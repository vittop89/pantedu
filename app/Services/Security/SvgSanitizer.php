<?php

declare(strict_types=1);

namespace App\Services\Security;

use enshrined\svgSanitize\Sanitizer;

/**
 * G24.phase2 — SVG sanitization per GeoGebra inline content.
 *
 * Wrapper su `enshrined/svg-sanitize`. Strip:
 *   - `<script>` (anche inside SVG)
 *   - `<foreignObject>` con HTML payload
 *   - `on*` event handlers (onload, onclick, ecc.)
 *   - `<use href="javascript:...">` / `<a xlink:href="javascript:...">`
 *   - Style con javascript: URI
 *
 * Preserva: tutti gli elementi SVG geometrici/typografici legittimi
 * (path, rect, circle, line, polygon, text, tspan, g, defs, clipPath,
 * filter primitives, gradients, ecc).
 *
 * Policy: server-side authoritative. SVG arriva da GeoGebra editor →
 * normalmente clean, ma teacher compromesso/malicious può injettare.
 *
 * Feature flag: condivisa con HtmlSanitizer (`XSS_SANITIZE_ENABLED`).
 */
final class SvgSanitizer
{
    private static ?Sanitizer $sanitizer = null;

    /** Sanitize SVG markup. Ritorna SVG pulito o stringa vuota se input
     *  malformato/non-SVG. */
    public static function sanitize(string $svg): string
    {
        if ($svg === '') {
            return '';
        }
        if (!self::isEnabled()) {
            return $svg;
        }

        $sanitizer = self::$sanitizer ??= self::buildSanitizer();
        $clean = $sanitizer->sanitize($svg);
        if (!is_string($clean)) {
            return '';
        }

        // Post-pass: enshrined/svg-sanitize NON inspeziona CSS dentro `style`
        // attribute. Strip pattern `javascript:` / `expression(` / `url(...js...)`
        // dagli style. Pattern conservativo (rimuove l'intero attribute style
        // se contiene js).
        $clean = preg_replace_callback(
            '/\sstyle\s*=\s*(["\'])(.*?)\1/is',
            static function ($m): string {
                $val = strtolower((string)$m[2]);
                if (
                    str_contains($val, 'javascript:')
                    || str_contains($val, 'expression(')
                    || str_contains($val, 'vbscript:')
                    || preg_match('/url\s*\(\s*["\']?\s*(javascript|data):/i', $val)
                ) {
                    return ''; // strip intero attr style
                }
                return $m[0];
            },
            $clean
        ) ?? $clean;

        return $clean;
    }

    private static function isEnabled(): bool
    {
        $env = getenv('XSS_SANITIZE_ENABLED');
        if ($env === false) {
            return true;
        }
        return !in_array(strtolower((string)$env), ['0', 'false', 'off', 'no'], true);
    }

    private static function buildSanitizer(): Sanitizer
    {
        $sanitizer = new Sanitizer();
        // Minify: false (preserva struttura per debug); rimozione attr remote ok
        $sanitizer->minify(false);
        // RemoveRemoteReferences=true: strip elementi `<use href="http://...">`
        // che potrebbero leak info o caricare contenuto esterno.
        $sanitizer->removeRemoteReferences(true);
        return $sanitizer;
    }
}
