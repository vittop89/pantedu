<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * IP e User-Agent della richiesta corrente, ridotti a hash prima di finire
 * in un registro.
 *
 * PERCHE' ESISTE (2026-09-03)
 *   Informativa, registro art. 30 e DPIA dichiaravano che nei registri di
 *   audit IP e User-Agent restano solo come hash. Lo faceva ActivityLogger,
 *   per il solo IP; ContentActionLogger, PrivilegedAccessLogger e
 *   TeacherRecoveryService scrivevano entrambi in chiaro, e nessuno scriveva
 *   lo User-Agent come hash. Quattro logger, quattro copie della stessa
 *   logica, tre sbagliate: da qui in poi la logica e' una sola.
 *
 * CONVENZIONE
 *   hash('sha256', valore, true): 32 byte grezzi in VARBINARY(32), la stessa
 *   di consent_audit, password_resets e audit_activity_log.ip_hash. Un IP
 *   noto resta confrontabile con il registro (lo si hasha e lo si cerca); dal
 *   registro non si risale all'IP. Per l'IP si prende il primo elemento di
 *   un eventuale elenco X-Forwarded-For: e' il client, gli altri sono proxy.
 *   Lo User-Agent si tronca a 512 caratteri prima dell'hash, la stessa
 *   lunghezza a cui veniva troncato quando si conservava in chiaro.
 *
 *   Le righe scritte in chiaro prima di questa data sono state convertite
 *   con la stessa formula dalla migration 100.
 */
final class RequestFingerprint
{
    public const UA_MAX_LEN = 512;

    /** IP del client come lo vede l'applicazione, o null se non c'e'. */
    public static function clientIp(): ?string
    {
        $ip = $_SERVER['HTTP_CLIENT_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $_SERVER['REMOTE_ADDR']
            ?? null;
        return self::firstIp(\is_string($ip) ? $ip : null);
    }

    /**
     * Hash SHA-256 (32 byte grezzi) dell'IP passato, o di quello della
     * richiesta corrente se non se ne passa nessuno. Passare esplicitamente
     * null significa "nessun IP": si ottiene null, non quello corrente.
     */
    public static function ipHash(?string $ip = null): ?string
    {
        $ip = \func_num_args() > 0 ? self::firstIp($ip) : self::clientIp();
        if ($ip === null) {
            return null;
        }
        return \hash('sha256', $ip, true);
    }

    /** Come ipHash(), per lo User-Agent. */
    public static function uaHash(?string $ua = null): ?string
    {
        if (\func_num_args() === 0) {
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        }
        if (!\is_string($ua) || $ua === '') {
            return null;
        }
        return \hash('sha256', \substr($ua, 0, self::UA_MAX_LEN), true);
    }

    /** Primo elemento di un elenco X-Forwarded-For, ripulito; null se vuoto o 'unknown'. */
    private static function firstIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }
        $ip = \trim(\explode(',', $ip)[0]);
        return ($ip === '' || $ip === 'unknown') ? null : $ip;
    }
}
