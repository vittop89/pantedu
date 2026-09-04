<?php

namespace App\Support;

/**
 * Estrae il contenuto di <body>...</body> da un documento HTML
 * completo. Usato dai partial-aware controller (AdminPartialController,
 * LogServeController) quando il client richiede la pagina come partial
 * (header X-Partial: 1): la legacy page continua a rendersi come pagina
 * intera — lo stripping avviene sull'output prima che raggiunga il browser.
 *
 * Se la sorgente non ha un <body> (fragment-style), viene
 * restituita immutata.
 */
final class BodyExtractor
{
    public static function extract(string $html): string
    {
        // Match <body[ ...]> ... </body>. Prefer regex to DOMDocument:
        // DOM would re-serialize entities and mangle MathJax/TikZ scripts.
        if (preg_match('#<body[^>]*>(.*)</body\s*>#si', $html, $m)) {
            return trim($m[1]);
        }
        // Fragment: html starts after some head-like noise — trim leading DOCTYPE/html/head if present
        $html = preg_replace('#^\s*<!doctype[^>]*>#i', '', $html, 1) ?? $html;
        $html = preg_replace('#<html[^>]*>|</html\s*>#i', '', $html) ?? $html;
        $html = preg_replace('#<head[^>]*>.*?</head\s*>#si', '', $html) ?? $html;
        return trim($html);
    }

    /**
     * Pulls <script> and <link rel="stylesheet"> tags from the <head>
     * so the SPA router can inject them after the body swap.
     *
     * @return array{0: string, 1: list<string>}
     *         [bodyHtml, assetTags]
     */
    public static function extractWithAssets(string $html): array
    {
        $assets = [];
        if (preg_match('#<head[^>]*>(.*?)</head\s*>#si', $html, $h)) {
            if (
                preg_match_all(
                    '#<(?:script[^>]*>.*?</script\s*>|link[^>]+/?\s*>)#si',
                    $h[1],
                    $matches
                )
            ) {
                $assets = $matches[0];
            }
        }
        return [self::extract($html), $assets];
    }
}
