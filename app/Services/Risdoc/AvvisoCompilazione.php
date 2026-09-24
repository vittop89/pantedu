<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

/**
 * L'avviso che il docente legge mentre compila un modello istituzionale.
 *
 * PERCHE' (2026-09-22)
 *   I Termini (§2.5) dicono gia' che qui si redigono bozze e che i dati
 *   personali degli studenti non si inseriscono. Ma li legge chi si iscrive,
 *   una volta, e chi scrive una relazione finale sei mesi dopo non li ha
 *   davanti. L'unico momento in cui quel divieto serve e' quello in cui si
 *   sta per scrivere.
 *
 *   Non impedisce niente — il testo libero resta libero, ed e' il rischio R20
 *   della DPIA. Sposta pero' l'unica cosa che un avviso possa spostare: chi
 *   scrive un dato vietato l'ha fatto dopo averlo letto lì.
 *
 * REGOLA
 *   Solo sui modelli istituzionali (CompilationStoragePolicy::isInstitutional),
 *   perche' sono quelli che parlano di studenti per natura, e solo dove non
 *   esistono account studente — cioe' dove lo studente non e' un interessato
 *   che la piattaforma conosce, e un suo dato scritto a mano non ha base
 *   giuridica. Con gli account studente (scenario 3) il quadro e' un altro e
 *   l'avviso direbbe una cosa falsa.
 *
 *   La seconda meta' della frase cambia con il salvataggio, e deve: dire
 *   «i campi si svuotano al salvataggio» dove non si salva niente sarebbe una
 *   rassicurazione a vuoto.
 */
final class AvvisoCompilazione
{
    private const APERTURA = 'Bozza di lavoro, non un atto della scuola. Non inserire dati personali degli studenti';

    /**
     * Il testo da mostrare, o null se non va mostrato niente.
     *
     * @param bool $istituzionale     CompilationStoragePolicy::isInstitutional()
     * @param bool $conAccountStudente DeploymentScenario::studentAccountsEnabled()
     * @param bool $siSalva           CompilationStoragePolicy::allowedFor()
     */
    public static function perModello(
        bool $istituzionale,
        bool $conAccountStudente,
        bool $siSalva
    ): ?string {
        if (!$istituzionale || $conAccountStudente) {
            return null;
        }

        // Dove si salva, lo scrubber svuota i campi che nominano una persona
        // (CompilationScrubber) e il testo libero no: si dicono tutte e due,
        // altrimenti e' una mezza verita' che rassicura.
        if ($siSalva) {
            return self::APERTURA
                . ': i campi che nominano una persona vengono svuotati al salvataggio, il testo libero no.';
        }

        return self::APERTURA
            . ': questa compilazione non si salva sul server e resta nel browser fino all’esportazione.';
    }

    /**
     * La seconda frase: quanto dura la bozza (ADR-046).
     *
     * PERCHE' E' SEPARATA DALLA PRIMA
     *   Sono due fatti con due destinatari diversi. Il primo riguarda i
     *   modelli istituzionali dove non ci sono account studente, ed e' un
     *   divieto. Questo riguarda **qualunque** compilazione salvata sul
     *   server, ed e' un'informazione: il docente deve sapere che il suo
     *   lavoro non resta li' per sempre, e deve saperlo prima, non quando gli
     *   arriva la mail dell'ultimo momento.
     *
     *   Tenerle insieme avrebbe voluto dire o non dire la scadenza sui modelli
     *   non istituzionali — dove pero' vale lo stesso — o dire il divieto
     *   ovunque, dove pero' non vale.
     *
     * DOVE NON SI SALVA non c'e' niente che scada: lo dice gia' l'altra frase.
     *
     * I numeri arrivano da {@see ScadenzaDelleBozze}: sono gli stessi che
     * applica il lavoro notturno, non una copia.
     */
    public static function scadenza(bool $siSalva): ?string
    {
        if (!$siSalva) {
            return null;
        }

        return sprintf(
            'Questa bozza si cancella dal server %d giorni dopo che avrai scaricato il documento, '
            . 'e comunque il %d agosto se resta ferma: ogni modifica fa ripartire il conto. '
            . 'Il modello resta, e puoi ricompilarlo quando vuoi.',
            ScadenzaDelleBozze::GRAZIA_GIORNI,
            ScadenzaDelleBozze::RETE_GIORNO,
        );
    }
}
