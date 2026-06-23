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
 *   - privileged_access_log  10 anni (legal hold per Art. 6(1)(c))
 *   - crypto_access_log      10 anni (forensic post-breach)
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
    'content_action_log'    => ['days' => 7 * 365,  'ts_col' => 'occurred_at'],
    'privileged_access_log' => ['days' => 10 * 365, 'ts_col' => 'created_at'],
    'crypto_access_log'     => ['days' => 10 * 365, 'ts_col' => 'accessed_at'],
    'waf_logs'              => ['days' => 90,       'ts_col' => 'ts'],
];

$mode = $apply ? '🔥 APPLY' : '🧪 DRY-RUN';
echo "═══════════════════════════════════════════════════════════════\n";
echo "  AUDIT LOG PURGE — $mode\n";
echo "  " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$pdo = Database::connection();
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
