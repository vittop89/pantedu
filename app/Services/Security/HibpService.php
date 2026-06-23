<?php

declare(strict_types=1);

namespace App\Services\Security;

use Throwable;

/**
 * Have I Been Pwned — password breach check via k-anonymity.
 *
 * API: https://haveibeenpwned.com/API/v3#PwnedPasswords
 * Endpoint: https://api.pwnedpasswords.com/range/{first5sha1}
 * No API key required. Free, unlimited.
 *
 * Privacy: NON inviamo mai la password al server. SHA-1 della password
 * locale, inviamo solo i PRIMI 5 caratteri del hash. Il server risponde
 * con tutti gli hash che iniziano con quei 5 caratteri (~500 risultati
 * tipici). Verifichiamo localmente se il NOSTRO suffix completa uno
 * degli hash → count.
 *
 * SHA-1 considerata sicura SOLO per questo uso (k-anonymity). NON
 * usiamo SHA-1 per storage password (bcrypt cost=12).
 *
 * Cache: in-process per request. Per scala, considerare Redis cache
 * 24h con prefix come key (~16M prefix possibili, response media ~30KB).
 */
final class HibpService
{
    private const API_BASE  = 'https://api.pwnedpasswords.com/range/';
    private const TIMEOUT_S = 3;

    /** @var array<string, list<array{suffix:string, count:int}>> */
    private static array $cache = [];

    public function __construct(
        private readonly int $timeoutSeconds = self::TIMEOUT_S,
    ) {
    }

    /**
     * Quante volte questa password è apparsa in breach pubblici.
     * 0 = mai vista (sicura), > 0 = compromessa.
     *
     * Fail-open: se API non raggiungibile o timeout, ritorna 0 (no block).
     * Non blocchiamo l'utente per outage di servizi esterni.
     */
    public function pwnedCount(string $password): int
    {
        if ($password === '') {
            return 0;
        }
        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $list = $this->fetchRange($prefix);
        if ($list === null) {
            return 0; // fail-open
        }
        foreach ($list as $row) {
            if ($row['suffix'] === $suffix) {
                return $row['count'];
            }
        }
        return 0;
    }

    /**
     * @return list<array{suffix:string,count:int}>|null
     */
    private function fetchRange(string $prefix): ?array
    {
        if (isset(self::$cache[$prefix])) {
            return self::$cache[$prefix];
        }
        $url = self::API_BASE . $prefix;
        try {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
                CURLOPT_USERAGENT      => 'pantedu-waf/25.J (+https://beta.pantedu.eu)',
                CURLOPT_HTTPHEADER     => [
                    'Add-Padding: true', // HIBP padding header (response uniform size)
                ],
            ];
            // Windows CLI: PHP no CA bundle. Detect via composer/ca-bundle o paths Linux.
            $ca = $this->detectCaBundle();
            if ($ca !== null) {
                $opts[CURLOPT_CAINFO] = $ca;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $http !== 200) {
                return null;
            }
            $rows = [];
            foreach (preg_split('/\r?\n/', (string)$body) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    continue;
                }
                [$suf, $cnt] = explode(':', $line, 2);
                $suf = strtoupper(trim($suf));
                $cnt = (int)trim($cnt);
                if ($suf === '' || $cnt === 0) {
                    continue;
                }
                $rows[] = ['suffix' => $suf, 'count' => $cnt];
            }
            self::$cache[$prefix] = $rows;
            return $rows;
        } catch (Throwable) {
            return null;
        }
    }

    private function detectCaBundle(): ?string
    {
        if (class_exists('\\Composer\\CaBundle\\CaBundle')) {
            try {
                /** @psalm-suppress UndefinedClass */
                return \Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
            } catch (Throwable) {
            }
        }
        $iniCa = (string)ini_get('openssl.cafile') ?: (string)ini_get('curl.cainfo');
        if ($iniCa !== '' && is_file($iniCa)) {
            return $iniCa;
        }
        foreach (
            [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
            ] as $p
        ) {
            if (is_file($p)) {
                return $p;
            }
        }
        return null;
    }
}
