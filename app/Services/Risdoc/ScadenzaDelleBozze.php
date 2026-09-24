<?php

declare(strict_types=1);

namespace App\Services\Risdoc;

use DateTimeImmutable;

/**
 * Quando muore la bozza di un modello.
 *
 * ── Perché esiste (22 settembre 2026) ────────────────────────────────────
 *
 * Una compilazione è ciò che il docente scrive dentro un atto della scuola:
 * relazione finale, scheda di recupero, piano annuale. Per natura può nominare
 * uno studente. Restava sul server per sempre, e ogni giorno in più è un
 * giorno in cui quei dati possono uscire da una violazione che non è ancora
 * successa.
 *
 * L'art. 5(1)(c) non chiede di non tenerli. Chiede di non tenerli **più del
 * necessario**, e il necessario finisce quando il docente ha il documento in
 * mano.
 *
 * ── Le due regole, e perché sono due ──────────────────────────────────────
 *
 * **La grazia.** Quindici giorni da quando il docente ha SCARICATO il PDF.
 * Scaricare non è «ho finito»: si scarica per vedere l'impaginazione, per
 * stampare, per mostrarla a un collega. Trattare il primo scaricamento come la
 * fine cancellerebbe lavoro in corso. Perciò il conto riparte da capo a ogni
 * modifica — si guarda **l'ultima fra scaricamento e modifica**, non lo
 * scaricamento da solo.
 *
 * **La rete.** Una bozza che non è mai stata scaricata non cadrebbe mai sotto
 * la prima regola: resterebbe per sempre proprio perché nessuno l'ha portata
 * via. La rete la prende a fine anno scolastico. Non il 31 agosto come
 * ricorrenza — un lavoro che salta una notte non deve saltare un anno — ma
 * come **soglia**: dal 31 agosto in poi muore ciò che non si tocca dal 1°
 * giugno precedente. Una bozza ancora lavorata a giugno serve per l'anno che
 * comincia e sopravvive; una ferma da maggio apparteneva a un anno che è
 * finito.
 *
 * Il minimo garantito dalla rete è **tre mesi** (modificata il 31 maggio,
 * cancellata il 31 agosto). Non esiste il caso di una bozza che nasce e muore
 * in pochi giorni per colpa della rete: quello lo fa solo lo scaricamento, che
 * è un gesto del docente.
 *
 * ── Perché la regola sta qui e non nel database ───────────────────────────
 *
 * Una soglia scritta in due posti diverge, e prima o poi si crede a quella
 * sbagliata. Nel database ci sono le due colonne e l'indice (migrazione 138);
 * i numeri stanno qui, in una classe senza database, che si può provare
 * sull'ora che si vuole senza far finta che sia un altro giorno.
 *
 * ── L'ora non si inventa ──────────────────────────────────────────────────
 *
 * Ogni funzione vuole «adesso» come argomento, e chi la chiama lo prende dal
 * database con la stessa `NOW()` che ha scritto `updated_at`. Confrontare una
 * data scritta dal database con l'orologio di PHP è il modo più facile di
 * sbagliare di un'ora due volte l'anno, e di cancellare un giorno prima.
 */
final class ScadenzaDelleBozze
{
    /** Giorni fra lo scaricamento (o l'ultima modifica) e la cancellazione. */
    public const GRAZIA_GIORNI = 15;

    /** Quanti giorni prima della cancellazione si avvisa il docente. */
    public const PREAVVISO_GIORNI = 7;

    /** Il giorno da cui la rete di fine anno scolastico comincia a mordere. */
    public const RETE_MESE  = 8;
    public const RETE_GIORNO = 31;

    /** Prima di questa data, dentro l'anno scolastico che si chiude, si è fermi. */
    public const SOGLIA_MESE  = 6;
    public const SOGLIA_GIORNO = 1;

    public const VIVA          = 'viva';
    public const DA_AVVISARE   = 'da_avvisare';
    public const DA_CANCELLARE = 'da_cancellare';

    /**
     * La scadenza che viene dallo scaricamento, o null se non è mai stato
     * scaricato.
     *
     * Si conta dall'ultima fra scaricamento e modifica: una modifica dopo lo
     * scaricamento vuol dire che il lavoro non era finito, e fa ripartire il
     * conto.
     */
    public static function perScaricamento(?string $scaricataIl, string $modificataIl): ?DateTimeImmutable
    {
        if ($scaricataIl === null || trim($scaricataIl) === '') {
            return null;
        }

        $scaricata = self::momento($scaricataIl);
        $modificata = self::momento($modificataIl);
        if ($scaricata === null) {
            return null;
        }

        $ultima = ($modificata !== null && $modificata > $scaricata) ? $modificata : $scaricata;

        return $ultima->modify('+' . self::GRAZIA_GIORNI . ' days');
    }

    /**
     * La scadenza che viene dalla rete di fine anno scolastico.
     *
     * Il 31 agosto dell'anno la cui soglia (1° giugno) è già passata rispetto
     * all'ultima modifica. Detto per casi:
     *
     *   modificata il 15 maggio 2027  → il 1° giugno 2027 le è già davanti
     *                                   → muore il 31 agosto 2027
     *   modificata il 15 giugno 2027  → ha superato la soglia del suo anno
     *                                   → muore il 31 agosto 2028
     *
     * Non è mai null: tutto ha una fine, anche ciò che nessuno scarica.
     */
    public static function perLaRete(string $modificataIl): DateTimeImmutable
    {
        $modificata = self::momento($modificataIl);
        if ($modificata === null) {
            // Una data illeggibile non è una licenza a tenere per sempre: vale
            // come il 1° gennaio 2000, quindi la riga risulta già scaduta. Non
            // per questo si cancella subito: il giro la avvisa la prima notte
            // e la cancella tre giorni dopo, come ogni riga scaduta senza
            // avviso (SpazzataDelleBozze::daAvvisare). «Come oggi», che diceva
            // questo commento fino al 23/9/2026, non era vero, e non sarebbe
            // nemmeno giusto: «oggi» si sposta ogni notte, e la riga non
            // scadrebbe mai.
            $modificata = new DateTimeImmutable('2000-01-01 00:00:00');
        }

        $anno = (int)$modificata->format('Y');
        $soglia = $modificata->setDate($anno, self::SOGLIA_MESE, self::SOGLIA_GIORNO)
                             ->setTime(0, 0, 0);

        if ($modificata >= $soglia) {
            $anno++;
        }

        return $modificata->setDate($anno, self::RETE_MESE, self::RETE_GIORNO)
                          ->setTime(23, 59, 59);
    }

    /**
     * La prima delle due scadenze: quella che arriva davvero.
     */
    public static function scadeIl(?string $scaricataIl, string $modificataIl): DateTimeImmutable
    {
        $rete = self::perLaRete($modificataIl);
        $scaricamento = self::perScaricamento($scaricataIl, $modificataIl);

        if ($scaricamento === null) {
            return $rete;
        }

        return $scaricamento < $rete ? $scaricamento : $rete;
    }

    /**
     * Quanti giorni mancano. Negativo se la scadenza è passata.
     */
    public static function giorniRimasti(?string $scaricataIl, string $modificataIl, string $adesso): int
    {
        $ora = self::momento($adesso);
        if ($ora === null) {
            // Senza un «adesso» leggibile non si decide niente: si dichiara che
            // manca tantissimo, che è il verso prudente. Cancellare per una
            // stringa mal formata sarebbe la peggiore delle uscite.
            return PHP_INT_MAX;
        }

        $scade = self::scadeIl($scaricataIl, $modificataIl);

        return (int)$ora->setTime(0, 0, 0)->diff($scade->setTime(0, 0, 0))->format('%r%a');
    }

    /**
     * Che cosa se ne fa il lavoro notturno: niente, un avviso, o la
     * cancellazione.
     *
     * `avvisataIl` serve solo a non riscrivere ogni notte: un avviso che arriva
     * tutti i giorni è un avviso che dopo tre giorni non si legge più.
     */
    public static function stato(
        ?string $scaricataIl,
        string $modificataIl,
        ?string $avvisataIl,
        string $adesso
    ): string {
        $rimasti = self::giorniRimasti($scaricataIl, $modificataIl, $adesso);

        if ($rimasti <= 0) {
            return self::DA_CANCELLARE;
        }

        if ($rimasti <= self::PREAVVISO_GIORNI && ($avvisataIl === null || trim($avvisataIl) === '')) {
            return self::DA_AVVISARE;
        }

        return self::VIVA;
    }

    /**
     * Perché muore: serve a dirlo al docente con parole sue, e a distinguere
     * nel resoconto del lavoro notturno le due regole.
     */
    public static function motivo(?string $scaricataIl, string $modificataIl): string
    {
        $scaricamento = self::perScaricamento($scaricataIl, $modificataIl);
        if ($scaricamento !== null && $scaricamento <= self::perLaRete($modificataIl)) {
            return 'scaricata';
        }

        return 'fine_anno';
    }

    private static function momento(string $quando): ?DateTimeImmutable
    {
        $quando = trim($quando);
        if ($quando === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($quando);
        } catch (\Throwable) {
            return null;
        }
    }
}
