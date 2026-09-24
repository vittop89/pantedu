<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Serve i due JSON dei modelli TikZ letti dall'editor:
 *
 *   GET /modelli_tikz_elements.json → <PANTEDU_DATA_PATH>/storage/data/modelli_tikz_elements.json
 *   GET /modelli_tikz_traccia.json  → <PANTEDU_DATA_PATH>/storage/data/modelli_tikz_traccia.json
 *
 * Sono dati d'istanza (fuori da git): l'indice degli elementi lo scrive
 * `TikzElementsService` dal CRUD admin, le tracce sono statiche. Fino al
 * 2026-09-05 il controller leggeva dalla radice del repo, dove in
 * produzione i file non ci sono (404 silenzioso e menù TeX senza modelli).
 *
 * Cache: ETag con `max-age=0, must-revalidate`, così una modifica admin è
 * visibile subito e il browser paga solo un 304 quando nulla è cambiato.
 * `/modelli_tikz.json` (copia stantia dell'indice generata dal vecchio
 * `ensure-json`) non esiste più.
 */
final class TikzDataController
{
    private const ALLOWED_FILES = [
        'modelli_tikz_elements.json',
        'modelli_tikz_traccia.json',
    ];

    public function show(Request $req, array $params): Response
    {
        unset($params);

        $name = ltrim($req->path, '/');
        $name = str_replace(["\0", '..', '/'], '', $name);
        if (!\in_array($name, self::ALLOWED_FILES, true)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $dataDir = realpath((string) Config::get('app.paths.storage') . DIRECTORY_SEPARATOR . 'data');
        if ($dataDir === false) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $target = realpath($dataDir . DIRECTORY_SEPARATOR . $name);
        if ($target === false || !str_starts_with($target, $dataDir) || !is_file($target)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        $stat  = @stat($target);
        $token = $stat ? \sprintf('%d-%d', $stat['mtime'], $stat['size']) : (string) time();
        return Response::file($target)->withETag($token, 0);
    }
}
