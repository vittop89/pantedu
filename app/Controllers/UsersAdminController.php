<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\PrivilegedAccessLogger;
use App\Core\Request;
use App\Core\Response;
use PDO;
use Throwable;

/**
 * Admin users management (Phase 13) — sostituisce
 * log/admin/user_manager.php legacy con endpoint JSON moderni.
 *
 *   GET  /api/admin/users?q=&role=&status=&limit=
 *   POST /api/admin/users/{id}/active   → body: { active: 0|1 }
 *   POST /api/admin/users/{id}/role     → body: { role }
 *   POST /api/admin/users/{id}/delete   → cancella user (e cascade pivot)
 */
final class UsersAdminController
{
    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $q       = trim((string)($req->query['q']       ?? ''));
        $role    = trim((string)($req->query['role']    ?? ''));
        $status  = trim((string)($req->query['status']  ?? ''));
        $limit   = max(1, min(500, (int)($req->query['limit']  ?? 100)));
        $reason  = trim((string)($req->query['reason']   ?? ''));

        // Phase 14 — GDPR minimizzazione:
        // - super-admin NON vede studenti (lista filtrata, PII non esposta)
        // - super-admin vede solo campi minimi per docenti (no email)
        // - accesso SEMPRE loggato con motivo obbligatorio
        $isSuper = Auth::isSuperAdmin();
        if ($isSuper && $reason === '') {
            PrivilegedAccessLogger::log('list', 'user', null, '', 'denied');
            return Response::json(['error' => 'reason_required'], 400);
        }

        $where = [];
        $args = [];
        if ($q !== '') {
            $where[] = '(username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        if ($role !== '') {
            $where[] = 'role = ?';
            $args[] = $role;
        }
        if ($status !== '') {
            $where[] = 'status = ?';
            $args[] = $status;
        }
        if ($isSuper) {
            $where[] = "role <> 'student'"; // GDPR strict: students mai esposti al super-admin
        }

        $sql = 'SELECT id, username, role, first_name, last_name, email, status, active, institute_id, created_at, approved_at
                FROM users';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY created_at DESC LIMIT $limit";
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($isSuper) {
            foreach ($rows as &$r) {
                unset($r['email']);
            }
            unset($r);
            PrivilegedAccessLogger::log('list', 'user', null, $reason);
        }
        return Response::json(['ok' => true, 'rows' => $rows]);
    }

    public function setActive(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $id     = (int)($params['id'] ?? 0);
        $active = !empty($req->post['active']) ? 1 : 0;
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if ($id === $this->currentUserId()) {
            return Response::json(['error' => 'cannot_disable_self'], 403);
        }
        try {
            $stmt = Database::connection()->prepare('UPDATE users SET active = ? WHERE id = ?');
            $stmt->execute([$active, $id]);
            return Response::json(['ok' => true, 'active' => (bool)$active]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function setRole(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $id   = (int)($params['id'] ?? 0);
        $role = trim((string)($req->post['role'] ?? ''));
        if (!in_array($role, ['student', 'teacher', 'collaborator', 'administrator'], true)) {
            return Response::json(['error' => 'invalid_role'], 400);
        }
        if ($id === $this->currentUserId() && $role !== 'administrator') {
            return Response::json(['error' => 'cannot_demote_self'], 403);
        }
        try {
            $stmt = Database::connection()->prepare('UPDATE users SET role = ? WHERE id = ?');
            $stmt->execute([$role, $id]);

            // Phase 19 — session rotation: se l'utente modificato e'
            // quello loggato, refresh claims + regenerate id.
            $rotated = false;
            if ($id === $this->currentUserId()) {
                \App\Core\Session::regenerate();
                \App\Core\Auth::refreshCurrentUserClaims();
                $rotated = true;
            }

            $response = Response::json(['ok' => true, 'role' => $role, 'rotated' => $rotated]);
            // Hint al client di ri-fetch /auth/user-info post privilege change
            if ($rotated) {
                $response->headers['X-FM-Reload'] = '1';
            }
            return $response;
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $id = (int)($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::json(['error' => 'invalid_id'], 400);
        }
        if ($id === $this->currentUserId()) {
            return Response::json(['error' => 'cannot_delete_self'], 403);
        }
        try {
            $stmt = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            return Response::json(['ok' => true, 'removed' => $stmt->rowCount() > 0]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function currentUserId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        $stmt = Database::connection()->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([(string)($u['username'] ?? '')]);
        return (int)($stmt->fetchColumn() ?: 0);
    }
}
