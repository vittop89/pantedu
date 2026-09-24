<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Config;
use Throwable;

/**
 * CrowdSec Bouncer — query Local API (LAPI) self-hosted.
 *
 * Phase 25.J.3 — alternativa free al CrowdSec Service API ($29/mo).
 *
 * Architettura:
 *   - Agent CrowdSec installato su VPS (apt install crowdsec)
 *   - Agent scrape /var/log/nginx/access.log + altri log
 *   - Agent detect attacchi (HTTP brute-force, CVE, scan, etc.)
 *   - Agent crea decisioni locali in LAPI sqlite
 *   - LAPI espone REST API (sull'host, di serie http://127.0.0.1:8080)
 *   - Bouncer (= questo service) query LAPI per ogni request:
 *     GET /v1/decisions?ip=X.X.X.X  → 200 con `null` o con l'elenco delle decisioni
 *
 * Bouncer auth:
 *   Header X-Api-Key: <bouncer_key>
 *   Generato sul VPS: cscli bouncers add pantedu-php
 *
 * Free: l'agent + LAPI sono open source. Community Hub (Spamhaus + altri
 * feed) anche free. Solo "Console" web (= Service API monitoraggio) è
 * a pagamento ($29/mo) ma NON serve per questo bouncer.
 *
 * Fail-open: se LAPI down/timeout → no block. Non blocchiamo utenti
 * per outage interno. Resta così (invariante).
 *
 * 23/9/2026 — dove sta la LAPI, e come la si riconosce (A-15 della revisione
 * architetturale del 23/9).
 *
 *   - **Nessun indirizzo predefinito.** Era `http://127.0.0.1:8080`, cioè la
 *     porta di serie della LAPI **sull'host**. Dall'8/9/2026 l'applicazione
 *     gira nel container, dove 127.0.0.1:8080 è il nginx dell'applicazione
 *     stessa (docker/nginx.conf). Con la chiave impostata e l'URL lasciato al
 *     predefinito, il bouncer interrogava il sito, riceveva un 404, e
 *     fail-open lasciava passare tutti: un livello del WAF mancava in silenzio.
 *     Adesso servono tutte e due, URL e chiave; con una sola il bouncer è
 *     spento, e la diagnostica (`crowdsec`) dice che è configurato a metà.
 *   - **Una risposta della LAPI si riconosce** (`riconosci()`): 200 JSON con
 *     `null` o con un elenco di decisioni; 403 JSON con «access forbidden» se
 *     la chiave è rifiutata. Qualunque altra cosa non è la LAPI. Il pannello
 *     dava «raggiungibile» a ogni codice fra 200 e 499, quindi anche al 404
 *     del nginx dell'applicazione.
 */
final class WafCrowdSecBouncerService
{
    /** La LAPI ha risposto, con la chiave accettata. */
    public const RISPOSTA_LAPI = 'lapi';
    /** La LAPI ha risposto, e rifiuta la chiave: nessuna decisione arriva. */
    public const RISPOSTA_CHIAVE_RIFIUTATA = 'chiave_rifiutata';
    /** Ha risposto qualcosa che non è la LAPI (per esempio il nginx dell'applicazione). */
    public const RISPOSTA_NON_LAPI = 'non_lapi';
    /** Nessuna risposta: connessione rifiutata, tempo scaduto, nome non risolto. */
    public const RISPOSTA_IRRAGGIUNGIBILE = 'irraggiungibile';

    /** @var array<string, array{ts:int, decision:?array}> */
    private static array $cache = [];
    private const CACHE_TTL_S = 30;

    // Proprietà classiche e non promosse: semgrep non legge le promosse.
    private string $lapiUrl;
    private string $lapiKey;
    private int $timeoutMs;

    public function __construct(string $lapiUrl = '', string $lapiKey = '', int $timeoutMs = 500)
    {
        $this->lapiUrl = $lapiUrl;
        $this->lapiKey = $lapiKey;
        $this->timeoutMs = $timeoutMs;
    }

    public static function default(): self
    {
        // Senza ripiego sulla loopback: vedi l'intestazione (23/9/2026).
        return new self(
            (string)Config::get('waf.crowdsec_lapi_url', $_ENV['CROWDSEC_LAPI_URL'] ?? ''),
            (string)Config::get('waf.crowdsec_lapi_key', $_ENV['CROWDSEC_LAPI_KEY'] ?? ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->lapiUrl !== '' && $this->lapiKey !== '';
    }

    /**
     * Le variabili che mancano perché il bouncer sia acceso.
     *
     * @return list<string>
     */
    public function mancano(): array
    {
        $mancano = [];
        if ($this->lapiUrl === '') {
            $mancano[] = 'CROWDSEC_LAPI_URL';
        }
        if ($this->lapiKey === '') {
            $mancano[] = 'CROWDSEC_LAPI_KEY';
        }
        return $mancano;
    }

    /** `host:porta` della LAPI configurata, per le prove e il pannello: mai la chiave. */
    public function servizio(): string
    {
        $host = (string)parse_url($this->lapiUrl, PHP_URL_HOST);
        $porta = parse_url($this->lapiUrl, PHP_URL_PORT);
        if (!\is_int($porta)) {
            $porta = parse_url($this->lapiUrl, PHP_URL_SCHEME) === 'https' ? 443 : 80;
        }
        return $host . ':' . $porta;
    }

    /**
     * Che cosa ha risposto a `GET /v1/decisions`: la LAPI, o qualcos'altro?
     *
     * La forma è quella del sorgente di CrowdSec (apiserver: `GetDecision`
     * risponde con `c.JSON(200, results)`, e un elenco vuoto diventa `null`; il
     * middleware della chiave risponde `403 {"message":"access forbidden"}`).
     * Non è stata misurata su una LAPI vera da questo repository: se una
     * versione nuova cambiasse forma, la diagnostica lo direbbe come «non è la
     * LAPI», cioè con un guasto, non con un verde.
     *
     * @param int          $http  il codice HTTP (0 se non c'è stata risposta)
     * @param string       $tipo  il Content-Type della risposta
     * @param string|false $corpo il corpo, false se cURL non ha avuto risposta
     */
    public static function riconosci(int $http, string $tipo, string|false $corpo): string
    {
        if ($http === 0 || $corpo === false) {
            return self::RISPOSTA_IRRAGGIUNGIBILE;
        }
        if (!str_starts_with(strtolower(trim($tipo)), 'application/json')) {
            return self::RISPOSTA_NON_LAPI;
        }
        $dati = json_decode($corpo, true);
        if ($http === 403) {
            return \is_array($dati) && ($dati['message'] ?? null) === 'access forbidden'
                ? self::RISPOSTA_CHIAVE_RIFIUTATA
                : self::RISPOSTA_NON_LAPI;
        }
        if ($http !== 200) {
            return self::RISPOSTA_NON_LAPI;
        }
        if (trim($corpo) === 'null') {
            return self::RISPOSTA_LAPI;
        }
        if (!\is_array($dati) || !array_is_list($dati)) {
            return self::RISPOSTA_NON_LAPI;
        }
        foreach ($dati as $d) {
            if (!\is_array($d) || !\is_string($d['type'] ?? null) || !\is_string($d['value'] ?? null)) {
                return self::RISPOSTA_NON_LAPI;
            }
        }
        return self::RISPOSTA_LAPI;
    }

    /**
     * Check IP against CrowdSec LAPI.
     *
     * @return array{action:string, scenario:string, origin:string, duration:string}|null
     *         null = no decision (IP non bloccato)
     */
    public function checkIp(string $ip): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }
        // Cache in-process: stessa IP nella stessa request non query LAPI 2 volte
        $cached = self::$cache[$ip] ?? null;
        if ($cached !== null && (time() - $cached['ts']) < self::CACHE_TTL_S) {
            return $cached['decision'];
        }
        $decision = $this->queryLapi($ip);
        self::$cache[$ip] = ['ts' => time(), 'decision' => $decision];
        return $decision;
    }

    /**
     * @return array{action:string, scenario:string, origin:string, duration:string}|null
     */
    private function queryLapi(string $ip): ?array
    {
        $url = rtrim($this->lapiUrl, '/') . '/v1/decisions?ip=' . rawurlencode($ip);
        try {
            $r = $this->chiedi($url, $this->timeoutMs);
            // Una decisione si prende solo da una risposta della LAPI. Tutto il
            // resto — il 404 del nginx dell'applicazione, la chiave rifiutata,
            // la LAPI ferma — è «nessuna decisione»: fail-open, come sempre.
            if (self::riconosci($r['http'], $r['tipo'], $r['corpo']) !== self::RISPOSTA_LAPI) {
                return null;
            }
            $data = json_decode((string)$r['corpo'], true);
            if (!\is_array($data) || $data === []) {
                return null; // `null`: nessuna decisione per questo indirizzo
            }
            // Prima decisione (più severa: type 'ban' > 'captcha' > 'throttle')
            $best = null;
            $weight = ['ban' => 100, 'captcha' => 50, 'throttle' => 10];
            foreach ($data as $d) {
                $type = (string)($d['type'] ?? '');
                $w = $weight[$type] ?? 0;
                if ($best === null || $w > ($weight[$best['type']] ?? 0)) {
                    $best = $d;
                }
            }
            if ($best === null) {
                return null;
            }
            return [
                'action'   => $best['type'] === 'ban' ? 'block' : 'challenge',
                'scenario' => (string)($best['scenario'] ?? ''),
                'origin'   => (string)($best['origin'] ?? ''),
                'duration' => (string)($best['duration'] ?? ''),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Una richiesta alla LAPI, con quello che serve per riconoscerla.
     *
     * @return array{http:int, tipo:string, corpo:string|false, errno:int, errore:string}
     */
    private function chiedi(string $url, int $tettoMs): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_TIMEOUT_MS        => $tettoMs,
            CURLOPT_CONNECTTIMEOUT_MS => max(100, (int)($tettoMs / 2)),
            CURLOPT_HTTPHEADER        => [
                'X-Api-Key: ' . $this->lapiKey,
                'User-Agent: pantedu-waf/25.J',
                'Accept: application/json',
            ],
        ]);
        $corpo = curl_exec($ch);
        $r = [
            'http'   => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'tipo'   => (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE),
            'corpo'  => \is_string($corpo) ? $corpo : false,
            'errno'  => curl_errno($ch),
            'errore' => curl_error($ch),
        ];
        curl_close($ch);
        return $r;
    }

    /**
     * Lo stato del bouncer, per il pannello del WAF e per la diagnostica.
     *
     * `reachable` è vero **solo** se ha risposto la LAPI con la chiave
     * accettata: fino al 23/9/2026 era vero per ogni codice fra 200 e 499.
     *
     * @return array{
     *     configured: bool,
     *     reachable: bool,
     *     version: ?string,
     *     error: ?string,
     *     mancano: list<string>,
     *     risposta: ?string,
     *     http: int,
     *     tipo: string,
     *     servizio: string
     * }
     */
    public function status(): array
    {
        $stato = [
            'configured' => $this->isConfigured(),
            'reachable'  => false,
            'version'    => null,
            'error'      => null,
            'mancano'    => $this->mancano(),
            'risposta'   => null,
            'http'       => 0,
            'tipo'       => '',
            'servizio'   => $this->lapiUrl === '' ? '' : $this->servizio(),
        ];
        if (!$stato['configured']) {
            $stato['error'] = 'Bouncer spento: manca ' . implode(' e ', $stato['mancano'])
                . '. La chiave si genera sull\'host con «cscli bouncers add pantedu-php»; l\'URL deve '
                . 'essere raggiungibile dal container (non 127.0.0.1, che lì è il container stesso).';
            return $stato;
        }
        try {
            $r = $this->chiedi(rtrim($this->lapiUrl, '/') . '/v1/decisions?ip=127.0.0.1', 2000);
            $stato['http'] = $r['http'];
            $stato['tipo'] = $r['tipo'];
            $stato['risposta'] = self::riconosci($r['http'], $r['tipo'], $r['corpo']);
            $stato['reachable'] = $stato['risposta'] === self::RISPOSTA_LAPI;
            $stato['error'] = $r['errno'] !== 0 ? "cURL {$r['errno']}: {$r['errore']}" : null;
        } catch (Throwable $e) {
            $stato['risposta'] = self::RISPOSTA_IRRAGGIUNGIBILE;
            $stato['error'] = $e->getMessage();
        }
        return $stato;
    }
}
