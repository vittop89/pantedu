<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/**
 * Phase 19 — probe endpoint no-op per health check + test e2e CSRF/rate.
 * Richiede CSRF + rate middleware; ritorna sempre 200 con echo ricevuto.
 *
 * Sostituisce l'endpoint legacy `/links/check-variation` come bersaglio
 * per test CSRF retry + rate limit.
 */
final class CsrfProbeController
{
    /** POST /api/probe */
    public function probe(Request $req): Response
    {
        return Response::json([
            'ok' => true,
            'received' => \array_keys($req->post ?? []),
            'ts' => \time(),
        ]);
    }
}
