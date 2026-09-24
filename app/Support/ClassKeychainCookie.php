<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * «Ricorda su questo dispositivo» per il portachiavi di credenziali di classe
 * (piano classi-credenziali-scenari, C).
 *
 * Il cookie porta solo i riferimenti alle credenziali (gli id) e una
 * scadenza, firmati con il segreto dell'istanza: nessun dato personale,
 * nessuna password. Al ritorno il portachiavi si ricostruisce dal DB, quindi
 * una credenziale revocata o scaduta non rientra anche se il cookie la cita.
 *
 * Facoltativo e non spuntato per default: sui PC condivisi non succede nulla
 * se nessuno lo chiede. Dura quanto la credenziale (per default fino al 31
 * agosto): a fine anno cade da se'. Senza segreto configurato la funzione e'
 * spenta, e la pagina non offre la casella.
 */
final class ClassKeychainCookie
{
    public const NAME = 'fm_keychain';

    public static function enabled(): bool
    {
        return self::secret() !== '';
    }

    /**
     * Emette il cookie. Ritorna false se la funzione e' spenta o gli header
     * sono gia' partiti.
     *
     * @param list<int> $credentialIds
     */
    public static function issue(array $credentialIds, \DateTimeInterface $until): bool
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $credentialIds),
            static fn(int $i) => $i > 0
        )));
        if (!self::enabled() || $ids === [] || headers_sent()) {
            return false;
        }
        $exp = $until->getTimestamp();
        if ($exp <= time()) {
            return false;
        }
        return setcookie(self::NAME, self::payload($ids, $exp, self::secret()), self::options($exp));
    }

    /**
     * Gli id delle credenziali ricordate, o lista vuota se il cookie manca,
     * e' manomesso o scaduto.
     *
     * @return list<int>
     */
    public static function read(): array
    {
        if (!self::enabled()) {
            return [];
        }
        $raw = (string)($_COOKIE[self::NAME] ?? '');
        $parsed = $raw !== '' ? self::parse($raw, time(), self::secret()) : null;
        return $parsed['ids'] ?? [];
    }

    public static function clear(): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::NAME, '', self::options(time() - 86400));
        unset($_COOKIE[self::NAME]);
    }

    /**
     * Valore del cookie: base64url(json{ids,exp}) . '.' . hmac. Pura.
     *
     * @param list<int> $ids
     */
    public static function payload(array $ids, int $exp, string $secret): string
    {
        $body = self::b64UrlEncode((string)json_encode(['ids' => array_values($ids), 'exp' => $exp]));
        return $body . '.' . self::b64UrlEncode(hash_hmac('sha256', $body, $secret, true));
    }

    /**
     * Verifica firma e scadenza. Pura: null se il valore non e' valido.
     *
     * @return array{ids:list<int>,exp:int}|null
     */
    public static function parse(string $value, int $now, string $secret): ?array
    {
        if ($secret === '' || substr_count($value, '.') !== 1) {
            return null;
        }
        [$body, $sig] = explode('.', $value, 2);
        $expected = self::b64UrlEncode(hash_hmac('sha256', $body, $secret, true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = json_decode(self::b64UrlDecode($body), true);
        if (!\is_array($json) || !isset($json['ids'], $json['exp']) || !\is_array($json['ids'])) {
            return null;
        }
        $exp = (int)$json['exp'];
        if ($exp <= $now) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $json['ids']), static fn(int $i) => $i > 0)));
        return $ids === [] ? null : ['ids' => $ids, 'exp' => $exp];
    }

    /** @return array<string,mixed> */
    private static function options(int $expires): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    private static function secret(): string
    {
        try {
            return (string)Config::get('storage.signing_secret', '');
        } catch (\Throwable) {
            return '';
        }
    }

    private static function b64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function b64UrlDecode(string $s): string
    {
        $pad = strlen($s) % 4;
        return (string)base64_decode(strtr($s, '-_', '+/') . ($pad ? str_repeat('=', 4 - $pad) : ''), true);
    }
}
