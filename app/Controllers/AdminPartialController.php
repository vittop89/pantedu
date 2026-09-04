<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Support\BodyExtractor;

/**
 * Phase 25.E17 — partial admin (HTML/PHP) serving.
 *
 * Sostituisce LegacyController per i due endpoint partial usati attivamente
 * dal frontend JS:
 *
 *   GET /Elementi_Riservati.html       → views/admin/Elementi_Riservati.html
 *      (fetched da editor-system.js, table-manager.js, google-apps.js
 *      via $.load() e Api.fetchHtmlTemplate)
 *
 *   GET /modello_pag_listSidebar.php   → views/partials/sidebar_list.php
 *      (templateURL config in 5 sezioni: api-jquery.js, config.js, endpoints.js)
 *
 * Comportamento:
 *   - Static/normal request → serve raw file
 *   - SPA partial (X-Partial: 1) su HTML/PHP → buffer + extract <body>
 */
final class AdminPartialController
{
    private const PARTIALS = [
        'Elementi_Riservati.html'     => 'views/admin/Elementi_Riservati.html',
        'modello_pag_listSidebar.php' => 'views/partials/sidebar_list.php',
    ];

    public function show(Request $req, array $params): Response
    {
        unset($params);
        $name = ltrim($req->path, '/');
        $name = str_replace(["\0", '..'], '', $name);
        $relative = self::PARTIALS[$name] ?? null;
        if ($relative === null) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $base = (string)Config::get('app.paths.legacy');
        $absoluteBase = realpath($base);
        if ($absoluteBase === false) {
            return Response::html('<h1>500 Server misconfigured</h1>', 500);
        }
        $target = realpath($absoluteBase . DIRECTORY_SEPARATOR . $relative);
        if ($target === false || !str_starts_with($target, $absoluteBase) || !is_file($target)) {
            return Response::html('<h1>404 Not Found</h1>', 404);
        }

        $ext = strtolower((string)pathinfo($target, PATHINFO_EXTENSION));
        $wantsPartial = ($req->headers['x-partial'] ?? $req->headers['X-Partial'] ?? '') === '1';
        if ($wantsPartial && \in_array($ext, ['php', 'html', 'htm'], true)) {
            ob_start();
            if ($ext === 'php') {
                chdir(dirname($target));
                require $target;
            } else {
                readfile($target);
            }
            $body = BodyExtractor::extract((string)ob_get_clean());
            return new Response(
                body: $body,
                status: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return Response::file($target);
    }
}
