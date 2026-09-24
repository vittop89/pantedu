<?php

/**
 * Porta nella biblioteca dell'istanza i modelli TikZ versionati in
 * storage/templates/tikz/ (ADR-050). Lo lancia il rilascio, passo 8-quater di
 * tools/webhook/deploy-container.sh, dentro il container nuovo come www-data:
 * la stessa identità del pannello admin, che scrive lo stesso file.
 *
 * Crea i modelli che mancano, sostituisce le versioni che il manifesto
 * dichiara superate, non tocca quelli cambiati dal pannello (li elenca).
 * Le regole stanno in App\Services\Tikz\ModelliTikzVersionati.
 *
 * Uso:
 *   php tools/tikz/sincronizza_modelli.php            # a secco: dice che cosa farebbe
 *   php tools/tikz/sincronizza_modelli.php --apply
 *   git show HEAD:storage/templates/tikz/<file>.tex | php tools/tikz/sincronizza_modelli.php --impronta
 *       # l'impronta della versione di prima, da mettere in «sostituisce»
 *
 * Esce 0 anche con modelli «modificati» (non sono un guasto: sono una scelta
 * dell'amministratore, o una versione vecchia da dichiarare in `sostituisce`),
 * 1 se il manifesto o la biblioteca non si leggono o una scrittura fallisce.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Services\Tikz\ModelliTikzVersionati;
use App\Services\TikzElementsService;

if (\in_array('--impronta', $argv, true)) {
    echo ModelliTikzVersionati::impronta((string) stream_get_contents(STDIN)), "\n";
    exit(0);
}

$applica = \in_array('--apply', $argv, true);
$modelli = new ModelliTikzVersionati(
    new TikzElementsService(),
    \dirname(__DIR__, 2) . '/' . ModelliTikzVersionati::CARTELLA_REL,
);

try {
    $righe = $applica ? $modelli->applica() : $modelli->piano();
} catch (\Throwable $e) {
    fwrite(STDERR, 'modelli TikZ: ' . $e->getMessage() . "\n");
    exit(1);
}

$conta = [];
$parole = [
    ModelliTikzVersionati::CREA       => $applica ? 'creato' : 'da creare',
    ModelliTikzVersionati::AGGIORNA   => $applica ? 'aggiornato' : 'da aggiornare',
    ModelliTikzVersionati::ALLINEATO  => 'già allineato',
    ModelliTikzVersionati::MODIFICATO => 'cambiato dal pannello: non lo tocco',
];
foreach ($righe as $r) {
    $conta[$r['azione']] = ($conta[$r['azione']] ?? 0) + 1;
    printf("[%s] %s / %s (%s) — %s\n", strtoupper($r['azione']), $r['gruppo'], $r['etichetta'], $r['file'], $parole[$r['azione']]);
}
printf(
    "%s — creati: %d, aggiornati: %d, allineati: %d, cambiati dal pannello: %d\n",
    $applica ? 'APPLY' : 'A SECCO (per scrivere: --apply)',
    $conta[ModelliTikzVersionati::CREA] ?? 0,
    $conta[ModelliTikzVersionati::AGGIORNA] ?? 0,
    $conta[ModelliTikzVersionati::ALLINEATO] ?? 0,
    $conta[ModelliTikzVersionati::MODIFICATO] ?? 0,
);
exit(0);
