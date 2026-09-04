<?php
/**
 * G22.S5 — Cron worker per la queue async di compile PDF.
 *
 * Pesca FIFO N job pending dalla tabella `verifica_compile_jobs`,
 * invoca tex-compile-vps `/compile-bundle` (S4.B.3) o `/compile`
 * (legacy) per ogni job, attach del PDF su success.
 *
 * Schedule consigliato: ogni minuto (lock atomic permette multi-worker).
 *
 * Crontab Linux (hosting legacy shared):
 *   * * * * * cd /var/www/pantedu && /usr/bin/php tools/cron/process_compile_jobs.php >> storage/logs/compile_jobs.log 2>&1
 *
 * hosting legacy cPanel cron:
 *   /usr/bin/php /home/user/public_html/tools/cron/process_compile_jobs.php
 *
 * Variabili ENV:
 *   COMPILE_JOBS_BATCH    quanti job processare per tick (default 5,
 *                         allineato al rate limit nginx 20 req/min).
 *   COMPILE_JOBS_PURGE_D  giorni dopo cui purgare jobs done/failed
 *                         (default 7).
 *
 * Output: una riga per job processato + summary finale (json-friendly).
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Services\Verifica\VerificaCompileJobService;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "[" . date('c') . "] DB_ENABLED=false — skip.\n");
    exit(0);
}

$batchMax = (int)(getenv('COMPILE_JOBS_BATCH') ?: 5);
$purgeDays = (int)(getenv('COMPILE_JOBS_PURGE_D') ?: 7);

$svc = new VerificaCompileJobService();
$startedAt = microtime(true);
$results = $svc->processBatch($batchMax);

foreach ($results as $r) {
    $line = sprintf(
        "[%s] job_id=%d doc_id=%d status=%s duration_ms=%d%s",
        date('c'),
        (int)$r['job_id'], (int)$r['doc_id'], (string)$r['status'],
        (int)$r['duration_ms'],
        !empty($r['error']) ? ' error=' . substr((string)$r['error'], 0, 200) : '',
    );
    echo $line . "\n";
}

// Cleanup post-batch.
$purged = $svc->purgeOld($purgeDays);

$summary = [
    'tick_at'    => date('c'),
    'batch_max'  => $batchMax,
    'processed'  => count($results),
    'done'       => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'done')),
    'failed'     => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'failed')),
    'retry'      => count(array_filter($results, fn($r) => ($r['status'] ?? '') === 'retry')),
    'purged_old' => $purged,
    'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
];
echo "[summary] " . json_encode($summary, JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
