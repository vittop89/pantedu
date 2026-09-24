<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Services\Waf\EdgeContext;
use App\Support\ImprontaIp;

/**
 * IP e User-Agent della richiesta corrente, ridotti a impronta prima di
 * finire in un registro.
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
 *   32 byte grezzi in VARBINARY(32), la stessa forma di consent_audit,
 *   password_resets (lì in esadecimale) e audit_activity_log.ip_hash.
 *
 *   L'IP, dal 24/9/2026, è un'impronta con chiave: la calcola
 *   `App\Support\ImprontaIp` (HMAC-SHA256, chiave derivata con HKDF dal
 *   segreto del server). Fino a quel giorno qui c'era hash('sha256', $ip),
 *   e il commento diceva «dal registro non si risale all'IP»: era falso. Gli
 *   IPv4 sono circa 4,3 miliardi, e chi ha il registro li prova tutti in
 *   pochi secondi. Un IP noto resta confrontabile con il registro solo sul
 *   server, che ha la chiave (`tools/audit/impronta_ip.php`); resta un dato
 *   pseudonimo, non anonimo.
 *
 *   Lo User-Agent resta hash('sha256', valore, true), senza chiave: le
 *   stringhe comuni sono poche e si ritrovano confrontandole, quindi anche
 *   questa è un'impronta pseudonima, non anonima. Si tronca a 512 caratteri
 *   prima dell'hash, la stessa lunghezza a cui veniva troncato quando si
 *   conservava in chiaro.
 *
 *   Le righe scritte in chiaro prima del 3/9/2026 sono state convertite con
 *   lo SHA-256 senza chiave dalla migration 100; quelle scritte fino al
 *   24/9/2026 hanno lo stesso SHA-256. Restano come sono fino al termine
 *   della loro tabella (vedi ImprontaIp, «Le righe di prima»).
 *
 * QUALE IP (23/9/2026)
 *   Quello che decide EdgeContext, lo stesso del limitatore, dei blocchi per
 *   brute force e del WAF. Fino a oggi qui si prendeva `Client-IP`, poi il
 *   primo elemento di `X-Forwarded-For`, poi REMOTE_ADDR: i primi due li
 *   sceglie il client, e nginx li lascia passare. Chiunque poteva quindi far
 *   finire nel registro l'hash di un indirizzo a piacere — il proprio
 *   nascosto, o quello di un altro (revisione architetturale 2026-09, A-63).
 *   Gli header dei proxy li legge solo EdgeContext, e solo quando la
 *   connessione arriva da un proxy fidato. Le righe scritte prima restano
 *   come sono: un hash non si ricalcola senza l'indirizzo.
 */
final class RequestFingerprint
{
    public const UA_MAX_LEN = 512;

    /**
     * IP del client secondo EdgeContext, o null se non c'e' (da riga di
     * comando, o con un REMOTE_ADDR che non e' un indirizzo).
     */
    public static function clientIp(): ?string
    {
        $ip = EdgeContext::clientIp($_SERVER);
        return $ip === '0.0.0.0' ? null : $ip;
    }

    /**
     * Impronta con chiave (32 byte grezzi, ImprontaIp::di) dell'IP passato,
     * o di quello della richiesta corrente se non se ne passa nessuno.
     * Passare esplicitamente null significa "nessun IP": si ottiene null,
     * non quello corrente. Null anche se manca il segreto del server.
     */
    public static function ipHash(?string $ip = null): ?string
    {
        return ImprontaIp::di(\func_num_args() > 0 ? $ip : self::clientIp());
    }

    /**
     * Hash SHA-256 senza chiave (32 byte grezzi) dello User-Agent passato, o
     * di quello della richiesta corrente. Pseudonimo, non anonimo: vedi
     * CONVENZIONE.
     */
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
}
