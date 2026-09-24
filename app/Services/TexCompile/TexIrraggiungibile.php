<?php

declare(strict_types=1);

namespace App\Services\TexCompile;

use App\Support\Anomalia;

/**
 * Che cosa si fa quando il servizio TeX non risponde (19/9/2026).
 *
 * Dall'8 settembre 2026, passata l'applicazione nel container, ogni
 * compilazione avviata dal sito falliva: il container cercava il TeX sul
 * proprio 127.0.0.1, dove non ascolta nessuno. È durato undici giorni perché
 * il guasto non lasciava traccia: i client restituivano l'errore di cURL al
 * docente — «Errore di rete: [7] Failed to connect to 127.0.0.1 port 8001 …»,
 * con dentro l'indirizzo di un servizio interno — e a nessun altro.
 *
 * Adesso, quando cURL non arriva al servizio (errno diverso da zero), i quattro
 * client passano di qui, che fa tre cose:
 *
 *   - una riga in `error_log`: nel container finisce in `docker logs`
 *     (docker/php.ini), sull'host nel registro degli errori PHP;
 *   - un'anomalia `tex_irraggiungibile` nel registro che la diagnostica legge e
 *     trasforma in una mail (con il contenimento del rumore di Anomalia: una
 *     riga ogni cinque minuti, le altre contate);
 *   - al docente un messaggio che dice cosa succede e non da dove.
 *
 * Mai il sorgente del documento: né nel registro né nell'anomalia.
 */
final class TexIrraggiungibile
{
    /** Quello che legge il docente: niente indirizzi, niente codici di cURL. */
    public const MESSAGGIO = 'Il servizio di compilazione non risponde.';

    /**
     * L'altro caso, che non è un guasto del servizio: il documento ha impiegato
     * più del tetto del client.
     */
    public const MESSAGGIO_LENTO = 'La compilazione ha superato il tempo massimo: '
        . 'il documento è troppo pesante, o ci sono troppe figure da disegnare insieme.';

    /** Il codice di cURL per «è scaduto il tempo»: CURLE_OPERATION_TIMEDOUT. */
    public const TEMPO_SCADUTO = 28;

    /**
     * Il nome di un errore di cURL, per chi legge: la diagnostica, /health/tex,
     * il registro. I tre che contano sono quelli che si vedono davvero quando
     * il servizio non si raggiunge.
     */
    public static function classe(int $errno): string
    {
        return match ($errno) {
            7       => 'connessione rifiutata',  // CURLE_COULDNT_CONNECT: nessuno ascolta lì
            28      => 'tempo scaduto',          // CURLE_OPERATION_TIMEDOUT: un firewall che scarta, o un servizio fermo
            6       => 'nome non risolto',       // CURLE_COULDNT_RESOLVE_HOST
            default => 'errore di rete',
        };
    }

    /**
     * La connessione al servizio era stata stabilita? (20/9/2026)
     *
     * `CURLINFO_CONNECT_TIME` è il tempo impiegato dalla stretta di mano TCP
     * (e TLS): zero se non è mai avvenuta. Va letto **prima** di
     * `curl_close()`, come `curl_errno()`.
     *
     * Serve a separare due cose che l'errno 28 confonde: il firewall che
     * scarta i pacchetti (nessuna connessione, il servizio è irraggiungibile)
     * e la compilazione più lunga del tetto del client (connessione stabilita,
     * il servizio sta lavorando).
     */
    public static function connesso(\CurlHandle $ch): bool
    {
        return ((float)curl_getinfo($ch, CURLINFO_CONNECT_TIME)) > 0.0;
    }

    /**
     * È una compilazione troppo lunga, e non un servizio che non si raggiunge?
     *
     * Solo l'errno 28 **con la connessione stabilita**. Un 52 o un 56 a
     * connessione aperta (risposta vuota, ricezione fallita) sono un worker di
     * uvicorn morto a metà richiesta: quelli restano guasti.
     */
    public static function compilazioneTroppoLunga(int $errno, bool $connesso): bool
    {
        return $errno === self::TEMPO_SCADUTO && $connesso;
    }

    /** `host:porta` dell'endpoint, per i registri interni. */
    public static function servizio(string $endpoint): string
    {
        $host = (string)parse_url($endpoint, PHP_URL_HOST);
        $porta = parse_url($endpoint, PHP_URL_PORT);
        if ($porta === null || $porta === false) {
            $porta = parse_url($endpoint, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        }
        return $host . ':' . $porta;
    }

    /**
     * Registra il guasto e restituisce il messaggio per il docente.
     *
     * @param string $operazione quale chiamata non è arrivata (compile, render-tikz, …)
     * @param bool   $connesso   la connessione al servizio c'era? (`connesso()`)
     */
    public static function segnala(int $errno, string $endpoint, string $operazione, bool $connesso = false): string
    {
        $servizio = self::servizio($endpoint);

        // Una compilazione più lunga del tetto del client non è un guasto di
        // rete, e non deve diventare una mail (20/9/2026).
        //
        // I tetti del servizio stanno sopra quelli dei client (le misure sono
        // in docs/ops/tex-dal-container.md), quindi un documento pesante
        // scadeva **sempre** dalla parte del client: errno 28, e fino a oggi
        // un'anomalia `tex_irraggiungibile` che la diagnostica trasforma in
        // una mail e che rimanda al runbook di rete e firewall. Un falso
        // allarme ricorrente, e un rapporto che non è mai pulito dopo un mese
        // non lo apre più nessuno.
        //
        // Resta la riga in `error_log`: serve a capire quanti documenti
        // superano il tetto, ma non è un'incoerenza interna.
        if (self::compilazioneTroppoLunga($errno, $connesso)) {
            error_log("[tex] compilazione troppo lunga (errno={$errno}, connessione stabilita) "
                . "su {$servizio}, operazione {$operazione}");
            return self::MESSAGGIO_LENTO;
        }

        $classe = self::classe($errno);
        error_log("[tex] servizio irraggiungibile errno={$errno} ({$classe}) su {$servizio}, operazione {$operazione}");
        Anomalia::registra(
            'tex_irraggiungibile',
            "Il servizio TeX non risponde ({$classe}): le compilazioni avviate da qui falliscono. "
                . 'Vedi docs/ops/tex-dal-container.md.',
            ['servizio' => $servizio, 'errno' => $errno, 'classe' => $classe, 'operazione' => $operazione],
        );
        return self::MESSAGGIO;
    }
}
