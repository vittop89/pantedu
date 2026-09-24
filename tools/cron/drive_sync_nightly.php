<?php
/**
 * Phase G5 — Cron notturno sync Drive.
 *
 * Itera sui docenti con il collegamento a Drive attivo e
 * `last_sync_at < NOW() - INTERVAL ? HOURS` (default 24h, configurabile via
 * ENV `DRIVE_CRON_SYNC_OLDER_THAN_H`). Per ognuno chiama
 * `MapSyncService::syncAllForTeacher` con limite per teacher
 * (default 200 file/run via Config drive.limits.sync_per_run_max_files),
 * solo per le mappe nuove o cambiate dall'ultima sincronizzazione.
 * I collegamenti da ricollegare non si provano (ADR-038).
 *
 * Gira come unità systemd: tools/systemd/pantedu-drive-sync.service e .timer,
 * con OnFailure che manda l'avviso.
 *
 * Output:
 *   Una riga per docente con il resoconto (count/ok/skip/error) e una di
 *   totale. Su stderr gli errori: le credenziali che mancano, i motivi degli
 *   errori per docente, le eccezioni.
 *
 * Uscita (App\Services\Drive\SincronizzazioneNotturna, che ha i casi uno per uno):
 *   0  Drive spento sull'installazione, niente da sincronizzare, tutto
 *      riuscito, o solo docenti da ricollegare (lo vedono nel cruscotto)
 *   1  Drive acceso senza credenziali, un errore, un'eccezione, il giro
 *      interrotto dal freno sul tempo, o tutti i collegamenti provati
 *      rifiutati insieme (almeno due)
 *   2  non da riga di comando
 *   Fino al 14/9/2026 usciva sempre con zero, anche con tutte le mappe in errore.
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
use App\Repositories\DriveOAuthRepository;
use App\Services\Drive\MapSyncService;
use App\Services\Drive\SincronizzazioneNotturna;
use App\Services\Drive\StatoDiDrive;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — skip\n");
    exit(1);
}

set_time_limit(0);

$olderThanH = (int)($_ENV['DRIVE_CRON_SYNC_OLDER_THAN_H'] ?? 24);
$drive = Config::get('drive', []);
$drive = is_array($drive) ? $drive : [];
$collegamenti = new DriveOAuthRepository();
$perStato = $collegamenti->collegamentiPerStato();

// Con Drive spento o guasto non si cerca nessuno: il giro dice perché, ed esce.
$teacherIds = StatoDiDrive::dellInstallazione($drive) === StatoDiDrive::ACCESO
    ? $collegamenti->docentiDaSincronizzare($olderThanH)
    : [];

echo sprintf(
    "[drive_sync_nightly] %s started — %d teacher(s) to sync (older than %dh), %d da ricollegare\n",
    date('Y-m-d H:i:s'),
    count($teacherIds),
    $olderThanH,
    $perStato['da_ricollegare']
);

$svc = null;
$giro = new SincronizzazioneNotturna(
    sincronizza: static function (int $tid) use (&$svc): array {
        $svc ??= new MapSyncService();
        // Solo le mappe nuove o cambiate dall'ultima sincronizzazione (ADR-038).
        // Fino al 15/9/2026 il giro ricaricava tutte le mappe ogni notte: 130
        // mappe, 641 s per un docente solo, oltre il freno di 300 s che con più
        // docenti avrebbe fermato il giro dopo il primo.
        return $svc->syncAllForTeacher($tid, null, true);
    },
    scrivi: static function (string $riga): void {
        echo "[drive_sync_nightly] {$riga}\n";
    },
    segnala: static function (string $riga): void {
        fwrite(STDERR, "[drive_sync_nightly] {$riga}\n");
    },
);

exit($giro->esegui(
    $drive,
    $teacherIds,
    $perStato['attivo'] + $perStato['da_ricollegare'],
    (int)Config::get('drive.limits.sync_per_teacher_timeout_s', 300),
));
