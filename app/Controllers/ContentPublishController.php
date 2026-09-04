<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\TeacherContentRepository;
use Throwable;

/**
 * ContentPublishController — estratto da TeacherContentController (ADR-029).
 * Metodi: publish, unpublish, sharePool.
 * Helper condivisi duplicati: teacherId, dbReady.
 */
final class ContentPublishController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    public function publish(Request $req, array $params): Response
    {
        return $this->setVisibility($req, $params, 'published');
    }

    public function unpublish(Request $req, array $params): Response
    {
        return $this->setVisibility($req, $params, 'draft');
    }

    /**
     * Phase 18 — PUT /api/teacher/content/{id}/share-pool
     * Body: enabled=0|1 (form-encoded o JSON).
     *
     * Toggle shared_with_pool. Solo l'owner può flippare il flag.
     * Dopo share, i docenti dello stesso istituto con pool_enabled
     * vedranno la riga via SharedContentPolicy::canReadContent.
     */
    public function sharePool(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $id = (int)($params['id'] ?? 0);
        if (!$id) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        $raw = $req->post['enabled'] ?? $req->input('enabled', 0);
        $enabled = (bool)\filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        // G22.S25 — delega a SharedContentPolicy (ownership + update + propagation
        // esercizio↔verifica). Sostituisce 100 righe duplicate fra TeacherContent
        // e VerificaController.
        $policy = new \App\Services\Sharing\SharedContentPolicy();
        $result = $policy->toggleSharePool($tid, 'teacher_content', $id, $enabled);
        if (!$result['ok']) {
            // Phase 25.P.3 — copyright block ha codice dedicato + messaggio user-facing
            if (($result['error'] ?? '') === 'copyright_block') {
                return Response::json([
                    'error'   => 'copyright_block',
                    'reason'  => $result['reason']  ?? null,
                    'message' => $result['message'] ?? 'Condivisione bloccata per ragioni di copyright.',
                ], 409); // 409 Conflict: semantically appropriate per share-block
            }
            $status = $result['error'] === 'forbidden' ? 403 : 400;
            return Response::json(['error' => $result['error']], $status);
        }
        return Response::json([
            'ok'                => true,
            'id'                => $id,
            'shared_with_pool'  => $enabled,
            'counterpart_id'    => $result['counterpart']['id']   ?? null,
            'counterpart_type'  => $result['counterpart']['type'] ?? null,
        ]);
    }

    private function setVisibility(Request $req, array $params, string $visibility): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ok = $this->repo->update((int)($params['id'] ?? 0), $tid, ['visibility' => $visibility]);
        if (!$ok) {
            return Response::json(['error' => 'not_found_or_forbidden'], 404);
        }
        return Response::json(['ok' => true, 'visibility' => $visibility]);
    }

    // ---- helper condivisi (copia da TeacherContentController, ADR-029) ----

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }
}
