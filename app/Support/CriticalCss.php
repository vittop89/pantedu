<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CriticalCss — inline above-the-fold styles in <head>.
 *
 * Phase Roadmap 9. Su Slow 3G inlining critical CSS riduce FCP -1.5s
 * (no roundtrip per il primo paint).
 *
 * Strategia:
 *   1. css/critical.css è statico, < 14 KB (limite TCP slow-start
 *      initial congestion window per HTTP/2).
 *   2. main.css resta come <link> con `media="print" onload=…`
 *      per async load (prevent render-blocking).
 *   3. <noscript> fallback per disabled-JS.
 *
 * Cache: in-memory per request (PHP-FPM worker hot path).
 *
 * Usage in head.php:
 *   echo \App\Support\CriticalCss::inline();
 */
final class CriticalCss
{
    private static ?string $cached = null;

    private const FILE = __DIR__ . '/../../css/critical.css';

    /**
     * Renderizza <style>…</style> inline + preload async per main.css.
     */
    public static function inline(): string
    {
        $css = self::read();
        // 2026-05-24 fix: prefer main.bundle.css se esiste (generato da
        // tools/build-css-bundle.php al deploy) — 1 file vs 38+ @import
        // nested, 1 cache-bust automatic via filemtime. Fallback main.css
        // con @import (dev senza build). Senza questo fix, attivare
        // FM_CRITICAL_CSS=1 forzava caricamento di main.css (no bundle)
        // → 38 HTTP request CSS + nessuno bundle perf gain.
        $bundleRel = '/css/main.bundle.css';
        $bundleAbs = __DIR__ . '/../../css/main.bundle.css';
        $useBundle = is_file($bundleAbs);
        $main = $useBundle ? $bundleRel : '/css/main.css';
        $mainAbs = __DIR__ . '/../..' . $main;

        if ($css === null) {
            return '<link rel="stylesheet" href="' . $main . '">';
        }

        // CSP nonce hook (se app espone nonce in request scope)
        $nonce = self::cspNonce();
        $nonceAttr = $nonce !== null ? ' nonce="' . htmlspecialchars($nonce, ENT_QUOTES) . '"' : '';

        $cacheBust = is_file($mainAbs) ? (string) filemtime($mainAbs) : '1';

        // 2026-05-24 fix v2: pattern dinamico CF-aware.
        // - SE via Cloudflare (HTTP_CF_RAY presente): public/index.php emette
        //   `Link: <bundle>; rel=preload` HTTP header (Early Hints 103). Allora
        //   usiamo `<link rel="stylesheet">` normale che consuma il preload.
        // - SE no CF (local XAMPP, direct VPS): main.bundle.css è render-block.
        //   Usiamo `<link rel="preload"...onload=swap>` per async + noscript fallback.
        //   Lighthouse perf win LCP -1500-2500ms su mobile/3G.
        $viaCf = !empty($_SERVER['HTTP_CF_RAY']);
        if ($viaCf) {
            return <<<HTML
<style{$nonceAttr}>{$css}</style>
<link rel="stylesheet" href="{$main}?v={$cacheBust}">
HTML;
        }
        // Async swap pattern (no-CF context)
        return <<<HTML
<style{$nonceAttr}>{$css}</style>
<link rel="preload" as="style" href="{$main}?v={$cacheBust}" data-fm-css="preload">
<script>(function(l){l&&l.addEventListener("load",function(){this.onload=null;this.rel="stylesheet"},{once:true})})(document.currentScript.previousElementSibling)</script>
<noscript><link rel="stylesheet" href="{$main}?v={$cacheBust}"></noscript>
HTML;
    }

    /**
     * Restituisce solo il contenuto critical.css (per debug/test).
     */
    public static function read(): ?string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        if (!is_file(self::FILE)) {
            return null;
        }
        $raw = file_get_contents(self::FILE);
        if ($raw === false) {
            return null;
        }
        self::$cached = self::minify($raw);
        return self::$cached;
    }

    /**
     * Minify CSS in-place (no deps). Sufficient per static critical.css.
     * Non gestisce edge case complessi (es. data URIs con commenti).
     */
    private static function minify(string $css): string
    {
        // Remove comments
        $css = (string) preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        // Collapse whitespace
        $css = (string) preg_replace('/\s+/', ' ', $css);
        // Trim around punct
        $css = (string) preg_replace('/\s*([{};:,>+~])\s*/', '$1', $css);
        // Remove last ; before }
        $css = (string) preg_replace('/;}/', '}', $css);
        return trim($css);
    }

    private static function cspNonce(): ?string
    {
        $nonce = $_SERVER['HTTP_X_CSP_NONCE'] ?? null;
        if (\is_string($nonce) && preg_match('/^[A-Za-z0-9+\/=]{8,}$/', $nonce)) {
            return $nonce;
        }
        return null;
    }

    // cacheBuster() rimosso 2026-05-24: era private + non più chiamato
    // (inline() ora usa filemtime($mainAbs) direttamente per supportare
    // bundle vs main.css scelta dinamica).
}
