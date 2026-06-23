<?php

declare(strict_types=1);

namespace App\Controllers\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Services\Risdoc\CompilationRepository;
use App\Services\Risdoc\Permission;

/**
 * REST API per risdoc per-teacher compilations.
 *
 *   GET    /api/risdoc/templates/{id}/compilations       → lista (filtrata)
 *   GET    /api/risdoc/compilations/{id}                 → dettaglio (con data_json)
 *   POST   /api/risdoc/templates/{id}/compilations       → upsert
 *   POST   /api/risdoc/compilations/{id}/delete          → delete
 *
 * Tutte protette da auth teacher+. Write protette da CSRF tramite
 * gruppo routes (vedi routes/web.php).
 */
final class CompilationController
{
    public function __construct(private CompilationRepository $repo = new CompilationRepository())
    {
    }

    public function index(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        // Filtri contesto: se specificati lato client, la lista mostra
        // solo le compilazioni con classe/sezione/indirizzo/disciplina
        // identici. Null = no filter su quel campo.
        $classe     = $this->nullable($req->query['classe']     ?? null);
        $sezione    = $this->nullable($req->query['sezione']    ?? null);
        $indirizzo  = $this->nullable($req->query['indirizzo']  ?? null);
        $disciplina = $this->nullable($req->query['disciplina'] ?? null);
        $rows = $this->repo->listByTeacher($tid, $id, $classe, $sezione, $indirizzo, $disciplina);
        return Response::json(['ok' => true, 'count' => count($rows), 'compilations' => $rows]);
    }

    public function show(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $row = $this->repo->find($tid, $id);
        if (!$row) {
            return Response::json(['error' => 'not_found'], 404);
        }
        return Response::json(['ok' => true, 'compilation' => $row]);
    }

    public function save(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if (!Permission::canView($id, $tid)) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $key   = trim((string)($req->post['compilation_key'] ?? ''));
        $label = trim((string)($req->post['label'] ?? ''));
        $data  = (string)($req->post['data'] ?? '');
        if ($key === '') {
            return Response::json(['error' => 'compilation_key_required'], 400);
        }
        if ($label === '') {
            return Response::json(['error' => 'label_required'], 400);
        }
        if ($data === '') {
            return Response::json(['error' => 'data_required'], 400);
        }
        if (!json_decode($data, true) && json_last_error() !== JSON_ERROR_NONE) {
            return Response::json(['error' => 'invalid_json'], 400);
        }
        if (strlen($data) > 2 * 1024 * 1024) {
// 2MB safeguard
            return Response::json(['error' => 'payload_too_large'], 413);
        }

        $classe     = $this->nullable($req->post['classe']     ?? null);
        $sezione    = $this->nullable($req->post['sezione']    ?? null);
        $indirizzo  = $this->nullable($req->post['indirizzo']  ?? null);
        $disciplina = $this->nullable($req->post['disciplina'] ?? null);

        $compilationId = $this->repo->save(
            $tid,
            $id,
            $key,
            $label,
            $classe,
            $sezione,
            $indirizzo,
            $disciplina,
            $data
        );
        return Response::json(['ok' => true, 'id' => $compilationId]);
    }

    public function delete(Request $req, array $params): Response
    {
        $tid = Permission::currentTeacherId();
        $id  = (int)($params['id'] ?? 0);
        if ($tid === 0) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if ($id === 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }

        $ok = $this->repo->delete($tid, $id);
        if (!$ok) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }
        return Response::json(['ok' => true]);
    }

    private function nullable(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $t = trim($v);
        return $t === '' ? null : $t;
    }
}
