<?php

/**
 * GitHub webhook → audit log + trigger flag file for systemd auto-deploy.
 *
 * Phase 25.R.21 — Opzione 4 (webhook + systemd Path unit).
 *
 * Storia:
 *   - Originale: webhook eseguiva `sudo deploy.sh` da www-data. Richiedeva
 *     NOPASSWD su www-data, incompatibile con sandbox PHP-FPM
 *     (NoNewPrivileges + ProtectSystem=strict).
 *   - Phase 25.N (Opzione E): solo audit log, deploy manuale via SSH.
 *   - Phase 25.R.21 (Opzione 4 — corrente): PHP non chiama sudo né exec.
 *     Scrive un flag file. Un systemd `Path` unit (root) lo watcha e
 *     lancia il deploy script. PHP sandbox INTATTA, privilege separation
 *     a 3 stadi.
 *
 * Flow:
 *   1. POST da GitHub con X-Hub-Signature-256 (HMAC sha256 del body).
 *   2. Verifica HMAC con secret da /etc/pantedu/webhook.env (root only).
 *   3. Filtra event=push + ref=refs/heads/master_vps.
 *   4. Replay protection: dedupe via X-GitHub-Delivery (UUID unico evento).
 *   5. Scrive /var/lib/pantedu-deploy/trigger con il delivery UUID +
 *      commit SHA + timestamp. Atomico (rename).
 *   6. Systemd Path unit (pantedu-deploy.path) rileva modify e lancia
 *      pantedu-deploy.service che esegue /usr/local/bin/pantedu-deploy.sh
 *      come root.
 *
 * Sicurezza:
 *   - PHP-FPM sandbox intatto (ProtectSystem=strict + NoNewPrivileges)
 *   - www-data può scrivere SOLO in /var/lib/pantedu-deploy/ (dir
 *     dedicated, mode 0750, owner www-data:pantedu-deploy)
 *   - Replay protection: stesso UUID processato 2 volte = secondo trigger
 *     ignorato dal systemd service (verifica last-uuid file)
 *   - HMAC: stesso secret /etc/pantedu/webhook.env (root-only, già
 *     esistente, riusato senza modifica)
 *
 * Standalone: no Composer autoload, no app bootstrap. Resta funzionale
 * anche se la codebase sotto è temporaneamente rotta.
 *
 * Risposte HTTP:
 *   202 → push valido, trigger scritto, deploy in coda systemd
 *   200 → ignored (ping, ref diverso, event non push)
 *   401 → HMAC invalid o assente
 *   405 → method != POST
 *   500 → config mancante o filesystem error
 */

declare(strict_types=1);

const SECRET_FILE  = '/etc/pantedu/webhook.env';
const LOG_FILE     = '/var/log/pantedu-deploy.log';
const TRIGGER_DIR  = '/var/lib/pantedu-deploy';
const TRIGGER_FILE = '/var/lib/pantedu-deploy/trigger';
const TARGET_REF   = 'refs/heads/main';

header('Content-Type: application/json');

$logLine = function (string $level, string $msg): void {
    $line = sprintf("[%s] [webhook:%s] %s\n", date('Y-m-d H:i:s'), $level, $msg);
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    return;
}

$secret = '';
if (is_readable(SECRET_FILE)) {
    foreach (file(SECRET_FILE, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^GITHUB_WEBHOOK_SECRET=(.+)$/', $line, $m)) {
            $secret = trim($m[1], "\"' ");
            break;
        }
    }
}
if ($secret === '') {
    http_response_code(500);
    $logLine('error', 'secret missing');
    echo json_encode(['error' => 'config_missing']);
    return;
}

$body = file_get_contents('php://input') ?: '';
$sigHeader = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$expected  = 'sha256=' . hash_hmac('sha256', $body, $secret);

if (!hash_equals($expected, $sigHeader)) {
    http_response_code(401);
    $logLine('warn', 'hmac mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    echo json_encode(['error' => 'invalid_signature']);
    return;
}

$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event === 'ping') {
    $logLine('info', 'ping received');
    echo json_encode(['ok' => true, 'pong' => true]);
    return;
}
if ($event !== 'push') {
    $logLine('info', 'event ignored: ' . $event);
    echo json_encode(['ok' => true, 'ignored' => $event]);
    return;
}

$payload = json_decode($body, true);
$ref = is_array($payload) ? ($payload['ref'] ?? '') : '';
if ($ref !== TARGET_REF) {
    $logLine('info', 'ref ignored: ' . $ref);
    echo json_encode(['ok' => true, 'ignored_ref' => $ref]);
    return;
}

$delivery = $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? '';
// Sanitize: solo formato UUID GitHub (hex + dashes), refuse anything else
// per evitare path traversal / shell injection nel trigger file content.
if (!preg_match('/^[a-f0-9-]{1,64}$/i', $delivery)) {
    $delivery = 'unknown-' . bin2hex(random_bytes(8));
}
$pusher = (string)($payload['pusher']['name'] ?? '?');
$head   = substr((string)($payload['after'] ?? ''), 0, 40);
$ts     = date('c');

// Atomic write: scrivi su tempfile + rename. Garantisce systemd Path unit
// non legge un file half-written.
if (!is_dir(TRIGGER_DIR)) {
    $logLine('error', 'TRIGGER_DIR missing: ' . TRIGGER_DIR);
    http_response_code(500);
    echo json_encode(['error' => 'trigger_dir_missing']);
    return;
}

// Rate-limit: max 1 trigger ogni 30s. Mitiga DoS leggero via PHP RCE
// (spam deploy con UUID diversi). I push legittimi GitHub a < 30s uno
// dall'altro sono rari; il deploy successivo deploierà comunque tutto
// (git reset --hard pulla HEAD corrente).
$minIntervalSec = 30;
if (is_file(TRIGGER_FILE)) {
    $age = time() - (int)@filemtime(TRIGGER_FILE);
    if ($age >= 0 && $age < $minIntervalSec) {
        $logLine('info', "rate-limited: last trigger {$age}s ago (<{$minIntervalSec}s) — skipped delivery={$delivery}");
        http_response_code(202);
        echo json_encode([
            'ok'          => true,
            'rate_limited' => true,
            'last_trigger_age_sec' => $age,
            'note'        => "trigger skipped (within {$minIntervalSec}s rate limit); next push will deploy",
        ]);
        return;
    }
}
$content = json_encode([
    'delivery' => $delivery,
    'head'     => $head,
    'ref'      => $ref,
    'pusher'   => $pusher,
    'ts'       => $ts,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

$tmp = TRIGGER_FILE . '.tmp.' . bin2hex(random_bytes(4));
if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
    $logLine('error', 'trigger tmp write failed: ' . $tmp);
    http_response_code(500);
    echo json_encode(['error' => 'trigger_write_failed']);
    return;
}
@chmod($tmp, 0640);
if (!@rename($tmp, TRIGGER_FILE)) {
    @unlink($tmp);
    $logLine('error', 'trigger rename failed: ' . $tmp . ' → ' . TRIGGER_FILE);
    http_response_code(500);
    echo json_encode(['error' => 'trigger_rename_failed']);
    return;
}

$logLine('info', "trigger written: delivery={$delivery} head=" . substr($head, 0, 8) . " pusher={$pusher}");

http_response_code(202);
echo json_encode([
    'ok'       => true,
    'head'     => substr($head, 0, 8),
    'delivery' => $delivery,
    'note'     => 'trigger written; systemd Path unit will pick up within ~1s',
]);
