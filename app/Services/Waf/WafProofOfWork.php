<?php

declare(strict_types=1);

namespace App\Services\Waf;

/**
 * WAF Proof-of-Work — challenge computazionale stateless (hashcash).
 *
 * Sostituisce il vecchio "JS-eval banale": prima il vecchio sistema chiedeva
 * solo di eseguire del JS e rispedire un fingerprint auto-dichiarato. Un bot
 * non doveva *risolvere* nulla. Con il PoW il client deve trovare un `nonce`
 * tale che `sha256(prefix . nonce)` abbia almeno N bit iniziali a zero —
 * lavoro misurabile (centinaia di ms) che rende costoso lo scraping di massa,
 * pur restando invisibile e accessibile (niente CAPTCHA, WCAG-safe).
 *
 * Tutto stateless e firmato HMAC: il server emette `prefix|bits|ts` firmati,
 * il client risolve e rispedisce `challenge + nonce`, il server riverifica
 * firma, freschezza e difficoltà. Nessuno storage server-side.
 */
final class WafProofOfWork
{
    public function __construct(
        private readonly string $secret,
        private readonly int $maxAgeSeconds = 300,
    ) {
    }

    /**
     * Emette una challenge PoW firmata.
     *
     * @return array{token:string, prefix:string, bits:int}
     */
    public function issue(int $bits): array
    {
        $bits   = max(1, min(24, $bits));
        $prefix = bin2hex(random_bytes(8));
        $payload = ['p' => $prefix, 'b' => $bits, 't' => time()];
        $token   = $this->sign($payload);
        return ['token' => $token, 'prefix' => $prefix, 'bits' => $bits];
    }

    /**
     * Verifica una soluzione PoW.
     *
     * @param string $token Token emesso da issue() (prefix|bits|ts firmati).
     * @param string $nonce Soluzione trovata dal client.
     */
    public function verify(string $token, string $nonce): bool
    {
        if ($token === '' || $nonce === '' || strlen($nonce) > 64) {
            return false;
        }
        $payload = $this->unsign($token);
        if ($payload === null) {
            return false;
        }
        $prefix = (string)($payload['p'] ?? '');
        $bits   = (int)($payload['b'] ?? 0);
        $ts     = (int)($payload['t'] ?? 0);
        if ($prefix === '' || $bits < 1) {
            return false;
        }
        // Freschezza: challenge non riutilizzabile oltre la finestra.
        if ($ts <= 0 || ($ts + $this->maxAgeSeconds) < time() || $ts > time() + 60) {
            return false;
        }
        $digest = hash('sha256', $prefix . $nonce, true);
        return self::leadingZeroBits($digest) >= $bits;
    }

    /**
     * Conta i bit iniziali a zero di un digest binario.
     */
    public static function leadingZeroBits(string $binary): int
    {
        $count = 0;
        $len = strlen($binary);
        for ($i = 0; $i < $len; $i++) {
            $byte = ord($binary[$i]);
            if ($byte === 0) {
                $count += 8;
                continue;
            }
            // Conta gli zeri iniziali nel byte non nullo.
            for ($b = 7; $b >= 0; $b--) {
                if (($byte >> $b) & 1) {
                    return $count;
                }
                $count++;
            }
            return $count;
        }
        return $count;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $b64  = $this->b64url($json);
        $sig  = $this->b64url(hash_hmac('sha256', $b64, $this->secret, true));
        return $b64 . '.' . $sig;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function unsign(string $token): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        [$b64, $sig] = $parts;
        $expected = hash_hmac('sha256', $b64, $this->secret, true);
        $provided = $this->b64urlDecode($sig);
        if ($provided === false || !hash_equals($expected, $provided)) {
            return null;
        }
        $json = $this->b64urlDecode($b64);
        if ($json === false) {
            return null;
        }
        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private function b64url(string $data): string
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
