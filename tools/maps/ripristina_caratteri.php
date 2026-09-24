<?php

declare(strict_types=1);

/**
 * Rimette le lettere accentate diventate «??» nelle mappe
 * (App\Services\Maps\RipristinoCaratteriMappe, dove stanno le precauzioni;
 * le regole in RipristinoCaratteri; la storia in wiki/domains/mappe/
 * mappe-overview.md, «Caratteri persi»).
 *
 * Si lavora in tre tempi, e solo l'ultimo scrive.
 *
 *   1. Censimento, sul server, in sola lettura. Esce un JSON con le corse di
 *      «?» di ogni mappa e 24 byte di contesto per lato (mai il contenuto
 *      intero); sullo standard error solo i conteggi.
 *
 *        php tools/maps/ripristina_caratteri.php --censimento > censimento.json
 *        php tools/maps/ripristina_caratteri.php --censimento --docente=77 --id=143
 *
 *   2. In locale: tools/maps/prepara_ripristino_caratteri.php confronta il
 *      censimento con gli originali del Drive e scrive la patch e il CSV di
 *      revisione. Patch e CSV contengono frammenti di testo didattico: stanno
 *      fuori dal repository e si cancellano dopo l'uso.
 *
 *   3. Sul server, con la patch:
 *
 *        php tools/maps/ripristina_caratteri.php --patch=FILE             # prova a secco
 *        php tools/maps/ripristina_caratteri.php --patch=FILE --apply     # scrive
 *
 *      Filtri: --id=N (anche più volte), --docente=N. Una mappa modificata
 *      negli ultimi 15 minuti si salta (un editor può essere aperto); --forza
 *      la scrive lo stesso. Meglio lanciarlo a editor chiusi: un editor aperto
 *      su una mappa corretta riceve un 409 al salvataggio successivo.
 *
 *      Prima di ogni scrittura il file cifrato si copia com'è in
 *      <storage>/maps_enc_prima_utf8/{docente}/{ulid}-{data}.bin; se poi non
 *      si scrive (versione cambiata, mappa non trovata) la copia si toglie,
 *      così nella cartella ci sono solo scritture davvero avvenute. Per
 *      tornare indietro su una mappa si rimette la copia al posto del blob
 *      (<storage>/maps_enc/{docente}/{ulid}.bin) e si alza map_version di uno,
 *      così un editor aperto se ne accorge; la lunghezza non cambia, perché
 *      ogni sostituzione ha la lunghezza della corsa. Quale copia: la più
 *      recente di quell'ULID è il disegno di prima dell'ultima scrittura dello
 *      strumento; le più vecchie riportano più indietro, e con loro si perde
 *      quello che il docente ha fatto nel frattempo. Le copie si tengono 30
 *      giorni:
 *
 *        php tools/maps/ripristina_caratteri.php --pulisci-copie            # elenca le vecchie
 *        php tools/maps/ripristina_caratteri.php --pulisci-copie --apply    # le cancella
 *
 * In produzione si lancia come l'utente dell'applicazione, da systemd, come gli
 * altri strumenti (docs/ops/diagnostica.md): la lettura della configurazione
 * così non conta come lettura interattiva.
 *
 * Dopo l'applicazione, la misura: si rilancia --censimento, e le corse rimaste
 * devono essere solo quelle legittime e quelle che si è deciso di non toccare.
 *
 * Uscita: 0 se niente è andato in errore, 1 se una mappa è finita in errore o
 * in conflitto di versione, 2 per un uso sbagliato o fuori da riga di comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cli_only";
    exit(2);
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\RipristinoCaratteriMappe;

const USO = "Uso:\n"
    . "  php tools/maps/ripristina_caratteri.php --censimento [--docente=N] [--id=N ...]\n"
    . "  php tools/maps/ripristina_caratteri.php --patch=FILE [--apply] [--forza] [--docente=N] [--id=N ...]\n"
    . "  php tools/maps/ripristina_caratteri.php --pulisci-copie [--apply]\n";

$censimento = false;
$pulisci = false;
$applica = false;
$forza = false;
$patch = null;
$docente = null;
$ids = [];
foreach (array_slice($argv, 1) as $argomento) {
    if ($argomento === '--censimento') {
        $censimento = true;
    } elseif ($argomento === '--pulisci-copie') {
        $pulisci = true;
    } elseif ($argomento === '--apply') {
        $applica = true;
    } elseif ($argomento === '--forza') {
        $forza = true;
    } elseif (preg_match('/^--patch=(.+)$/', $argomento, $m) === 1) {
        $patch = $m[1];
    } elseif (preg_match('/^--docente=(\d+)$/', $argomento, $m) === 1) {
        $docente = (int)$m[1];
    } elseif (preg_match('/^--id=(\d+)$/', $argomento, $m) === 1) {
        $ids[] = (int)$m[1];
    } else {
        fwrite(STDERR, USO);
        exit(2);
    }
}
if ((int)$censimento + (int)$pulisci + (int)($patch !== null) !== 1) {
    fwrite(STDERR, USO);
    exit(2);
}
if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — niente da fare.\n");
    exit(1);
}
set_time_limit(0);
ini_set('memory_limit', '1G');

$storage = rtrim((string)Config::get('app.paths.storage'), '/');
$logs = rtrim((string)Config::get('app.paths.logs', $storage . '/logs'), '/');
$servizio = new RipristinoCaratteriMappe(
    Database::connection(),
    new MapBlobStore(),
    $storage . '/maps_enc_prima_utf8',
    $logs . '/ripristino-caratteri.tsv',
);

if ($pulisci) {
    $vecchie = $servizio->pulisciCopie($applica);
    foreach ($vecchie as $v) {
        echo ($applica ? 'cancellata ' : 'da cancellare ') . $v . PHP_EOL;
    }
    echo '[ripristina_caratteri] copie più vecchie di ' . RipristinoCaratteriMappe::GIORNI_DELLE_COPIE . ' giorni: '
        . count($vecchie) . ($applica ? ', cancellate' : ' (prova: --apply per cancellarle)') . PHP_EOL;
    exit(0);
}

if ($censimento) {
    $esito = $servizio->censimento($docente, $ids);
    $json = json_encode($esito, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        fwrite(STDERR, '[ripristina_caratteri] censimento non scrivibile in JSON: ' . json_last_error_msg() . PHP_EOL);
        exit(1);
    }
    echo $json . PHP_EOL;
    fwrite(STDERR, '[ripristina_caratteri] censimento: '
        . json_encode($esito['riepilogo'], JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
}

$testo = @file_get_contents((string)$patch);
$dati = $testo === false ? null : json_decode($testo, true);
if (!is_array($dati)) {
    fwrite(STDERR, "[ripristina_caratteri] patch non leggibile: $patch\n");
    exit(2);
}
try {
    $esiti = $servizio->esegui($dati, ['ids' => $ids, 'docente' => $docente, 'applica' => $applica, 'forza' => $forza]);
} catch (InvalidArgumentException $e) {
    fwrite(STDERR, '[ripristina_caratteri] patch non valida: ' . $e->getMessage() . PHP_EOL);
    exit(2);
}

/** Il frammento intorno alla sostituzione, su una riga: «…unit[??→à] di mis…». */
function frammento(array $s): string
{
    $pulisci = static fn(string $t): string => str_replace(["\r", "\n", "\t"], ['⏎', '⏎', ' '], $t);
    $sinistra = (string)($s['sinistra'] ?? '');
    $destra = (string)($s['destra'] ?? '');
    $sinistra = (string)preg_replace('/^[\x80-\xBF]+/', '', substr($sinistra, -14));
    $destra = substr($destra, 0, 14);
    while ($destra !== '' && preg_match('//u', $destra) !== 1) {
        $destra = substr($destra, 0, -1);
    }
    return '…' . $pulisci($sinistra) . '[' . $s['corsa'] . '→' . $pulisci((string)$s['testo']) . ']' . $pulisci($destra) . '…';
}

echo '[ripristina_caratteri] ' . ($applica ? 'APPLICO' : 'PROVA A SECCO (niente si scrive; --apply per scrivere)')
    . ($forza ? ', anche le mappe modificate da poco' : '') . PHP_EOL;
$conteggi = [];
$sostituzioni = 0;
$errori = 0;
foreach ($esiti as $e) {
    $conteggi[$e['esito']] = ($conteggi[$e['esito']] ?? 0) + 1;
    $applicate = $e['applicate'] ?? [];
    $conflitti = $e['conflitti'] ?? [];
    $fonti = [];
    foreach ($applicate as $a) {
        $fonti[$a['fonte'] ?? ''] = ($fonti[$a['fonte'] ?? ''] ?? 0) + 1;
    }
    ksort($fonti);
    printf(
        "mappa %d (docente %d%s): %s — %d sostituzioni%s, %d conflitti%s%s%s\n",
        $e['id'],
        $e['teacher_id'],
        isset($e['versione_prima']) ? ', v' . $e['versione_prima'] : '',
        $e['esito'],
        count($applicate),
        $fonti === [] ? '' : ' (' . implode(', ', array_map(static fn($k, $v) => "$k $v", array_keys($fonti), $fonti)) . ')',
        count($conflitti),
        isset($e['per_offset']) ? ($e['per_offset'] ? ', per offset' : ', per contesto (il blob è cambiato dal censimento)') : '',
        !empty($e['recente']) ? ', modificata negli ultimi ' . RipristinoCaratteriMappe::MINUTI_DI_RISPETTO . ' minuti' : '',
        isset($e['motivo']) ? ' — ' . $e['motivo'] : '',
    );
    foreach ($applicate as $a) {
        echo '    ' . frammento($a) . ' ' . ($a['fonte'] ?? '') . PHP_EOL;
    }
    foreach ($conflitti as $c) {
        echo '    NON APPLICATA (' . $c['motivo'] . '): ' . frammento($c['sostituzione']) . PHP_EOL;
    }
    if (($e['motivo'] ?? '') === 'rilettura_diversa') {
        // Un errore che però ha già scritto: dirlo qui, perché chi legge
        // «errore» pensa che non sia successo niente.
        echo '    ATTENZIONE: la mappa È stata scritta (v' . $e['versione_prima'] . ' → v' . $e['versione_dopo']
            . '), ma rileggendola si trova altro: qualcuno ha scritto subito dopo. L\'evento è in'
            . ' audit_activity_log e nel registro; la copia di prima è '
            . basename((string)($e['copia'] ?? '')) . PHP_EOL;
    }
    if (in_array($e['esito'], [RipristinoCaratteriMappe::ERRORE, RipristinoCaratteriMappe::CONFLITTO], true)) {
        $errori++;
    }
    if (in_array($e['esito'], [RipristinoCaratteriMappe::APPLICATA, RipristinoCaratteriMappe::DA_APPLICARE], true)) {
        $sostituzioni += count($applicate);
    }
}
ksort($conteggi);
echo '[ripristina_caratteri] riepilogo: '
    . ($conteggi === [] ? 'nessuna mappa' : implode(', ', array_map(static fn($k, $v) => "$k=$v", array_keys($conteggi), $conteggi)))
    . "; sostituzioni " . ($applica ? 'scritte' : 'da scrivere') . ": $sostituzioni" . PHP_EOL;
exit($errori > 0 ? 1 : 0);
