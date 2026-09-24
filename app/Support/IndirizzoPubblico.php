<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * La radice degli indirizzi assoluti che l'applicazione mette in ciò che
 * esce da lei: i collegamenti delle email, i codici QR delle credenziali di
 * classe, il ritorno dell'autorizzazione di Drive, l'intestazione delle
 * chiamate verso servizi esterni.
 *
 * LA REGOLA (23/9/2026): **la radice è `app.url`, e basta.** Senza, o con un
 * valore che non è un indirizzo `http(s)://host`, non c'è ripiego:
 *
 *   - né sul dominio di produzione. Fino al 23/9 nove flussi di `app/` (e
 *     uno strumento) ripiegavano sul dominio quando `app.url` mancava, e due
 *     lo scrivevano direttamente nel collegamento: un'altra istanza — il
 *     progetto si dichiara installabile da altri in `publiccode.yml` — o la
 *     CI con `app.url` vuota mandavano collegamenti e gettoni di reset verso
 *     il sito di qualcun altro (revisione architetturale del 23/9/2026,
 *     rilievo A-13);
 *   - né sull'intestazione `Host` della richiesta, che il visitatore scrive
 *     come vuole. È la via dell'avvelenamento dei collegamenti di reset: chi
 *     chiede il reset per la vittima con `Host: sito-suo` riceve, nella
 *     casella della vittima, un link al proprio sito con il gettone dentro.
 *     Si è scelta la via che non ha casi: nemmeno per i codici QR, che
 *     tornano nella stessa risposta, si guarda la richiesta. Lì si vedeva
 *     anche il secondo difetto dell'intestazione, il 14/9/2026 facendo girare
 *     la suite contro l'immagine del rilascio: il nginx del container passa a
 *     PHP `HTTP_HOST` **senza porta**, e in CI il pacchetto risultava su
 *     `http://127.0.0.1/…`.
 *
 * Quando manca, si registra un'anomalia `indirizzo_pubblico_mancante` (con il
 * flusso che la cercava: la diagnostica la trasforma in una mail) e una riga
 * in `error_log`, e poi dipende dal messaggio:
 *
 *   - `radice()`: il collegamento **è** il messaggio — un gettone, una
 *     conferma, un'azione da fare sul sito. Si lancia
 *     `IndirizzoPubblicoMancante` e il messaggio non parte; il flusso lo dice
 *     nel modo che ha già (un esito, un avviso all'amministratore, un'uscita
 *     con errore);
 *   - `radiceSeConfigurata()`: il collegamento accompagna un messaggio che ha
 *     un'altra ragione d'essere — un codice di accesso, un avviso dovuto
 *     all'interessato, una ricevuta. Torna null e il messaggio parte senza il
 *     collegamento: bloccare l'accesso o tacere un avviso di custodia per un
 *     collegamento di cortesia sarebbe peggio del collegamento che manca.
 *
 * La guardia che il dominio non torni nel codice:
 * `tests/Unit/DominioNonScrittoNelCodiceTest.php`.
 */
final class IndirizzoPubblico
{
    /** Il codice dell'anomalia, uno per tutti i flussi: il flusso sta nei dettagli. */
    public const ANOMALIA = 'indirizzo_pubblico_mancante';

    /**
     * La radice, senza la barra finale; se manca, l'anomalia e l'eccezione.
     *
     * @param string      $flusso chi la chiede, per il registro: `recupero_password`, …
     * @param string|null $appUrl per le prove; di norma `app.url`
     *
     * @throws IndirizzoPubblicoMancante
     */
    public static function radice(string $flusso, ?string $appUrl = null): string
    {
        [$radice, $motivo] = self::leggi($appUrl);
        if ($radice !== null) {
            return $radice;
        }
        self::registraLaMancanza($flusso, $motivo);
        throw new IndirizzoPubblicoMancante(
            "app.url {$motivo}: il flusso «{$flusso}» non può costruire un collegamento assoluto"
        );
    }

    /**
     * La radice, oppure null — registrata come in `radice()` — per i messaggi
     * che partono anche senza collegamento.
     */
    public static function radiceSeConfigurata(string $flusso, ?string $appUrl = null): ?string
    {
        [$radice, $motivo] = self::leggi($appUrl);
        if ($radice === null) {
            self::registraLaMancanza($flusso, $motivo);
        }
        return $radice;
    }

    /**
     * L'intestazione `User-Agent` delle chiamate verso servizi esterni:
     * `prodotto (+radice)`, cioè chi chiama e dove trovarlo — l'istanza, non
     * la nostra. Senza radice il prodotto e basta, e nessuna anomalia: un
     * `User-Agent` non è un collegamento che qualcuno debba aprire.
     */
    public static function agente(string $prodotto, ?string $appUrl = null): string
    {
        [$radice] = self::leggi($appUrl);
        return $radice === null ? $prodotto : "{$prodotto} (+{$radice})";
    }

    /**
     * @return array{0: string|null, 1: string} la radice, o null e il perché
     */
    private static function leggi(?string $appUrl): array
    {
        $valore = trim($appUrl ?? (string) Config::get('app.url', ''));
        if ($valore === '') {
            return [null, 'vuota'];
        }
        // Un indirizzo `http(s)://host[:porta][/percorso]`, senza credenziali,
        // parametri o frammenti: tutto quello che segue la radice lo aggiunge
        // chi costruisce il collegamento.
        $parti = parse_url($valore);
        if (!\is_array($parti)) {
            return [null, 'non valida'];
        }
        if (
            !\in_array(strtolower($parti['scheme'] ?? ''), ['http', 'https'], true)
            || ($parti['host'] ?? '') === ''
            || isset($parti['user'])
            || isset($parti['pass'])
            || isset($parti['query'])
            || isset($parti['fragment'])
            || preg_match('/\s/', $valore) === 1
        ) {
            return [null, 'non valida'];
        }
        return [rtrim($valore, '/'), ''];
    }

    /** Il valore di `app.url` non si scrive: dice quale flusso e perché, non che cosa c'era. */
    private static function registraLaMancanza(string $flusso, string $motivo): void
    {
        error_log("[indirizzo_pubblico] app.url {$motivo}: «{$flusso}» senza collegamento assoluto");
        Anomalia::registra(
            self::ANOMALIA,
            "app.url {$motivo}: il flusso «{$flusso}» non ha potuto mettere un collegamento al sito.",
            ['flusso' => $flusso, 'motivo' => $motivo],
            self::ANOMALIA . ':' . $flusso,
        );
    }
}
