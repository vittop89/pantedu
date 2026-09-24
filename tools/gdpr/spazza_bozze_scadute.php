<?php

declare(strict_types=1);

/**
 * Avvisa i docenti e poi cancella le bozze di compilazione scadute (ADR-046).
 *
 * PERCHE' (2026-09-22)
 *   Una compilazione e' cio' che il docente scrive dentro un atto della
 *   scuola: relazione finale, scheda di recupero, piano annuale. Per natura
 *   puo' nominare uno studente. Restava sul server per sempre, e ogni giorno
 *   in piu' e' un giorno in cui quei dati possono uscire da una violazione che
 *   non e' ancora successa. L'art. 5(1)(c) non chiede di non tenerli: chiede
 *   di non tenerli piu' del necessario.
 *
 * LA REGOLA, in due righe
 *   - quindici giorni dall'ultima fra SCARICAMENTO e modifica;
 *   - e comunque il 31 agosto, per cio' che non si tocca dal 1 giugno.
 *   I numeri stanno in App\Services\Risdoc\ScadenzaDelleBozze, non qui: una
 *   soglia scritta in due posti diverge.
 *
 * USO
 *   php tools/gdpr/spazza_bozze_scadute.php             # prova: dice cosa farebbe
 *   php tools/gdpr/spazza_bozze_scadute.php --applica   # avvisa e cancella
 *   php tools/gdpr/spazza_bozze_scadute.php --json      # per un'altra macchina
 *
 * USCITA
 *   0  il giro e' andato (anche se non c'era niente da fare)
 *   1  almeno un errore: qualcosa non e' stato avvisato o non e' stato
 *      cancellato. Agganciato a systemd con OnFailure=pantedu-avviso@%n.service
 *      diventa una mail.
 *   2  non e' riuscito nemmeno a partire.
 *
 * COSA NON FA
 *   Non guarda dentro le compilazioni: sono cifrate con la chiave di ciascun
 *   docente e da qui non si leggono. Decide su due date e basta. Per lo stesso
 *   motivo l'avviso al docente non cita nulla del contenuto.
 *
 * DOVE GIRA
 *   Sull'host, come utente `pantedu`, da tools/systemd/pantedu-risdoc-scadenze.timer
 *   — come tutti gli altri lavori notturni. Le unita' NON le installa il
 *   rilascio: vanno messe a mano (docs/ops/).
 */

require __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Services\Risdoc\ScadenzaDelleBozze;
use App\Services\Risdoc\SpazzataDelleBozze;

// In produzione display_errors e' 0: senza questa rete un'eccezione uscirebbe
// 255 senza un messaggio ne' su stdout ne' su stderr, e l'avviso di systemd
// direbbe soltanto «fallita».
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, '[spazza_bozze_scadute] ' . $e::class . ': ' . $e->getMessage()
        . ' (' . $e->getFile() . ':' . $e->getLine() . ")\n");
    exit(2);
});

$applica = in_array('--applica', $argv, true);
$comeJson = in_array('--json', $argv, true);

if (!Database::isAvailable()) {
    fwrite(STDERR, "DB non disponibile.\n");
    exit(2);
}

$spazzata = new SpazzataDelleBozze();
$esito = $spazzata->gira($applica);

if ($comeJson) {
    echo json_encode($esito, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit($esito['errori'] === [] ? 0 : 1);
}

if (!$esito['pronta']) {
    fwrite(STDERR, "La migrazione 138 non e' applicata: la colonna exported_at non esiste.\n");
    fwrite(STDERR, "Niente e' stato guardato e niente e' stato cancellato.\n");
    exit(1);
}

printf("%s — %s\n", $applica ? 'APPLICA' : 'PROVA', $esito['adesso']);
printf("  regola: %d giorni dall'ultima fra scaricamento e modifica; rete il %d/%d per cio' che e' fermo dal %d/%d\n",
    ScadenzaDelleBozze::GRAZIA_GIORNI,
    ScadenzaDelleBozze::RETE_GIORNO,
    ScadenzaDelleBozze::RETE_MESE,
    ScadenzaDelleBozze::SOGLIA_GIORNO,
    ScadenzaDelleBozze::SOGLIA_MESE,
);

// «Zero cancellate» non e' un esito: si dice sempre quante se ne sono
// guardate, cosi' un giro che non trova niente si distingue da un giro che ha
// guardato nel posto sbagliato.
printf("\n  compilazioni guardate ......... %d\n", $esito['guardate']);
printf("  docenti da avvisare ........... %d\n", $esito['da_avvisare']);
foreach ($esito['avvisi'] as $come => $quanti) {
    printf("      %-18s %d\n", $come, $quanti);
}
printf("  scadute e mature .............. %d\n", $esito['da_cancellare']);
printf("  scadute ma in attesa dell'avviso %d\n", $esito['scadute_in_attesa']);
printf("  CANCELLATE .................... %d\n", $esito['cancellate']);

// Le bloccate sono l'unico numero di questo resoconto che significa «qualcosa
// non funziona»: scadute da un pezzo e mai annunciate. Senza avviso non si
// cancellano, quindi restano li' per sempre — e siccome non entrano mai fra le
// mature, guardare le sole mature non basterebbe a vederle. Non fanno uscire
// questo strumento in errore, perche' un invio fallito una notte si ritenta la
// notte dopo: a giudicare e' l'invariante `bozze` della diagnostica, che gira
// due volte al giorno e non ha bisogno della posta per gridare.
if ($esito['bloccate'] > 0) {
    printf("\n  BLOCCATE ...................... %d\n", $esito['bloccate']);
    echo "  Scadute da oltre una settimana e mai annunciate: l'avviso non parte.\n";
    echo "  Guardare l'esito degli avvisi qui sopra — `non_partita` è la posta.\n";
}

$nonRecapitati = ($esito['avvisi'][SpazzataDelleBozze::SENZA_INDIRIZZO] ?? 0)
               + ($esito['avvisi'][SpazzataDelleBozze::SENZA_POSTA] ?? 0);
if ($nonRecapitati > 0) {
    printf("\n  ATTENZIONE: %d avvisi non recapitati (indirizzo mancante o posta non configurata).\n", $nonRecapitati);
    echo "  Le righe sono state segnate lo stesso e si cancelleranno: altrimenti un\n";
    echo "  indirizzo mancante terrebbe quei dati per sempre. Va guardato.\n";
}

foreach ($esito['errori'] as $e) {
    fwrite(STDERR, '  ERRORE: ' . $e . "\n");
}

if (!$applica) {
    echo "\nNiente e' stato scritto. Per farlo davvero:\n";
    echo "  php tools/gdpr/spazza_bozze_scadute.php --applica\n";
}

exit($esito['errori'] === [] ? 0 : 1);
