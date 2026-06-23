<?php

declare(strict_types=1);

namespace App\Services\Security;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * G24.phase1 — XSS sanitization layer per content block HTML inline.
 *
 * Wrapper su HTMLPurifier configurato con allowlist conservativa per il
 * dominio pantedu (editor didattico). Mira a chiudere il vector XSS
 * identificato nell'audit G23:
 *   - `<a href="javascript:...">` → href stripped
 *   - `<span onclick="...">` → handler stripped
 *   - `<span style="background:url(javascript:)">` → CSS expression stripped
 *   - `<script>` / `<iframe>` / `<object>` → fully stripped
 *
 * Allowlist (text block inline format):
 *   - Tag: b, strong, i, em, u, s, sub, sup, a, span, br
 *   - Attr `<a>`: href (solo http/https/mailto)
 *   - Attr `<span>`: style (solo color/background-color/font-weight/font-style), class
 *   - CSS: NO `expression()`, `url()` con js:, etc.
 *
 * Policy: server-side authoritative. Client-side è UX hint, no security
 * boundary (cfr. defense-in-depth in `js/modules/security/html-sanitize-client.js`).
 *
 * Performance: HTMLPurifier_Config con definitions cache filesystem
 * (`storage/htmlpurifier-cache/`) per evitare schema rebuild ad ogni call.
 *
 * Feature flag (rollback): `Config::get("security.xss_sanitize", true)`.
 * Se disabilitato, ritorna input AS-IS (no sanitization). Usare SOLO per
 * debug emergenza in produzione.
 */
final class HtmlSanitizer
{
    private static ?HTMLPurifier $purifierBlockContent = null;
    private static ?HTMLPurifier $purifierStrict = null;
    private static ?HTMLPurifier $purifierPageDoc = null;

    /**
     * Sanitize per block content (text type con inline HTML tag).
     * Allowlist: b/strong/i/em/u/s/sub/sup/a/span/br + safe href/style.
     */
    public static function forBlockContent(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (!self::isEnabled()) {
            return $html;
        }

        $purifier = self::$purifierBlockContent ??= self::buildPurifier(self::buildBlockContentConfig());
        return $purifier->purify($html);
    }

    /**
     * G23 page-doc — sanitize per `staticContent` block (HTML strutturato:
     * heading h2-h4, paragraph, list, blockquote, code, link).
     *
     * Whitelist più ampia di forBlockContent (che è solo inline) per
     * supportare contenuti documentali stile legislazione/linee-guida.
     * Reuse stesso HTMLPurifier wrapper + cache (no nuova dep).
     */
    public static function forPageDoc(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (!self::isEnabled()) {
            return $html;
        }

        $purifier = self::$purifierPageDoc ??= self::buildPurifier(self::buildPageDocConfig());
        return $purifier->purify($html);
    }

    /**
     * Sanitize strict (no inline HTML, solo plain text).
     * Usato per badge/titolo/categoria_label dove markup HTML non è atteso.
     */
    public static function forStrictText(string $html): string
    {
        if ($html === '') {
            return '';
        }
        if (!self::isEnabled()) {
            return $html;
        }

        $purifier = self::$purifierStrict ??= self::buildPurifier(self::buildStrictConfig());
        return $purifier->purify($html);
    }

    /** Feature flag — env override per rollback istantaneo in caso regressione. */
    private static function isEnabled(): bool
    {
        // Default ON. Disabilita SOLO via env XSS_SANITIZE_ENABLED=false per debug.
        $env = getenv('XSS_SANITIZE_ENABLED');
        if ($env === false) {
            return true;
        }
        return !in_array(strtolower((string)$env), ['0', 'false', 'off', 'no'], true);
    }

    private static function buildPurifier(HTMLPurifier_Config $config): HTMLPurifier
    {
        return new HTMLPurifier($config);
    }

    private static function buildBlockContentConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();

        // Cache definitions in storage (evita schema rebuild ad ogni call).
        $cacheDir = self::cacheDir();
        if ($cacheDir !== null) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            // Fallback: disabilita cache filesystem (in-memory solo)
            $config->set('Cache.DefinitionImpl', null);
        }

        // Allowlist tag inline format + attributi sicuri.
        $config->set('HTML.Allowed', 'b,strong,i,em,u,s,sub,sup,a[href|title],span[style|class],br');

        // URI schemes: solo http/https/mailto. NO javascript: / data: / file: / vbscript:
        $config->set('URI.AllowedSchemes', [
            'http'   => true,
            'https'  => true,
            'mailto' => true,
        ]);
        $config->set('URI.DisableExternalResources', false); // permettiamo link esterni (libri/risorse)
        $config->set('URI.DisableResources', false);

        // CSS properties: solo styling tipografico safe.
        $config->set('CSS.AllowedProperties', [
            'color',
            'background-color',
            'font-weight',
            'font-style',
            'text-decoration',
        ]);

        // Security flags
        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.SafeObject', false);
        $config->set('HTML.SafeEmbed', false);
        $config->set('HTML.Trusted', false);

        // Auto-paragraph OFF (preserve inline content as-is).
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', false);

        // Targets per <a>: aggiungi `target="_blank" rel="noopener noreferrer"` per safety
        $config->set('HTML.TargetBlank', false); // l'editor non aggiunge target; mantieni semantica originale
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);

        return $config;
    }

    /**
     * G23 page-doc — whitelist tag per pagine informative.
     * Block: h2/h3/h4, p, ul/ol/li, blockquote, pre, hr.
     * Inline: a/strong/em/code/br/span.
     * No iframe/script/style/object/embed/svg.
     */
    private static function buildPageDocConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $cacheDir = self::cacheDir();
        if ($cacheDir !== null) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }

        $config->set(
            'HTML.Allowed',
            'h2,h3,h4,p,ul,ol,li,blockquote,pre,hr,br,'
            . 'a[href|title|target|rel],strong,em,code,span[class]'
        );

        $config->set('URI.AllowedSchemes', [
            'http'   => true,
            'https'  => true,
            'mailto' => true,
        ]);
        // Allow site-relative paths for legacy /strcomp_bes_altro/... PDF links
        $config->set('URI.Base', '/');
        $config->set('URI.MakeAbsolute', false);

        $config->set('HTML.SafeIframe', false);
        $config->set('HTML.SafeObject', false);
        $config->set('HTML.SafeEmbed', false);
        $config->set('HTML.Trusted', false);

        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('AutoFormat.RemoveEmpty', true);

        // target="_blank" + rel="noopener noreferrer" auto-injection
        $config->set('Attr.AllowedRel', ['noopener', 'noreferrer', 'nofollow']);
        // HTMLPurifier auto-aggiunge rel=noreferrer su target=_blank quando true
        $config->set('HTML.TargetBlank', false); // preservare semantica originale

        return $config;
    }

    private static function buildStrictConfig(): HTMLPurifier_Config
    {
        $config = HTMLPurifier_Config::createDefault();
        $cacheDir = self::cacheDir();
        if ($cacheDir !== null) {
            $config->set('Cache.SerializerPath', $cacheDir);
        } else {
            $config->set('Cache.DefinitionImpl', null);
        }
        // Strict: solo plain text (tutto markup strippato)
        $config->set('HTML.Allowed', '');
        return $config;
    }

    private static function cacheDir(): ?string
    {
        $base = dirname(__DIR__, 3) . '/storage/htmlpurifier-cache';
        if (!is_dir($base)) {
            // Tentativo creazione idempotente
            @mkdir($base, 0775, true);
        }
        return is_dir($base) && is_writable($base) ? $base : null;
    }
}
