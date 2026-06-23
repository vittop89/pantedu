<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * TOTP (Time-based One-Time Password) — RFC 6238.
 *
 * Implementazione self-contained, zero dipendenze esterne.
 * Compatibile con Google Authenticator, Authy, 1Password, Bitwarden, etc.
 *
 * Parametri standard:
 *   - Hash: SHA-1 (RFC 6238 default — alcuni authenticator non supportano SHA-256)
 *   - Digits: 6
 *   - Period: 30s
 *   - Secret: 160-bit (20 byte) random, Base32-encoded
 *
 * Tolleranza temporale verify: ±1 step (±30s) per drift clock.
 */
final class TotpService
{
    private const DIGITS = 6;
    private const PERIOD = 30;
    private const ALG    = 'sha1';

    /**
     * Genera secret random 20-byte → Base32 string (32 char).
     */
    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(20));
    }

    /**
     * Calcola codice TOTP corrente per il secret dato.
     */
    public function generateCode(string $secret, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();
        $counter   = intdiv($timestamp, self::PERIOD);
        return $this->hotp($secret, $counter);
    }

    /**
     * Verifica codice utente contro secret, con tolleranza ±1 step.
     */
    public function verifyCode(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code);
        if ($code === null || !preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $timestamp = $timestamp ?? time();
        $counter   = intdiv($timestamp, self::PERIOD);
        // Tolleranza ±1 step per drift orologio (totale 90s window)
        for ($w = -1; $w <= 1; $w++) {
            if (hash_equals($this->hotp($secret, $counter + $w), $code)) {
                return true;
            }
        }
        return false;
    }

    /**
     * URI per QR code (otpauth://). Apre Google Authenticator/Authy/etc.
     *
     * @param string $secret        Base32 secret
     * @param string $accountName   user@domain (display nell'app)
     * @param string $issuer        Nome organizzazione (display nell'app)
     */
    public function provisioningUri(string $secret, string $accountName, string $issuer = 'Pantedu'): string
    {
        $label  = rawurlencode($issuer) . ':' . rawurlencode($accountName);
        $params = http_build_query([
            'secret'    => $secret,
            'issuer'    => $issuer,
            'algorithm' => 'SHA1',
            'digits'    => self::DIGITS,
            'period'    => self::PERIOD,
        ]);
        return "otpauth://totp/{$label}?{$params}";
    }

    /**
     * Genera N backup codes (hex 10 char). User li salva offline,
     * usabili 1 volta cadauno se perde il phone.
     *
     * @return list<string>
     */
    public function generateBackupCodes(int $count = 10): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5)); // 10 char hex
        }
        return $codes;
    }

    /**
     * Hash backup codes per storage (bcrypt cost=10 per speed).
     *
     * @param list<string> $codes
     * @return list<string> hashes
     */
    public function hashBackupCodes(array $codes): array
    {
        return array_map(
            static fn($c) => password_hash($c, PASSWORD_BCRYPT, ['cost' => 10]),
            $codes
        );
    }

    /**
     * Verifica backup code contro hashes salvati. Ritorna l'indice del
     * code matched (per consumarlo single-use) o -1.
     *
     * @param list<string> $hashes
     */
    public function verifyBackupCode(array $hashes, string $code): int
    {
        $code = strtolower(preg_replace('/\s+/', '', $code) ?? '');
        foreach ($hashes as $i => $hash) {
            if (password_verify($code, $hash)) {
                return $i;
            }
        }
        return -1;
    }

    // ───────── internal: HOTP (RFC 4226) ─────────

    private function hotp(string $base32Secret, int $counter): string
    {
        $key = $this->base32Decode($base32Secret);
        $bin = pack('N*', 0, $counter); // 8 byte big-endian counter
        $hmac = hash_hmac(self::ALG, $bin, $key, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;
        $code = (
            ((ord($hmac[$offset])     & 0x7F) << 24) |
            ((ord($hmac[$offset + 1]) & 0xFF) << 16) |
            ((ord($hmac[$offset + 2]) & 0xFF) << 8)  |
             (ord($hmac[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $bytes): string
    {
        static $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        for ($i = 0; $i < strlen($bytes); $i++) {
            $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i < strlen($bits); $i += 5) {
            $chunk = substr($bits, $i, 5);
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $out .= $alpha[bindec($chunk)];
        }
        return $out;
    }

    private function base32Decode(string $s): string
    {
        static $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $s = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $s) ?? '');
        $bits = '';
        for ($i = 0; $i < strlen($s); $i++) {
            $v = strpos($alpha, $s[$i]);
            if ($v === false) {
                continue;
            }
            $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
            $out .= chr(bindec(substr($bits, $i, 8)));
        }
        return $out;
    }
}
