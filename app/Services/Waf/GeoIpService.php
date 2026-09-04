<?php

declare(strict_types=1);

namespace App\Services\Waf;

/**
 * GeoIP Service — lookup country code da IP.
 *
 * Strategia (in ordine di preferenza):
 *   1. Header `CF-IPCountry` (Cloudflare se proxato) — istantaneo, no DB
 *   2. Header `X-GeoIP-Country` (Nginx ngx_http_geoip2_module pre-impostato)
 *   3. Database MMDB locale via php geoip2/geoip2 SDK
 *   4. Fallback: null (treat as "unknown" → policy decide)
 *
 * Setup database MMDB (in ordine di preferenza per licenza):
 *
 *   Provider raccomandato: DB-IP Lite (CC-BY-4.0 — EUPL-compatible)
 *     Due DB separati:
 *       - dbip-country-lite.mmdb → country lookup (WAF geo-blocking)
 *       - dbip-asn-lite.mmdb     → ASN lookup (WAF threat intel)
 *     1. Download mensile (script: tools/waf/update_dbip_geoip.sh)
 *        - https://db-ip.com/db/download/ip-to-country-lite
 *        - https://db-ip.com/db/download/ip-to-asn-lite
 *     2. Posiziona in: storage/geoip/dbip-{country,asn}-lite.mmdb
 *     3. .env:
 *        - WAF_GEOIP_DB=/path/to/dbip-country-lite.mmdb
 *        - WAF_GEOIP_ASN_DB=/path/to/dbip-asn-lite.mmdb
 *     4. Cron mensile (1° del mese, gestito da update_dbip_geoip.sh)
 *     5. Attribuzione richiesta: NOTICE.md (già presente)
 *
 *   Alternativa storica (proprietary): MaxMind GeoLite2-{Country,ASN}.mmdb
 *     Soggetto a MaxMind EULA — signup richiesto su maxmind.com.
 *     SCONSIGLIATO per deploy pubblicato sotto EUPL — non open-source.
 *
 * L'SDK geoip2/geoip2 (Apache-2.0) supporta entrambi i formati senza modifiche
 * al codice — il binario MMDB è cross-vendor.
 *
 * @see docs/legal/third-party-licenses-audit.md §3 (analisi compat. GeoIP/EUPL)
 */
final class GeoIpService
{
    private ?\GeoIp2\Database\Reader $reader = null;
    private bool $readerInitialized = false;
    private ?\GeoIp2\Database\Reader $asnReader = null;
    private bool $asnReaderInitialized = false;

    /**
     * In-process cache per rDNS + ASN (TTL implicito = vita del processo).
     * @var array<string, array{rdns:?string, ts:int}>
     */
    private static array $rdnsCache = [];
    /** @var array<string, array{asn:?array, ts:int}> */
    private static array $asnCache = [];

    public function __construct(
        private readonly ?string $dbPath = null,
        private readonly ?string $asnDbPath = null,
    ) {
    }

    /**
     * Country code ISO-3166 alpha-2 (es. "IT") o null se sconosciuto.
     *
     * @param string $ip IPv4 o IPv6
     * @param array<string,string> $headers Header HTTP request (Cf-Ipcountry, X-GeoIP-Country)
     */
    public function lookup(string $ip, array $headers = []): ?string
    {
        // Loopback / private → null (skip)
        if ($this->isPrivateIp($ip)) {
            return null;
        }

        // Priority 1: Cloudflare header (case-insensitive)
        foreach ($headers as $k => $v) {
            $kLower = strtolower((string)$k);
            if ($kLower === 'cf-ipcountry' || $kLower === 'x-geoip-country') {
                $cc = strtoupper(trim((string)$v));
                if (preg_match('/^[A-Z]{2}$/', $cc)) {
                    return $cc;
                }
            }
        }

        // Priority 2: MaxMind .mmdb local
        if ($this->dbPath !== null && file_exists($this->dbPath)) {
            return $this->lookupMaxMind($ip);
        }

        return null;
    }

    private function lookupMaxMind(string $ip): ?string
    {
        if (!class_exists('\\GeoIp2\\Database\\Reader')) {
            // SDK non installato — silenzioso fallback
            return null;
        }
        try {
            if (!$this->readerInitialized) {
                /** @psalm-suppress UndefinedClass */
                $this->reader = new \GeoIp2\Database\Reader($this->dbPath);
                $this->readerInitialized = true;
            }
            $record = $this->reader->country($ip);
            return $record->country->isoCode ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Lookup ASN + organizzazione per IP via MaxMind/db-ip ASN mmdb.
     *
     * @return array{asn:int,org:string}|null null se DB mancante / IP privato / errore
     */
    public function lookupAsn(string $ip): ?array
    {
        if ($this->isPrivateIp($ip)) {
            return null;
        }
        if (isset(self::$asnCache[$ip])) {
            return self::$asnCache[$ip]['asn'];
        }
        $result = null;
        if (
            $this->asnDbPath !== null && file_exists($this->asnDbPath)
            && class_exists('\\GeoIp2\\Database\\Reader')
        ) {
            try {
                if (!$this->asnReaderInitialized) {
                    /** @psalm-suppress UndefinedClass */
                    $this->asnReader = new \GeoIp2\Database\Reader($this->asnDbPath);
                    $this->asnReaderInitialized = true;
                }
                $rec = $this->asnReader->asn($ip);
                $asn = (int)($rec->autonomousSystemNumber ?? 0);
                $org = (string)($rec->autonomousSystemOrganization ?? '');
                if ($asn > 0) {
                    $result = ['asn' => $asn, 'org' => $org];
                }
            } catch (\Throwable) {
                $result = null;
            }
        }
        self::$asnCache[$ip] = ['asn' => $result, 'ts' => time()];
        return $result;
    }

    /**
     * Reverse DNS lookup (PTR query) con timeout configurabile.
     * Usa `gethostbyaddr()` (blocking, network-dependent: 50-2000ms).
     * Cache in-process per evitare lookup ripetuti nello stesso request.
     *
     * Nota: PHP non offre timeout nativo per `gethostbyaddr`. Per evitare
     * blocchi lunghi su DNS lenti, l'admin può disabilitare rDNS dal
     * config `enrich_rdns_asn`. Per produzione consigliato Redis cache
     * + cron pre-warm.
     */
    public function reverseDns(string $ip): ?string
    {
        if ($this->isPrivateIp($ip)) {
            return null;
        }
        if (isset(self::$rdnsCache[$ip])) {
            return self::$rdnsCache[$ip]['rdns'];
        }
        $rdns = null;
        try {
            $host = @gethostbyaddr($ip);
            if ($host !== false && $host !== $ip) {
                $rdns = $host;
            }
        } catch (\Throwable) {
            $rdns = null;
        }
        self::$rdnsCache[$ip] = ['rdns' => $rdns, 'ts' => time()];
        return $rdns;
    }

    /**
     * Enrichment bundle (rDNS + ASN) — usata da admin tables quando
     * toggle "RDNS & ASN" ON.
     *
     * @return array{rdns:?string, asn:?int, org:?string}
     */
    public function enrich(string $ip): array
    {
        $asn = $this->lookupAsn($ip);
        return [
            'rdns' => $this->reverseDns($ip),
            'asn'  => $asn['asn'] ?? null,
            'org'  => $asn['org'] ?? null,
        ];
    }

    /**
     * Verifica IP loopback / RFC1918 / link-local.
     */
    public function isPrivateIp(string $ip): bool
    {
        return !filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    /**
     * Country code ISO-3166 alpha-2 → HTML flag (img PNG via flagcdn.com).
     * Es. "IT" → '<img src="https://flagcdn.com/w20/it.png" alt="IT" ...>'.
     *
     * Motivo: regional indicator unicode pair (🇮🇹) NON viene renderato
     * come bandiera su Windows (font Segoe UI Emoji non li copre).
     * PNG flagcdn copre tutti i paesi ISO, 0 latency su CDN, cache-friendly.
     *
     * Ritorna null per code invalidi.
     */
    public static function countryFlag(?string $isoCode): ?string
    {
        if ($isoCode === null || !preg_match('/^[A-Z]{2}$/', $isoCode)) {
            return null;
        }
        $cc = strtolower($isoCode);
        return sprintf(
            '<img src="https://flagcdn.com/w20/%s.png" srcset="https://flagcdn.com/w40/%s.png 2x" alt="%s" width="20" height="15" loading="lazy">',
            $cc,
            $cc,
            htmlspecialchars($isoCode, ENT_QUOTES),
        );
    }

    /**
     * Verifica se l'IP è in una whitelist di CIDR.
     *
     * @param string $ip
     * @param list<string> $cidrs es. ["192.168.0.0/16", "10.0.0.0/8"]
     */
    public static function ipInCidrList(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, trim($cidr))) {
                return true;
            }
        }
        return false;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            // Plain IP match
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int)$mask;

        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $bytes = strlen($ipBin);
        $maskBin = str_repeat("\xff", intdiv($mask, 8));
        $remBits = $mask % 8;
        if ($remBits !== 0) {
            $maskBin .= chr((0xff << (8 - $remBits)) & 0xff);
        }
        $maskBin = str_pad($maskBin, $bytes, "\0");
        return ($ipBin & $maskBin) === ($subnetBin & $maskBin);
    }
}
