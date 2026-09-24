<?php

declare(strict_types=1);

/**
 * Importa il drawio delle mappe che hanno solo il link pubblico di Drive
 * (App\Services\Maps\MappaDaLinkDrive, dove sta la regola).
 *
 * Servono le mappe create con «🔗 Link esterno» prima del 15/9/2026: la pagina
 * di studio le mostrava come «orphan». Da allora il modale le importa da sé.
 *
 * Senza --apply scarica e dice che cosa farebbe; non stampa i link.
 *
 *   php tools/maps/importa_mappa_da_link.php --id=1017
 *   php tools/maps/importa_mappa_da_link.php --id=1017 --apply
 *
 * In produzione va lanciato con l'utente dell'applicazione, non da una sessione
 * di login (legge la configurazione): docs/ops.
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Maps\MapBlobStore;
use App\Services\Maps\MappaDaLinkDrive;

$ids = [];
$applica = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $applica = true;
    } elseif (preg_match('/^--id=(\d+)$/', $arg, $m) === 1) {
        $ids[] = (int)$m[1];
    } else {
        fwrite(STDERR, "Uso: php tools/maps/importa_mappa_da_link.php --id=N [--id=M] [--apply]\n");
        exit(2);
    }
}
if ($ids === []) {
    fwrite(STDERR, "Nessuna mappa: --id=N\n");
    exit(2);
}

$pdo = Database::connection();
$servizio = new MappaDaLinkDrive();
$blob = new MapBlobStore();
$errori = 0;
foreach ($ids as $id) {
    $esito = $servizio->importaNellaMappa($pdo, $blob, $id, $applica);
    printf("mappa %d: %s%s%s\n", $id, $esito['esito'],
        isset($esito['byte']) ? ' (' . $esito['byte'] . ' byte)' : '',
        isset($esito['motivo']) ? ' — ' . $esito['motivo'] : '');
    if ($esito['esito'] === 'errore' || $esito['esito'] === 'non_trovata') {
        $errori++;
    }
}
echo $applica ? "Fatto.\n" : "Prova a secco: niente è stato scritto. Per importare: --apply\n";
exit($errori > 0 ? 1 : 0);
