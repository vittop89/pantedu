<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Gestione materie del docente (Phase 13 — multi-materia flessibile).
 *
 *   GET  /api/teacher/subjects             → lista materie del docente
 *   POST /api/teacher/subjects             → crea materia + pivot teacher
 *        body: { code, label, group? }
 *   POST /api/teacher/subjects/{id}/delete → unlink (non cancella materia
 *        se altri docenti la usano)
 *
 * Persistenza:
 *   - `curriculum_entries` (kind=materie): registry globale materie
 *   - `curriculum_users` pivot: scope per-teacher
 */
final class TeacherSubjectController
{
    public function listMine(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['ok' => true, 'subjects' => []]);
        }

        $stmt = Database::connection()->prepare(
            "SELECT ce.id, ce.code, ce.label, ce.grp, ce.active
             FROM curriculum_entries ce
             INNER JOIN curriculum_users cu ON cu.curriculum_id = ce.id
             WHERE ce.kind = 'materie' AND cu.user_id = ?
             ORDER BY ce.label"
        );
        $stmt->execute([$tid]);
        return Response::json(['ok' => true, 'subjects' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    public function create(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'user_not_in_db'], 403);
        }

        $code  = trim((string)($req->post['code']  ?? ''));
        $label = trim((string)($req->post['label'] ?? ''));
        $group = trim((string)($req->post['group'] ?? '')) ?: null;

        if (!preg_match('/^[A-Za-z0-9_-]{1,16}$/', $code)) {
            return Response::json(['error' => 'invalid_code'], 400);
        }
        if ($label === '' || mb_strlen($label) > 200) {
            return Response::json(['error' => 'invalid_label'], 400);
        }

        $pdo = Database::connection();
        try {
            $pdo->beginTransaction();
            // upsert materia (idempotente su unique kind+code)
            $stmt = $pdo->prepare(
                "INSERT INTO curriculum_entries (kind, code, label, grp, active)
                 VALUES ('materie', ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), grp = VALUES(grp), active = 1"
            );
            $stmt->execute([$code, $label, $group]);
            // ottieni id
            $sub = $pdo->prepare("SELECT id FROM curriculum_entries WHERE kind='materie' AND code = ?");
            $sub->execute([$code]);
            $sid = (int)$sub->fetchColumn();
            // pivot (idempotente)
            $pdo->prepare("INSERT IGNORE INTO curriculum_users (curriculum_id, user_id) VALUES (?, ?)")
                ->execute([$sid, $tid]);
            $pdo->commit();
            return Response::json(['ok' => true, 'subject' => ['id' => $sid, 'code' => $code, 'label' => $label, 'group' => $group]]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function unlink(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        $sid = (int)($params['id'] ?? 0);
        if (!$tid || !$sid) {
            return Response::json(['error' => 'invalid_params'], 400);
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM curriculum_users WHERE curriculum_id = ? AND user_id = ?'
        );
        $stmt->execute([$sid, $tid]);
        return Response::json(['ok' => true, 'unlinked' => $stmt->rowCount() > 0]);
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function resolveUserId(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }
}
