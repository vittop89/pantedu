<?php

declare(strict_types=1);

/**
 * Phase 25.R.25 — Purge log table per retention policy.
 *
 * GDPR Art. 5(1)(e): "data minimization" + Art. 32 best practice = audit log
 * retention 7 anni per accountability. Oltre questo: cancellazione automatica.
 *
 * USO CLI:
 *   php tools/audit/purge_old_logs.php             # DRY-RUN (default)
 *   php tools/audit/purge_old_logs.php --apply     # esegue DELETE reali
 *   php tools/audit/purge_old_logs.php --years=5   # retention custom
 *
 * CRON consigliato (1 volta al mese):
 *   0 3 1 * * php /var/www/pantedu/tools/audit/purge_old_logs.php --apply
 *
 * RETENTION DEFAULT: 7 anni (GDPR best practice per audit trail).
 *
 * Tabelle gestite:
 *   - content_action_log     7 anni
 *   - privileged_access_log  5 anni (1825 giorni: e' quanto dichiarano
 *                            informativa, registro art. 30 e DPIA; qui c'erano
 *                            dieci anni, mai comunicati a nessuno — 2026-09-03)
 *   - crypto_access_log      5 anni (idem)
 *   - audit_activity_log     2 anni (operazioni di tutti i ruoli, minori inclusi)
 *   - waf_logs               90 giorni (traffic data, no PII rilevante)
 *   - teacher_recovery_audit 7 anni
 *
 * NON purgato (append-only legale obbligatorio):
 *   - crypto_custody_events (chain of custody KMS — MAI cancellare)
 *   - data_breach_register  (Art. 33 GDPR — MAI cancellare)
 *   - dpo_requests          (DSR storia 30+ anni standard)
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use Dotenv\Dotenv;

$basePath = dirname(__DIR__, 2);
if (is_file($basePath . '/.env')) {
    Dotenv::createImmutable($basePath)->safeLoad();
}
if (is_file($basePath . '/.env.local')) {
    Dotenv::createMutable($basePath, '.env.local')->safeLoad();
}
Config::load($basePath . '/app/Config');

// Parse args
$apply  = false;
$customYears = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') $apply = true;
    if (preg_match('/^--years=(\d+)$/', $arg, $m)) $customYears = (int)$m[1];
    if ($arg === '--help' || $arg === '-h') {
        echo "Usage: php purge_old_logs.php [--apply] [--years=N]\n";
        echo "  --apply        Execute DELETE (default: dry-run).\n";
        echo "  --years=N      Override default retention (default per table).\n";
        exit(0);
    }
}

// Phase 25.R.25 — Retention per tabella + nome colonna timestamp reale (schema dipendente)
$tables = [
    // 2026-09-04 — cinque anni come gli accessi privilegiati, non sette:
    // i sette non avevano una ragione scritta, e un registro senza ragione
    // e' esattamente cio' che l'art. 5(1)(e) vieta. Cinque e' il termine di
    // prescrizione degli abusi che questi registri servono a ricostruire.
    'content_action_log'    => ['days' => 1825, 'ts_col' => 'occurred_at'],
    // 1825 giorni e non dieci anni: i documenti consegnati promettono cinque
    // anni, e la conservazione piu' lunga la sapeva solo questo file.
    'privileged_access_log' => ['days' => 1825, 'ts_col' => 'created_at'],
    'crypto_access_log'     => ['days' => 1825, 'ts_col' => 'accessed_at'],
    // 30 giorni (2026-09-04, erano 90): e' l'unico registro con l'IP in
    // chiaro che tocca anche le sessioni degli studenti con credenziale di
    // classe; un mese basta a bloccare gli abusi ricorrenti.
    'waf_logs'              => ['days' => 30,       'ts_col' => 'ts'],
    // 2026-09-02 — le due tabelle che la descrizione qui sopra elencava ma
    // che il codice non toccava. Senza, sarebbero cresciute per sempre.
    //
    // audit_activity_log: due anni. Dentro ci sono anche le operazioni degli
    // studenti, minori compresi; le azioni che meritano dieci anni hanno gia'
    // le loro tabelle (privileged_access_log, crypto_access_log). Due anni
    // coprono l'anno scolastico in corso e il precedente, che e' l'orizzonte
    // entro cui a una scuola viene chiesto conto di qualcosa.
    'audit_activity_log'     => ['days' => 2 * 365, 'ts_col' => 'occurred_at'],
    'teacher_recovery_audit' => ['days' => 1825, 'ts_col' => 'created_at'],
    // 2026-09-04 — era dichiarato "permanente, mai cancellato": una
    // conservazione senza termine non e' ammessa dall'art. 5(1)(e). Dieci
    // anni dall'evento, la prescrizione ordinaria (art. 2946 c.c.): oltre,
    // nessuno puo' piu' contestare un consenso dato o revocato.
    'consent_audit'          => ['days' => 3650, 'ts_col' => 'accessed_at'],
];

$mode = $apply ? '🔥 APPLY' : '🧪 DRY-RUN';
echo "═══════════════════════════════════════════════════════════════\n";
echo "  AUDIT LOG PURGE — $mode\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Connessione di manutenzione: e' l'unica che conserva il permesso di
// cancellare dalle tabelle di audit. L'utente del sito non ce l'ha piu'
// (vedi Database::maintenanceConnection). Senza DB_MAINT_USER ricade
// sulla connessione ordinaria e nulla cambia.
$pdo = Database::maintenanceConnection();
$totalDeleted = 0;

foreach ($tables as $table => $config) {
    $daysDefault = $config['days'];
    $tsCol       = $config['ts_col'];
    $days = $customYears !== null ? ($customYears * 365) : $daysDefault;

    // Check table exists
    try {
        $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $check->execute([$table]);
        if ((int)$check->fetchColumn() === 0) {
            echo sprintf("  %-25s ⚠️  table missing — skip\n", $table);
            continue;
        }
    } catch (\Throwable $e) {
        echo sprintf("  %-25s ⚠️  check failed: %s\n", $table, $e->getMessage());
        continue;
    }

    // Count candidates
    try {
        $sqlCount = "SELECT COUNT(*) FROM $table WHERE $tsCol < NOW() - INTERVAL ? DAY";
        $stmt = $pdo->prepare($sqlCount);
        $stmt->execute([$days]);
        $count = (int)$stmt->fetchColumn();
    } catch (\Throwable $e) {
        echo sprintf("  %-25s ⚠️  count failed: %s\n", $table, $e->getMessage());
        continue;
    }

    if ($count === 0) {
        echo sprintf("  %-25s retention=%4dgg  candidates=0\n", $table, $days);
        continue;
    }

    if (!$apply) {
        echo sprintf("  %-25s retention=%4dgg  candidates=%6d  (DRY-RUN)\n", $table, $days, $count);
        continue;
    }

    // Execute DELETE
    try {
        $sqlDel = "DELETE FROM $table WHERE $tsCol < NOW() - INTERVAL ? DAY";
        $stmt = $pdo->prepare($sqlDel);
        $stmt->execute([$days]);
        $deleted = $stmt->rowCount();
        $totalDeleted += $deleted;
        echo sprintf("  %-25s retention=%4dgg  deleted=%6d  ✓\n", $table, $days, $deleted);
    } catch (\Throwable $e) {
        echo sprintf("  %-25s ⚠️  delete failed: %s\n", $table, $e->getMessage());
    }
}

echo "\n";
if ($apply) {
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "  TOTAL DELETED: $totalDeleted\n";
    echo "═══════════════════════════════════════════════════════════════\n";
} else {
    echo "ℹ️  DRY-RUN finito. Riesegui con --apply per cancellare.\n";
}
