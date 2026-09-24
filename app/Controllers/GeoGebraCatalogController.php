<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\GeoGebra\GeoGebraCatalogService;
use Throwable;

/**
 * G22.S15.bis Fase 4 — REST endpoints per il catalogo personale GeoGebra.
 *
 * Routes:
 *   GET  /geogebra/catalog          → list "leggera" (no ggb_b64) per UI
 *   GET  /geogebra/catalog/{id}     → item completo con ggb_b64 (per ricarica editor)
 *   POST /geogebra/catalog/save     → upsert (id?, label, ggb_b64, svg_cached?)
 *   POST /geogebra/catalog/delete   → elimina (id)
 *
 * Auth: utente loggato (catalogo personale scoped per teacher_id).
 */
final class GeoGebraCatalogController
{
    private GeoGebraCatalogService $svc;

    public function __construct(?GeoGebraCatalogService $svc = null)
    {
        $this->svc = $svc ?? new GeoGebraCatalogService();
    }

    private function authedTeacherId(): ?int
    {
        if (!Auth::check()) {
            return null;
        }
        $u = Auth::user();
        $id = (int)($u['id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /** GET /geogebra/catalog */
    public function list(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::fail('auth_required', 401);
        }
        try {
            return Response::json(['ok' => true, 'items' => $this->svc->listLight($tid)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /geogebra/catalog/{id}
     *
     * 23/9/2026 (revisione architetturale, A-5) — l'id si leggeva da
     * `$req->params`, che Request non ha: valeva sempre '' e il pulsante
     * «Apri» del catalogo riceveva 400 a ogni voce. I parametri della rotta
     * arrivano come secondo argomento (Kernel::invoke).
     *
     * @param array<string, string> $params parametri della rotta
     */
    public function get(Request $req, array $params = []): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::fail('auth_required', 401);
        }
        $id = (string)($params['id'] ?? '');
        if ($id === '') {
            return Response::fail('id_missing', 400);
        }
        try {
            $item = $this->svc->getItem($tid, $id);
            if ($item === null) {
                return Response::fail('not_found', 404);
            }
            return Response::json(['ok' => true, 'item' => $item]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /geogebra/catalog/save */
    public function save(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::fail('auth_required', 401);
        }
        $body = $req->body();
        $label    = trim((string)($body['label'] ?? ''));
        $ggbB64   = (string)($body['ggb_b64'] ?? '');
        $svg      = (string)($body['svg_cached'] ?? '');
        $id       = trim((string)($body['id'] ?? ''));

        if ($label === '') {
            return Response::fail('label_missing', 400);
        }
        if ($ggbB64 === '') {
            return Response::fail('ggb_missing', 400);
        }
        try {
            $r = $this->svc->saveItem($tid, $label, $ggbB64, $svg, $id);
            return Response::json(['ok' => true, 'success' => true] + $r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /geogebra/catalog/delete */
    public function delete(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::fail('auth_required', 401);
        }
        $body = $req->body();
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            return Response::fail('id_missing', 400);
        }
        try {
            $removed = $this->svc->deleteItem($tid, $id);
            return Response::json(['ok' => true, 'success' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
