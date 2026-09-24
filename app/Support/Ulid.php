<?php

namespace App\Support;

/**
 * ULID (Universally Unique Lexicographically Sortable Identifier).
 * 26-char Crockford base32: 10 char timestamp (ms) + 16 char random.
 * Implementazione autonoma — no package esterni.
 */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        $ms = (int)(microtime(true) * 1000);
        return self::encodeTime($ms) . self::encodeRandom();
    }

    private static function encodeTime(int $ms): string
    {
        $out = '';
        for ($i = 9; $i >= 0; $i--) {
            $out = self::ALPHABET[$ms & 31] . $out;
            $ms >>= 5;
        }
        return $out;
    }

    private static function encodeRandom(): string
    {
        $bytes = random_bytes(10);
        $out = '';
        // 80 bit in 16 chars da 5 bit ciascuno
        $buf = 0;
        $bits = 0;
        for ($i = 0; $i < 10; $i++) {
            $buf = ($buf << 8) | \ord($bytes[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= self::ALPHABET[($buf >> $bits) & 31];
            }
        }
        return $out;
    }
}
