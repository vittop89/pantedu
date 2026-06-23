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

    private function readJsonBody(Request $req): array
    {
        $contentType = (string)($_SERVER['CONTENT_TYPE'] ?? $req->headers['content-type'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $raw = (string) @file_get_contents('php://input');
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        $body = $req->post ?? [];
        if (empty($body)) {
            $raw = (string) @file_get_contents('php://input');
            if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }
        return is_array($body) ? $body : [];
    }

    /** GET /geogebra/catalog */
    public function list(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        try {
            return Response::json(['ok' => true, 'items' => $this->svc->listLight($tid)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /geogebra/catalog/{id} */
    public function get(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $id = (string)($req->params['id'] ?? '');
        if ($id === '') {
            return Response::json(['ok' => false, 'error' => 'id_missing'], 400);
        }
        try {
            $item = $this->svc->getItem($tid, $id);
            if ($item === null) {
                return Response::json(['ok' => false, 'error' => 'not_found'], 404);
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
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $label    = trim((string)($body['label'] ?? ''));
        $ggbB64   = (string)($body['ggb_b64'] ?? '');
        $svg      = (string)($body['svg_cached'] ?? '');
        $id       = trim((string)($body['id'] ?? ''));

        if ($label === '') {
            return Response::json(['ok' => false, 'error' => 'label_missing'], 400);
        }
        if ($ggbB64 === '') {
            return Response::json(['ok' => false, 'error' => 'ggb_missing'], 400);
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
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $id = trim((string)($body['id'] ?? ''));
        if ($id === '') {
            return Response::json(['ok' => false, 'error' => 'id_missing'], 400);
        }
        try {
            $removed = $this->svc->deleteItem($tid, $id);
            return Response::json(['ok' => true, 'success' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
