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
 *   - LAPI espone REST API su http://127.0.0.1:8080
 *   - Bouncer (= questo service) query LAPI per ogni request:
 *     GET /v1/decisions?ip=X.X.X.X  → 200 con array decisions o 404
 *
 * Bouncer auth:
 *   Header X-Api-Key: <bouncer_key>
 *   Generato sul VPS: cscli bouncers add pantedu-php
 *
 * Free: l'agent + LAPI sono open source. Community Hub (Spamhaus + altri
 * feed) anche free. Solo "Console" web (= Service API monitoraggio) è
 * a pagamento ($29/mo) ma NON serve per questo bouncer.
 *
 * Performance: LAPI 127.0.0.1:8080 = TCP localhost ~1ms. Acceptable
 * per ogni request HTTP. Cache in-process per ridurre ulteriormente.
 *
 * Fail-open: se LAPI down/timeout → no block. Non blocchiamo utenti
 * per outage interno.
 */
final class WafCrowdSecBouncerService
{
    /** @var array<string, array{ts:int, decision:?array}> */
    private static array $cache = [];
    private const CACHE_TTL_S = 30;

    public function __construct(
        private readonly string $lapiUrl = '',
        private readonly string $lapiKey = '',
        private readonly int $timeoutMs = 500,
    ) {
    }

    public static function default(): self
    {
        return new self(
            (string)Config::get('waf.crowdsec_lapi_url', $_ENV['CROWDSEC_LAPI_URL'] ?? 'http://127.0.0.1:8080'),
            (string)Config::get('waf.crowdsec_lapi_key', $_ENV['CROWDSEC_LAPI_KEY'] ?? ''),
        );
    }

    public function isConfigured(): bool
    {
        return $this->lapiUrl !== '' && $this->lapiKey !== '';
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
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
                CURLOPT_CONNECTTIMEOUT_MS => max(100, (int)($this->timeoutMs / 2)),
                CURLOPT_HTTPHEADER     => [
                    'X-Api-Key: ' . $this->lapiKey,
                    'User-Agent: pantedu-waf/25.J',
                    'Accept: application/json',
                ],
            ]);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // 200 + body 'null' = no decision (IP clean)
            // 200 + array decisions = blocked/captcha
            if ($http !== 200 || $body === false || $body === 'null' || $body === '') {
                return null;
            }
            $data = json_decode((string)$body, true);
            if (!is_array($data) || empty($data)) {
                return null;
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
     * Status check: ritorna info sull'agent + LAPI.
     *
     * @return array{configured:bool, reachable:bool, version:?string, error:?string}
     */
    public function status(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'reachable'  => false,
                'version'    => null,
                'error'      => 'CROWDSEC_LAPI_KEY non configurato. Genera con: sudo cscli bouncers add pantedu-php',
            ];
        }
        $url = rtrim($this->lapiUrl, '/') . '/v1/decisions?ip=127.0.0.1';
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS     => 2000,
                CURLOPT_HTTPHEADER     => [
                    'X-Api-Key: ' . $this->lapiKey,
                    'User-Agent: pantedu-waf/25.J',
                ],
            ]);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            return [
                'configured' => true,
                'reachable'  => $http >= 200 && $http < 500,
                'version'    => null,
                'error'      => $err ?: null,
            ];
        } catch (Throwable $e) {
            return [
                'configured' => true,
                'reachable'  => false,
                'version'    => null,
                'error'      => $e->getMessage(),
            ];
        }
    }
}
