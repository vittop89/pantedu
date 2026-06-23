<?php
/**
 * Phase G5 — Cron notturno sync Drive.
 *
 * Itera su tutti i docenti che hanno `teacher_drive_oauth` connesso e
 * `last_sync_at < NOW() - INTERVAL ? HOURS` (default 24h, configurabile via
 * ENV `DRIVE_CRON_SYNC_OLDER_THAN_H`). Per ognuno chiama
 * `MapSyncService::syncAllForTeacher` con limite per teacher
 * (default 200 file/run via Config drive.limits.sync_per_run_max_files).
 *
 * Schedule consigliato: una volta a notte (es. 03:00 ora locale), al di
 * fuori delle finestre di pubblicazione mappe ad alto traffico.
 *
 * Esempio crontab Linux:
 *   0 3 * * * cd /var/www/pantedu && /usr/bin/php tools/cron/drive_sync_nightly.php >> storage/logs/drive_sync.log 2>&1
 *
 * Esempio Aruba (cPanel cron jobs):
 *   /usr/bin/php /home/user/public_html/tools/cron/drive_sync_nightly.php
 *
 * Output:
 *   Stampa riga per teacher con report (count/ok/skip/error). Anche su
 *   storage/logs/drive_sync.log se redirection attiva. Errori a stderr.
 *
 * Limiti:
 *   - PHP CLI tipicamente senza time limit (fallback `set_time_limit(0)`).
 *   - Drive API quota: 1k req/100s/user. Backoff lasciato al
 *     google/apiclient lib (retry transparent).
 *
 * Sicurezza:
 *   - Run ONLY come CLI (no exposure web). Verifica `PHP_SAPI === 'cli'`.
 *   - KMS_MASTER_KEY in env (no plaintext envelope key in cron output).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cli_only";
    exit(2);
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Drive\MapSyncService;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — skip\n");
    exit(1);
}

set_time_limit(0);
$startedAt = time();

$olderThanH = (int)($_ENV['DRIVE_CRON_SYNC_OLDER_THAN_H'] ?? 24);
$pdo = Database::connection();

$stmt = $pdo->prepare(
    'SELECT teacher_id FROM teacher_drive_oauth
     WHERE last_sync_at IS NULL OR last_sync_at < (NOW() - INTERVAL ? HOUR)
     ORDER BY last_sync_at IS NULL DESC, last_sync_at ASC
     LIMIT 100'
);
$stmt->execute([$olderThanH]);
$teacherIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

echo sprintf(
    "[drive_sync_nightly] %s started — %d teacher(s) to sync (older than %dh)\n",
    date('Y-m-d H:i:s'),
    count($teacherIds),
    $olderThanH
);

$svc = new MapSyncService();
$totalOk = 0;
$totalErr = 0;
$totalSkip = 0;

foreach ($teacherIds as $tid) {
    $teacherStart = time();
    $teacherTimeout = (int)Config::get('drive.limits.sync_per_teacher_timeout_s', 300);

    try {
        $report = $svc->syncAllForTeacher($tid);
    } catch (\Throwable $e) {
        fwrite(STDERR, sprintf(
            "[drive_sync_nightly] teacher_id=%d EXCEPTION: %s\n",
            $tid,
            $e->getMessage()
        ));
        $totalErr++;
        continue;
    }

    $elapsed = time() - $teacherStart;
    echo sprintf(
        "[drive_sync_nightly] teacher_id=%d count=%d ok=%d skip=%d error=%d (%ds)\n",
        $tid,
        $report['count'],
        $report['ok'],
        $report['skip'],
        $report['error'],
        $elapsed
    );
    $totalOk   += $report['ok'];
    $totalSkip += $report['skip'];
    $totalErr  += $report['error'];

    // Soft circuit breaker: se un teacher ha richiesto piu' del timeout
    // configurato, sospetto rate-limit Drive — fermati per non accumulare
    // 429 cascading.
    if ($elapsed > $teacherTimeout) {
        fwrite(STDERR, sprintf(
            "[drive_sync_nightly] teacher_id=%d exceeded timeout (%ds > %ds) — aborting batch\n",
            $tid,
            $elapsed,
            $teacherTimeout
        ));
        break;
    }
}

echo sprintf(
    "[drive_sync_nightly] %s done — total ok=%d skip=%d error=%d (elapsed=%ds)\n",
    date('Y-m-d H:i:s'),
    $totalOk,
    $totalSkip,
    $totalErr,
    time() - $startedAt
);

exit(0);
