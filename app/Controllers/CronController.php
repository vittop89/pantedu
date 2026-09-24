<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\FileService;

/**
 * Phase 25.E17 — Cron / localhost-only entrypoints.
 *
 *   ANY /delete_temp.php → svuota le radici `temp` e `verifiche_temp`.
 *
 * Fino al 2026-09-05 delegava a `api/files/delete_temp.php`, eseguito con
 * `require` da Response::serveFile(): quel ramo non esiste più e la logica
 * (tre righe di FileService) vive qui. La guardia resta: solo CLI o
 * REMOTE_ADDR ∈ {127.0.0.1, ::1}.
 */
final class CronController
{
    /** Cron localhost-only: delete temp files. */
    public function deleteTemp(Request $req, array $params): Response
    {
        unset($params);
        $remote = $req->server['REMOTE_ADDR'] ?? '';
        $isCli = PHP_SAPI === 'cli';
        $isLocalhost = \in_array($remote, ['127.0.0.1', '::1'], true);
        if (!$isCli && !$isLocalhost) {
            return Response::html('<h1>403 Forbidden — cron-only endpoint</h1>', 403);
        }

        $svc     = new FileService();
        $removed = $svc->clearRootContents('temp') + $svc->clearRootContents('verifiche_temp');
        return new Response("cleared=$removed", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
