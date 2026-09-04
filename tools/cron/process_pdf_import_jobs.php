<?php
/**
 * Phase PDF-Import — cron worker per l'estrazione asincrona delle sessioni.
 *
 * Pesca FIFO le sessioni in stato 'rasterized'/'retry' dalla tabella
 * `pdf_import_sessions` e ne completa l'estrazione pagina-per-pagina, poi
 * costruisce contracts.json (PdfImportSessionService::processBatch).
 *
 * Schedule consigliato: ogni minuto (lock atomic permette multi-worker).
 *
 *   * * * * * cd /var/www/pantedu && /usr/bin/php tools/cron/process_pdf_import_jobs.php >> storage/logs/pdf_import_jobs.log 2>&1
 *
 * Variabili ENV:
 *   PDF_IMPORT_JOBS_BATCH    sessioni per tick (default 3)
 *   PDF_IMPORT_JOBS_PURGE_D  giorni dopo cui purgare sessioni terminali (default 14)
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Services\PdfImport\Session\PdfImportSessionService;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, '[' . date('c') . "] DB_ENABLED=false — skip.\n");
    exit(0);
}
if (!Config::get('pdf_import.enabled', false)) {
    fwrite(STDERR, '[' . date('c') . "] PDF_IMPORT_ENABLED=false — skip.\n");
    exit(0);
}

$batchMax  = (int)(getenv('PDF_IMPORT_JOBS_BATCH') ?: 3);
// Default retention da config (pdf_import.retention_days, 7gg); env override.
$purgeDays = (int)(getenv('PDF_IMPORT_JOBS_PURGE_D')
    ?: (int)Config::get('pdf_import.retention_days', 7));

// Modalità "solo pulizia" (NIENTE token): salta l'estrazione in background,
// esegue solo la retention/auto-delete. Attiva con `--purge-only` o
// PDF_IMPORT_PURGE_ONLY=1. Pensata per chi NON vuole spesa token non presidiata:
// l'estrazione resta legata al poll della pagina (avanza solo a pagina aperta).
$purgeOnly = in_array('--purge-only', $argv ?? [], true)
    || (getenv('PDF_IMPORT_PURGE_ONLY') === '1');

$svc = new PdfImportSessionService();
$startedAt = microtime(true);

$results = [];
if (!$purgeOnly) {
    $results = $svc->processBatch($batchMax);
    foreach ($results as $r) {
        echo sprintf(
            "[%s] session_id=%d status=%s%s\n",
            date('c'),
            (int)$r['session_id'],
            (string)$r['status'],
            !empty($r['error']) ? ' error=' . substr((string)$r['error'], 0, 200) : '',
        );
    }
}

$purged = $svc->purgeOld($purgeDays);

echo '[summary] ' . json_encode([
    'tick_at'     => date('c'),
    'mode'        => $purgeOnly ? 'purge-only' : 'full',
    'batch_max'   => $purgeOnly ? 0 : $batchMax,
    'processed'   => count($results),
    'purged_old'  => $purged,
    'duration_ms' => (int)((microtime(true) - $startedAt) * 1000),
], JSON_UNESCAPED_UNICODE) . "\n";
exit(0);
