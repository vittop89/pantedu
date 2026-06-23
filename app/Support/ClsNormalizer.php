<?php

declare(strict_types=1);

namespace App\Support;

/**
 * G19.8 — Bidirectional normalizer for class codes.
 *
 * Background: prior to G19.8 the curriculum codes were "1s"/"2s"/"3s"/"4s"/"5s"
 * (Standard) and "1b"/"2b"/"3b"/"4b" (Breve, partially used). Existing
 * data (DB rows in `users.classe`, `print_info.classe`, file paths
 * like `eser/sc/eser_sc2s/...`, URLs `/studio/ar/2s/MAT/topic`) all
 * use the LEGACY form.
 *
 * G19.8 changes the dropdown to expose SHORT codes ("1"/"2"/"3"/"4"/"5"),
 * mergeing Standard + Breve under the same number. To avoid breaking
 * existing URLs/data, this helper normalizes both forms at boundary:
 *
 *   - `expand("2")`   → "2s"  (short → legacy: for DB queries, file paths)
 *   - `expand("2s")`  → "2s"  (idempotent on legacy)
 *   - `shrink("2s")`  → "2"   (legacy → short: for new dropdown display)
 *   - `shrink("2")`   → "2"   (idempotent on short)
 *
 * Strategy: the source-of-truth REMAINS "2s" (legacy) inside DB / files.
 * The frontend just shows "2" to the user. URL accepts both forms; the
 * controller `expand()`s the cls before any DB / file lookup. This way
 * existing bookmarks `/studio/ar/2s/MAT/topic` keep working AND new
 * URLs `/studio/ar/2/MAT/topic` work too.
 */
final class ClsNormalizer
{
    /** Numeric classes that are exposed in the new short curriculum. */
    private const NUMERIC = ['1', '2', '3', '4', '5'];

    /**
     * Expand short → legacy (default `'s'` suffix).
     * "1" → "1s", "1s" → "1s" (idempotent), "1b" → "1b" (preserved).
     */
    public static function expand(string $cls): string
    {
        $c = trim($cls);
        if (\in_array($c, self::NUMERIC, true)) {
            return $c . 's';
        }
        return $c;
    }

    /**
     * Shrink legacy → short, dropping the suffix `s`/`b`.
     * "2s" → "2", "2b" → "2", "2" → "2" (idempotent).
     * Used per la display nel dropdown e per i nuovi URL puliti.
     */
    public static function shrink(string $cls): string
    {
        $c = trim($cls);
        if (preg_match('/^([1-5])(s|b)$/', $c, $m)) {
            return $m[1];
        }
        return $c;
    }

    /**
     * Equivalence check: "2" and "2s" considered equal (same class).
     */
    public static function equals(string $a, string $b): bool
    {
        return self::shrink($a) === self::shrink($b);
    }
}
