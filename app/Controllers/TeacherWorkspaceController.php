<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Tikz\TeacherTemplateWorkspaceService;
use Throwable;

/**
 * G22.S15.bis Fase 3 — Workspace personale del docente per template TikZ/LaTeX.
 *
 * Routes:
 *   GET  /tikz/workspace                       → ritorna workspace docente (lazy create)
 *   GET  /tikz/admin-library                   → ritorna defaults admin (read-only per il docente)
 *   POST /tikz/workspace/element/save          → upsert elemento (crea o aggiorna; rinomina con oldLabel)
 *   POST /tikz/workspace/element/delete        → elimina elemento
 *   POST /tikz/workspace/group/rename          → rinomina chiave gruppo
 *   POST /tikz/workspace/group/delete          → elimina gruppo intero
 *   POST /tikz/workspace/group/reorder         → riordina gruppi (orderedKeys[])
 *   POST /tikz/workspace/reset-all             → wipe + ricopia defaults admin
 *   POST /tikz/workspace/import                → import singolo da admin con conflict resolution
 *
 * Auth: utente loggato. Workspace scoped per teacher_id (Auth::user()['id']).
 */
final class TeacherWorkspaceController
{
    private TeacherTemplateWorkspaceService $svc;

    public function __construct(?TeacherTemplateWorkspaceService $svc = null)
    {
        $this->svc = $svc ?? new TeacherTemplateWorkspaceService();
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

    /** GET /tikz/workspace */
    public function getWorkspace(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        try {
            return Response::json($this->svc->getWorkspace($tid));
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** GET /tikz/admin-library */
    public function getAdminLibrary(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        try {
            return Response::json($this->svc->getAdminLibrary());
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/element/save */
    public function saveElement(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
        $oldLabel = trim((string)($body['oldLabel'] ?? ''));
        $code     = (string)($body['code'] ?? '');
        $type     = (string)($body['type'] ?? 'tikz');
        $data     = isset($body['data']) && is_array($body['data']) ? $body['data'] : null;

        if ($groupKey === '' || $label === '') {
            return Response::json(['ok' => false, 'error' => 'group_or_label_missing'], 400);
        }
        if ($code === '') {
            return Response::json(['ok' => false, 'error' => 'code_missing'], 400);
        }
        if (strlen($code) > 1024 * 1024) {
            return Response::json(['ok' => false, 'error' => 'code_too_large'], 413);
        }

        try {
            $r = $this->svc->saveElement($tid, $groupKey, $label, $code, $type, $data, $oldLabel);
            return Response::json(['ok' => true, 'success' => true] + $r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/element/delete */
    public function deleteElement(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
        if ($groupKey === '' || $label === '') {
            return Response::json(['ok' => false, 'error' => 'group_or_label_missing'], 400);
        }
        try {
            $removed = $this->svc->deleteElement($tid, $groupKey, $label);
            return Response::json(['ok' => true, 'success' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/group/rename */
    public function renameGroup(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $oldKey = trim((string)($body['oldKey'] ?? ''));
        $newKey = trim((string)($body['newKey'] ?? ''));
        if ($oldKey === '' || $newKey === '') {
            return Response::json(['ok' => false, 'error' => 'keys_missing'], 400);
        }
        try {
            $this->svc->renameGroup($tid, $oldKey, $newKey);
            return Response::json(['ok' => true, 'success' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /** POST /tikz/workspace/group/delete */
    public function deleteGroup(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        if ($groupKey === '') {
            return Response::json(['ok' => false, 'error' => 'group_missing'], 400);
        }
        try {
            $removed = $this->svc->deleteGroup($tid, $groupKey);
            return Response::json(['ok' => true, 'success' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/group/reorder */
    public function reorderGroups(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $orderedKeys = isset($body['orderedKeys']) && is_array($body['orderedKeys']) ? $body['orderedKeys'] : [];
        if (empty($orderedKeys)) {
            return Response::json(['ok' => false, 'error' => 'orderedKeys_missing'], 400);
        }
        try {
            $this->svc->reorderGroups($tid, $orderedKeys);
            return Response::json(['ok' => true, 'success' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/reset-all */
    public function resetAll(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        try {
            $ws = $this->svc->resetAll($tid);
            return Response::json(['ok' => true, 'success' => true, 'workspace' => $ws]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/workspace/import */
    public function importFromAdmin(Request $req): Response
    {
        $tid = $this->authedTeacherId();
        if ($tid === null) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $body = $this->readJsonBody($req);
        $sourceGroupKey = trim((string)($body['sourceGroupKey'] ?? ''));
        $sourceLabel    = trim((string)($body['sourceLabel'] ?? ''));
        $targetGroupKey = trim((string)($body['targetGroupKey'] ?? ''));
        $conflict       = (string)($body['conflict'] ?? 'abort');
        if (!in_array($conflict, ['abort', 'overwrite', 'rename'], true)) {
            $conflict = 'abort';
        }
        if ($sourceGroupKey === '' || $sourceLabel === '') {
            return Response::json(['ok' => false, 'error' => 'source_missing'], 400);
        }
        try {
            $r = $this->svc->importFromAdmin($tid, $sourceGroupKey, $sourceLabel, $targetGroupKey, $conflict);
            return Response::json(['ok' => true, 'success' => true] + $r);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
