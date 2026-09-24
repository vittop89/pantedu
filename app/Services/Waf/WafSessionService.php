<?php

declare(strict_types=1);

namespace App\Services\Waf;

/**
 * WAF Session Token Service
 *
 * Implementa cookie HMAC-firmato `waf_session` come da prompt
 * docs/todo/waf_security_prompt.md Parte 2 (porting Lua → PHP).
 *
 * Formato cookie: base64url(payload_json) "." base64url(hmac_sha256)
 *
 * Payload:
 *   { score: int, ip: string, ts: int, challenge: "pass"|"soft"|"block" }
 *
 * Verifica:
 *   1. Decodifica base64url + split su "."
 *   2. Verifica HMAC-SHA256 con secret key (timing-safe)
 *   3. Controlla scadenza (ts + ttl > now)
 *   4. Verifica IP corrispondente (anti session-hijacking)
 */
final class WafSessionService
{
    public function __construct(
        private readonly string $secretKey,
        private readonly int $ttlSeconds = 3600,
    ) {
        if (strlen($this->secretKey) < 32) {
            throw new \InvalidArgumentException('WAF HMAC secret key must be >= 32 bytes');
        }
    }

    /**
     * Crea token di sessione firmato HMAC.
     *
     * @param string $uaHash Hash stabile dello User-Agent (binding anti-replay
     *                       cross-client). Vuoto = nessun binding UA (legacy).
     * @return string Cookie value pronto per Set-Cookie.
     */
    public function createToken(int $score, string $ip, string $challenge, string $uaHash = ''): string
    {
        $payload = [
            'score'     => $score,
            'ip'        => $ip,
            'ts'        => time(),
            'challenge' => $challenge,
        ];
        if ($uaHash !== '') {
            // 'u' = binding UA; 'n' = nonce (entropia per token unici).
            $payload['u'] = $uaHash;
            $payload['n'] = bin2hex(random_bytes(8));
        }
        $payloadJson  = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadB64   = $this->b64urlEncode($payloadJson);
        $sig          = hash_hmac('sha256', $payloadB64, $this->secretKey, true);
        $sigB64       = $this->b64urlEncode($sig);
        return $payloadB64 . '.' . $sigB64;
    }

    /**
     * Verifica token e ritorna payload se valido + IP match.
     *
     * @param string $uaHash Hash UA corrente: se il token ne contiene uno e
     *                       non combacia → invalido (anti session-replay).
     * @return array{score:int, ip:string, ts:int, challenge:string}|null null se invalido/scaduto/ip mismatch
     */
    public function verifyToken(string $cookie, string $currentIp, string $uaHash = ''): ?array
    {
        if ($cookie === '') {
            return null;
        }
        $parts = explode('.', $cookie, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$payloadB64, $sigB64] = $parts;

        // Timing-safe HMAC verify
        $expectedSig = hash_hmac('sha256', $payloadB64, $this->secretKey, true);
        $providedSig = $this->b64urlDecode($sigB64);
        if ($providedSig === false || !hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        $payloadJson = $this->b64urlDecode($payloadB64);
        if ($payloadJson === false) {
            return null;
        }
        try {
            $payload = json_decode($payloadJson, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($payload)) {
            return null;
        }

        // Required fields
        foreach (['score', 'ip', 'ts', 'challenge'] as $k) {
            if (!array_key_exists($k, $payload)) {
                return null;
            }
        }

        // Expiry check
        $ts = (int)$payload['ts'];
        if ($ts + $this->ttlSeconds < time()) {
            return null;
        }

        // IP binding (anti session-hijacking)
        if ((string)$payload['ip'] !== $currentIp) {
            return null;
        }

        // UA binding (anti session-replay cross-client): se il token è stato
        // emesso con binding UA, deve combaciare con lo UA corrente.
        if (isset($payload['u']) && (string)$payload['u'] !== $uaHash) {
            return null;
        }

        return [
            'score'     => (int)$payload['score'],
            'ip'        => (string)$payload['ip'],
            'ts'        => $ts,
            'challenge' => (string)$payload['challenge'],
        ];
    }

    /**
     * Set-Cookie header value RFC-compliant per token WAF.
     */
    public function buildSetCookieHeader(string $token, bool $secure = true): string
    {
        $maxAge = $this->ttlSeconds;
        $parts = [
            "waf_session={$token}",
            'Path=/',
            "Max-Age={$maxAge}",
            'HttpOnly',
            'SameSite=Strict',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }
        return implode('; ', $parts);
    }

    /**
     * Hash stabile dello User-Agent per il binding del token.
     * Lo UA è costante per uno stesso browser → usabile come fattore di
     * binding senza rcompromettere l'usabilità.
     */
    public static function uaHash(string $userAgent): string
    {
        return substr(hash('sha256', trim($userAgent)), 0, 24);
    }

    private function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return string|false */
    private function b64urlDecode(string $data): string|false
    {
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }
        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
