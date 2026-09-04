<?php
/**
 * Phase 25.K.2 — Export waf_logs blocked entries to file for fail2ban.
 *
 * fail2ban necessita di un LOG FILE di input. I nostri waf_logs vanno
 * in MySQL. Questo script (cron 1-min via systemd timer) esporta gli
 * eventi blocked_* a /var/log/pantedu-waf-blocked.log in formato
 * grep-friendly compatibile con fail2ban filter.
 *
 * State: ultimo id processato in /var/lib/pantedu/waf-last-id.txt
 * Idempotente: chunked 1000 row/run, NO duplicati.
 *
 * Format output:
 *   2026-05-19 14:23:01 ip=1.2.3.4 country=PL outcome=blocked_geo uri=/login ua="Mozilla/..."
 *
 * Usage:
 *   php tools/waf/export_blocked_to_log.php
 *
 * Output file rotation: cron logrotate (vedi tools/dev/hardening/02-fail2ban-waf.sh).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "ERROR: CLI only.\n");
    exit(1);
}

$logFile    = '/var/log/pantedu-waf-blocked.log';
$stateDir   = '/var/lib/pantedu';
$stateFile  = $stateDir . '/waf-last-id.txt';

if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0755, true);
}

$lastId = is_file($stateFile) ? (int)trim((string)file_get_contents($stateFile)) : 0;

try {
    $pdo = \App\Core\Database::connection();
    $stmt = $pdo->prepare(
        'SELECT id, ts, ip, country, outcome, request_uri, user_agent
         FROM waf_logs
         WHERE id > ? AND outcome LIKE "blocked_%"
         ORDER BY id ASC
         LIMIT 1000'
    );
    $stmt->execute([$lastId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($rows)) {
        exit(0);
    }

    $fh = @fopen($logFile, 'ab');
    if ($fh === false) {
        fwrite(STDERR, "ERROR: cannot open $logFile\n");
        exit(2);
    }

    $maxId = $lastId;
    foreach ($rows as $r) {
        // Format compat fail2ban: timestamp + key=value
        $line = sprintf(
            "%s ip=%s country=%s outcome=%s uri=%s ua=\"%s\"\n",
            (string)$r['ts'],
            (string)$r['ip'],
            (string)($r['country'] ?? '-'),
            (string)$r['outcome'],
            substr((string)$r['request_uri'], 0, 200),
            substr(str_replace('"', "'", (string)($r['user_agent'] ?? '-')), 0, 100)
        );
        fwrite($fh, $line);
        $maxId = max($maxId, (int)$r['id']);
    }
    fclose($fh);

    // Aggiorna state
    @file_put_contents($stateFile, (string)$maxId);
    @chmod($stateFile, 0644);

    fwrite(STDOUT, sprintf("Exported %d rows up to id=%d\n", count($rows), $maxId));
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    exit(3);
}
