<?php
/**
 * Recupera da Drive le mappe che hanno la riga ma non il file cifrato
 * (App\Services\Drive\RecuperoMappeDaDrive, dove sta la regola).
 *
 * Prima di lanciarlo, il docente dà il consenso di migrazione:
 * /teacher/drive/connect-migration (aggiunge drive.readonly). Senza, il comando
 * lo dice e salta il docente. Dopo il recupero, per tornare al solo drive.file:
 * revocare l'app da https://myaccount.google.com/permissions e ricollegarsi dal
 * cruscotto (ADR-038, «Le mappe senza file»).
 *
 * Uso:
 *   php tools/migrations/recupera_mappe_da_drive.php                  # prova: scarica, non scrive
 *   php tools/migrations/recupera_mappe_da_drive.php --teacher=N      # un docente solo
 *   php tools/migrations/recupera_mappe_da_drive.php --teacher=N --apply
 *
 * In produzione si lancia da systemd, come l'utente dell'applicazione: così la
 * lettura di .env.local non conta come lettura interattiva (docs/ops/diagnostica.md).
 *
 * Uscita: 0 se niente è andato in errore (anche con mappe non trovate su Drive,
 * che si elencano), 1 se almeno un recupero è fallito, 2 fuori da riga di comando.
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
use App\Repositories\DriveOAuthRepository;
use App\Services\Drive\DriveClient;
use App\Services\Drive\RecuperoMappeDaDrive;
use App\Services\Drive\StatoDiDrive;
use App\Services\Maps\MapBlobStore;

if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — niente da fare.\n");
    exit(1);
}
set_time_limit(0);

$istanza = StatoDiDrive::attuale();
if ($istanza !== StatoDiDrive::ACCESO) {
    fwrite(STDERR, "[recupera_mappe] Drive è $istanza su questa installazione (ADR-038): non si può scaricare niente.\n");
    exit(1);
}

$applica = in_array('--apply', $argv, true);
$docente = null;
foreach ($argv as $argomento) {
    if (preg_match('/^--teacher=(\d+)$/', $argomento, $m)) {
        $docente = (int)$m[1];
    }
}

$client = new DriveClient();
$collegamenti = new DriveOAuthRepository();
/** @var array<int, \Google\Service\Drive> $servizi */
$servizi = [];
$recupero = new RecuperoMappeDaDrive(
    Database::connection(),
    new MapBlobStore(),
    static function (int $tid, string $idFile) use ($client, &$servizi): string {
        $servizi[$tid] ??= $client->getDriveFor($tid);
        $risposta = $servizi[$tid]->files->get($idFile, ['alt' => 'media']);
        return (string)$risposta->getBody();
    },
);

echo '[recupera_mappe] ' . ($applica ? 'APPLICO' : 'PROVA (niente si scrive; --apply per scrivere)')
    . ($docente !== null ? " — docente $docente" : '') . PHP_EOL;

$candidate = $recupero->candidate($docente);
echo '[recupera_mappe] mappe con la riga e senza il file: ' . count($candidate) . PHP_EOL;

$conteggi = [];
$saltati = [];
foreach ($candidate as $mappa) {
    $tid = $mappa['teacher_id'];
    if (!isset($saltati[$tid])) {
        $meta = $collegamenti->getMetadata($tid);
        $saltati[$tid] = match (true) {
            $meta === null => 'Drive non collegato',
            !str_contains($meta['scope'], 'drive.readonly') => 'manca il consenso di migrazione (drive.readonly): /teacher/drive/connect-migration',
            default => '',
        };
        if ($saltati[$tid] !== '') {
            echo "  docente $tid: salto, {$saltati[$tid]}" . PHP_EOL;
        }
    }
    if ($saltati[$tid] !== '') {
        $conteggi['saltata'] = ($conteggi['saltata'] ?? 0) + 1;
        continue;
    }

    $esito = $recupero->recupera($mappa, $applica);
    $conteggi[$esito['esito']] = ($conteggi[$esito['esito']] ?? 0) + 1;
    echo sprintf(
        '  id=%d «%s»: %s%s%s',
        $mappa['id'],
        $mappa['title'],
        $esito['esito'],
        isset($esito['byte']) ? " ({$esito['byte']} byte" . (isset($esito['tipo']) ? ", {$esito['tipo']}" : '') . ')' : '',
        isset($esito['motivo']) ? ' — ' . $esito['motivo'] : '',
    ) . PHP_EOL;
}

ksort($conteggi);
echo '[recupera_mappe] riepilogo: '
    . ($conteggi === [] ? 'niente' : implode(', ', array_map(static fn($k, $v) => "$k=$v", array_keys($conteggi), $conteggi)))
    . PHP_EOL;

exit(($conteggi[RecuperoMappeDaDrive::ERRORE] ?? 0) > 0 ? 1 : 0);
