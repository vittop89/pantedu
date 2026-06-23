<?php

declare(strict_types=1);

namespace App\Services\Security;

/**
 * G24.phase3 — Validation/sanitization per body di `<script type="text/tikz">`.
 *
 * Il body è TeX/TikZ source (es. `\begin{tikzpicture}...\end{tikzpicture}`).
 * Browser non lo esegue come JS (type="text/tikz" non riconosciuto), MA un
 * attaccante può chiudere il tag con `</script>` letterale e iniettare
 * codice JS reale:
 *
 *   <script type="text/tikz">
 *     \begin{tikzpicture}...</script><script>alert(1)</script>
 *   </script>
 *
 * Quando il browser parsa il primo `</script>`, chiude il blocco TikZ. Il
 * successivo `<script>` SENZA type è quindi eseguito come JS.
 *
 * Strategia: escape `</` in `<\/` nel body (innocuo per TeX, blocca il
 * browser dal chiudere prematuramente lo script).
 *
 * Validation: opzionale `validate()` throwa eccezione se body contiene
 * pattern sospetto (utile per save-time hard reject).
 */
final class TikzScriptValidator
{
    /**
     * Sanitize body TikZ: escape `</` → `<\/`. Idempotente.
     * Sicuro per TeX (slash escape è "<\/" che TeX legge come `<` + comando
     * di "\\/" inutilizzato, ma TikZ non parsa `<` come comando).
     */
    public static function sanitize(string $body): string
    {
        if ($body === '') {
            return '';
        }
        if (!self::isEnabled()) {
            return $body;
        }
        // Escape `</` literal (preserve già-escaped `<\/`)
        return preg_replace('#</(?!\\\\)#', '<\\/', $body) ?? $body;
    }

    /**
     * Validate: throws RuntimeException se body contiene pattern sospetto.
     * Da chiamare a SAVE time per hard reject prima di storing.
     */
    public static function validate(string $body): void
    {
        if (!self::isEnabled()) {
            return;
        }
        // Hard reject pattern non-escaped `</script>`
        if (preg_match('#</\s*script\b#i', $body)) {
            throw new \RuntimeException('TikZ body contiene </script> non escapato (XSS injection vector)');
        }
    }

    private static function isEnabled(): bool
    {
        $env = getenv('XSS_SANITIZE_ENABLED');
        if ($env === false) {
            return true;
        }
        return !in_array(strtolower((string)$env), ['0', 'false', 'off', 'no'], true);
    }
}
