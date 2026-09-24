<?php

declare(strict_types=1);

/**
 * Toglie dalle mappe il layout e il body_pt d'esempio che il modale ✎
 * scriveva fino al 19/9/2026 (App\Services\Maps\PuliziaMetadatiMappe, dove
 * sta la regola: solo le mappe il cui body_pt è esattamente il seme).
 *
 * Senza --applica non scrive niente: dice quali mappe pulirebbe e quali lascia
 * e perché. Non stampa i metadati, solo i nomi delle chiavi.
 *
 *   php tools/maps/pulisci_metadati_mappe.php                         # prova a secco
 *   php tools/maps/pulisci_metadati_mappe.php --applica               # scrive
 *   php tools/maps/pulisci_metadati_mappe.php --docente=N --applica   # un docente solo
 *
 * In produzione si lancia come l'utente dell'applicazione, con systemd-run
 * (legge la configurazione): docs/ops. Prima la prova a secco, e una copia
 * delle righe delle mappe.
 *
 * Uscita: 0 se è andato tutto come detto, 1 se qualche mappa è cambiata fra
 * la lettura e la scrittura (non toccata: si rilancia), 2 per un argomento
 * sconosciuto o fuori dalla riga di comando.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "cli_only";
    exit(2);
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Services\Maps\PuliziaMetadatiMappe;

$applica = false;
$docente = null;
foreach (array_slice($argv, 1) as $argomento) {
    if ($argomento === '--applica') {
        $applica = true;
    } elseif (preg_match('/^--docente=(\d+)$/', $argomento, $m) === 1) {
        $docente = (int)$m[1];
    } else {
        fwrite(STDERR, "Uso: php tools/maps/pulisci_metadati_mappe.php [--docente=N] [--applica]\n");
        exit(2);
    }
}
if (!Config::get('database.enabled')) {
    fwrite(STDERR, "DB_ENABLED=false — niente da fare.\n");
    exit(1);
}

$esito = (new PuliziaMetadatiMappe(Database::connection()))->esegui($applica, $docente);

echo '[pulisci_metadati_mappe] ' . ($applica ? 'APPLICO' : 'PROVA A SECCO (niente si scrive; --applica per scrivere)')
    . ($docente !== null ? " — docente $docente" : '') . PHP_EOL;
echo 'Mappe con il body_pt d\'esempio: ' . count($esito['da_pulire']) . PHP_EOL;
foreach ($esito['da_pulire'] as $m) {
    $stato = !$applica ? 'da pulire'
        : (in_array($m['id'], $esito['pulite'], true) ? 'pulita' : 'NON pulita: cambiata nel frattempo');
    printf(
        "  mappa %d (docente %d): %s — tolte %s; restano %s\n",
        $m['id'],
        $m['teacher_id'],
        $stato,
        implode(', ', $m['tolte']),
        $m['restano'] === [] ? '(nessuna chiave)' : implode(', ', $m['restano'])
    );
}
$elenco = static fn(array $ids): string => $ids === [] ? 'nessuna' : implode(', ', $ids);
echo 'Lasciate stare, con un body_pt diverso dal seme: ' . $elenco($esito['corpo_diverso']) . PHP_EOL;
echo 'Lasciate stare, con il solo layout: ' . $elenco($esito['solo_layout']) . PHP_EOL;
echo 'Lasciate stare, con il corpo cifrato: ' . $elenco($esito['corpo_cifrato']) . PHP_EOL;
if ($applica) {
    echo 'Pulite: ' . count($esito['pulite']) . '; cambiate nel frattempo: '
        . $elenco($esito['cambiate_nel_frattempo']) . PHP_EOL;
}
exit($esito['cambiate_nel_frattempo'] === [] ? 0 : 1);
