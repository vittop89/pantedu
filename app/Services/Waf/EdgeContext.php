<?php

declare(strict_types=1);

namespace App\Services\Waf;

use App\Core\Config;

/**
 * EdgeContext — risoluzione affidabile di IP client reale, fiducia sul proxy
 * di bordo e country code, a prova di spoofing X-Forwarded-For.
 *
 * PROBLEMA risolto (audit sicurezza 2026-06-01): il vecchio `clientIp()` si
 * fidava SEMPRE del primo hop di `X-Forwarded-For`/`CF-Connecting-IP`, headers
 * interamente controllati dal client. Un attaccante poteva quindi:
 *   - falsificare l'IP per aggirare blacklist / whitelist / threat-intel /
 *     IP-binding del cookie WAF / geo-block;
 *   - inviare `Cf-IPCountry: IT` per superare il geo-block "solo IT".
 *
 * REGOLA: gli header di forwarding (`CF-Connecting-IP`, `X-Forwarded-For`,
 * `Cf-IPCountry`) sono considerati ATTENDIBILI solo se la connessione TCP
 * (`REMOTE_ADDR`) proviene da un proxy fidato — i range Cloudflare o i proxy
 * elencati in `TRUSTED_PROXIES` — oppure se nginx ha già marcato la request
 * come proveniente dal bordo (`WAF_EDGE_TRUSTED=1`, impostato solo nel vhost
 * con origin lockato ai soli IP Cloudflare).
 *
 * Se la connessione NON è da un proxy fidato (hit diretto all'origin che
 * scavalca Cloudflare), gli header vengono IGNORATI e si usa `REMOTE_ADDR`:
 * niente più spoofing.
 */
final class EdgeContext
{
    /**
     * Range IPv4/IPv6 ufficiali Cloudflare.
     * Fonte: https://www.cloudflare.com/ips/ (stabili, aggiornati raramente).
     * Override/aggiunta possibile via env `TRUSTED_PROXIES` (CIDR CSV).
     *
     * @var list<string>
     */
    private const CLOUDFLARE_CIDRS = [
        // IPv4
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        // IPv6
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private function __construct(
        public readonly string $ip,
        public readonly bool $trustedEdge,
        public readonly ?string $country,
    ) {
    }

    /**
     * @param array<string,mixed> $server $_SERVER / Request::$server
     */
    public static function resolve(array $server): self
    {
        $remote = (string)($server['REMOTE_ADDR'] ?? '');

        // Edge fidato se:
        //  (a) la connessione TCP arriva da un range Cloudflare (stato attuale:
        //      nginx senza real_ip → REMOTE_ADDR = IP di Cloudflare); OPPURE
        //  (b) nginx marca esplicitamente la request come da bordo fidato
        //      (`WAF_EDGE_TRUSTED=1`), da impostare SOLO quando l'origin è
        //      lockato ai soli IP Cloudflare e real_ip è attivo (REMOTE_ADDR
        //      diventa l'IP client reale, quindi (a) non basta più).
        // NB: il marker è un fastcgi_param impostato dal server, NON un header
        // client (HTTP_*), quindi non è falsificabile dal client.
        $marker = (string)($server['WAF_EDGE_TRUSTED'] ?? '') === '1';
        $trusted = self::isTrustedHop($remote) || $marker;

        if (!$trusted) {
            // Hit diretto all'origin (o proxy sconosciuto): NON fidarsi degli
            // header. Usa l'IP di connessione, niente country da header.
            $ip = filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0';
            return new self($ip, false, null);
        }

        // Edge fidato → preferisci CF-Connecting-IP, poi il primo XFF valido.
        $ip = self::firstValidIp([
            (string)($server['HTTP_CF_CONNECTING_IP'] ?? ''),
            self::firstXff((string)($server['HTTP_X_FORWARDED_FOR'] ?? '')),
        ]) ?? (filter_var($remote, FILTER_VALIDATE_IP) ? $remote : '0.0.0.0');

        $country = strtoupper(trim((string)($server['HTTP_CF_IPCOUNTRY'] ?? '')));
        // "XX"/"T1" = Cloudflare sconosciuto/Tor → tratta come ignoto.
        if ($country === '' || $country === 'XX' || $country === 'T1') {
            $country = null;
        }

        return new self($ip, true, $country);
    }

    /**
     * Helper retro-compatibile: ritorna solo l'IP reale.
     */
    public static function clientIp(array $server): string
    {
        return self::resolve($server)->ip;
    }

    private static function isTrustedHop(string $ip): bool
    {
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        foreach (self::trustedCidrs() as $cidr) {
            if (GeoIpService::ipInCidr($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return list<string>
     */
    private static function trustedCidrs(): array
    {
        $extra = (string)(Config::get('waf.trusted_proxies', '') ?: ($_ENV['TRUSTED_PROXIES'] ?? ''));
        $list = self::CLOUDFLARE_CIDRS;
        if ($extra !== '') {
            foreach (explode(',', $extra) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $list[] = $c;
                }
            }
        }
        return $list;
    }

    private static function firstXff(string $xff): string
    {
        if ($xff === '') {
            return '';
        }
        return trim(explode(',', $xff)[0]);
    }

    /**
     * @param list<string> $candidates
     */
    private static function firstValidIp(array $candidates): ?string
    {
        foreach ($candidates as $c) {
            $c = trim($c);
            if ($c !== '' && filter_var($c, FILTER_VALIDATE_IP)) {
                return $c;
            }
        }
        return null;
    }
}
