<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.E17 — TIKZ data JSON serving.
 *
 * Sostituisce LegacyController per i 3 endpoint JSON di dati TikZ:
 *
 *   GET /modelli_tikz.json            → storage/data/modelli_tikz.json
 *   GET /modelli_tikz_elements.json   → storage/data/modelli_tikz_elements.json
 *   GET /modelli_tikz_traccia.json    → storage/data/modelli_tikz_traccia.json
 *
 * Fetched da js/modules/features/checkin-handlers.js (multipli punti).
 *
 * Cache: ETag + max-age 1h (i JSON cambiano raramente; consumer JS
 * mantiene il fetch ma 304 risparmia bandwidth).
 *
 * Path moderno per nuovi endpoint: `/api/tikz/data/{name}` — i 3 URL
 * legacy sopra sono mantenuti perché ancora referenziati da JS attivi.
 */
final class TikzDataController
{
    private const ALLOWED_FILES = [
        'modelli_tikz.json',
        'modelli_tikz_elements.json',
        'modelli_tikz_traccia.json',
    ];

    private const CACHE_MAX_AGE = 3600;

    public function show(Request $req, array $params): Response
    {
        unset($params);
        $name = ltrim($req->path, '/');
        $name = str_replace(["\0", '..', '/'], '', $name);
        if (!\in_array($name, self::ALLOWED_FILES, true)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $base = (string)Config::get('app.paths.legacy');
        $absoluteBase = realpath($base);
        if ($absoluteBase === false) {
            return Response::json(['error' => 'server_misconfigured'], 500);
        }
        $target = realpath($absoluteBase . DIRECTORY_SEPARATOR . 'storage/data' . DIRECTORY_SEPARATOR . $name);
        if ($target === false || !str_starts_with($target, $absoluteBase) || !is_file($target)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $stat = @stat($target);
        $token = $stat ? \sprintf('%d-%d', $stat['mtime'], $stat['size']) : (string)time();
        return Response::file($target)->withETag($token, self::CACHE_MAX_AGE);
    }
}
