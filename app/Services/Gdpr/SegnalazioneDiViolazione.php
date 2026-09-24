<?php

declare(strict_types=1);

namespace App\Services\Gdpr;

/**
 * Una segnalazione di violazione non è una richiesta sui propri dati, e non
 * si risponde con lo stesso orologio.
 *
 * ── Il difetto, misurato il 22/9/2026 ─────────────────────────────────────
 *
 * Il modulo `/dpo-contact` offre nove oggetti, e uno è «Segnalazione data
 * breach». La ricevuta però era **la stessa per tutti e nove**: prometteva una
 * risposta «entro 30 giorni, prorogabili a 60 (art. 12 §3)» anche a chi stava
 * segnalando una violazione. E la richiesta veniva portata da sola a
 * `acknowledged`, cioè appariva già presa in carico nella coda.
 *
 * Per una richiesta di accesso o di cancellazione quei trenta giorni sono
 * giusti. Per una violazione l'orologio è un altro: l'art. 33 dà **72 ore**
 * da quando il titolare ne viene a conoscenza, e la conoscenza comincia
 * esattamente lì, quando quel modulo viene inviato. Promettere un mese a chi
 * segnala una violazione è, oltre che sbagliato, il modo migliore per non
 * ricevere il secondo messaggio quando la situazione peggiora.
 *
 * ── Perché lo stato resta «open» ──────────────────────────────────────────
 *
 * `acknowledged` vuol dire «ricevuta confermata», e per una richiesta di
 * diritti è vero: la ricevuta è partita. Ma nella coda
 * `/admin/data-requests` una riga `acknowledged` si legge come una riga già
 * sistemata, e una segnalazione di violazione non è sistemata finché una
 * persona non l'ha valutata. Resta quindi `open`: la ricevuta parte lo stesso,
 * è il registro che non dice una cosa che nessuno ha fatto.
 */
final class SegnalazioneDiViolazione
{
    /** L'oggetto del modulo che apre l'istruttoria dell'art. 33. */
    public const OGGETTO = 'breach_report';

    public static function riconosce(string $oggetto): bool
    {
        return $oggetto === self::OGGETTO;
    }

    /**
     * Lo stato con cui la richiesta entra nel registro dopo che la ricevuta è
     * partita. Vedi il perché qui sopra.
     */
    public static function statoDopoLaRicevuta(string $oggetto): string
    {
        return self::riconosce($oggetto) ? 'open' : 'acknowledged';
    }

    /**
     * I punti «che cosa succede ora» della pagina di conferma.
     *
     * @return list<string> frammenti HTML già pronti, senza <li>
     */
    public static function passiDellaRicevuta(string $oggetto): array
    {
        if (!self::riconosce($oggetto)) {
            return [
                'Riceverai un acknowledgment via email entro <strong>72 ore</strong>.',
                'Il Titolare risponderà alla tua richiesta entro <strong>30 giorni</strong>'
                    . ' (prorogabili a 60 con comunicazione motivata, Art. 12 §3 GDPR).',
                'In caso di insoddisfazione, puoi presentare reclamo al'
                    . ' <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">Garante Privacy</a>.',
            ];
        }

        return [
            'Questa <strong>non</strong> è una richiesta sui tuoi dati: è la segnalazione di una'
                . ' possibile violazione, e segue una procedura diversa.',
            'Da questo momento decorrono <strong>72 ore</strong> (Art. 33 GDPR) entro cui il Titolare'
                . ' deve valutarla e, se ne ricorrono i presupposti, notificarla al Garante.',
            'Se la violazione riguarda dati di cui è Titolare un altro soggetto — per esempio una'
                . ' scuola — avvisiamo anche quello, senza ingiustificato ritardo.',
            'Non devi fare altro. Se servono dettagli ti scriviamo noi all\'indirizzo che hai indicato.',
            'Puoi in ogni caso rivolgerti al'
                . ' <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">Garante Privacy</a>.',
        ];
    }

    /**
     * Le righe «che cosa succede ora» dell'email al richiedente.
     *
     * @return list<string> righe in testo semplice, senza il trattino iniziale
     */
    public static function passiDellEmail(string $oggetto): array
    {
        if (!self::riconosce($oggetto)) {
            return [
                'Il Titolare la prenderà in carico entro 30 giorni (Art. 12 §3 GDPR).',
                'In caso di complessità il termine può essere prorogato a 60g con comunicazione motivata.',
            ];
        }

        return [
            'Non è una richiesta sui tuoi dati: è la segnalazione di una possibile violazione.',
            'Da adesso decorrono 72 ore (Art. 33 GDPR) per la valutazione e, se dovuta, la notifica al Garante.',
            'Se i dati sono di un altro Titolare, per esempio una scuola, avvisiamo anche quello.',
            'Non devi fare altro: se servono dettagli ti scriviamo noi.',
        ];
    }

    /**
     * La riga che dice all'operatore che orologio è partito. È l'unica parte
     * che una persona leggerà di corsa, quindi sta in testa al messaggio.
     */
    public static function intestazionePerOperatore(string $oggetto): string
    {
        if (!self::riconosce($oggetto)) {
            return 'SLA: rispondi entro 30 giorni dalla ricezione (Art. 12 §3).';
        }

        return "SEGNALAZIONE DI VIOLAZIONE — l'orologio dell'Art. 33 parte da questo messaggio:\n"
            . "72 ore per valutare e, se dovuta, notificare al Garante.\n"
            . "Apri l'incidente in /admin/data-breach e segui docs/privacy/data_breach_runbook.md.\n"
            . 'Se i dati sono di un Istituto, va avvisato senza ingiustificato ritardo.';
    }
}
