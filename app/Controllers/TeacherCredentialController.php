<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\TeacherCredentialRepository;
use Throwable;

/**
 * Gestione credenziali di accesso per studenti alle risorse del docente
 * (Phase 13 — `teacher_access_credentials`).
 *
 *   GET  /api/teacher/credentials                → lista coppie del docente
 *   POST /api/teacher/credentials                → crea (label, username, password)
 *   POST /api/teacher/credentials/{id}/delete    → cancella
 *   POST /api/teacher/credentials/{id}/toggle    → on/off
 *
 * Endpoint pubblico (auth in sessione richiesta come studente o guest):
 *   POST /api/access/student-login   → body: { username, password }
 *     Verifica credenziali; se ok, stampa nella session
 *     `fm_teacher_access` = { teacher_id, label, indirizzo?, classe? }
 *     così frontend può mostrare risorse di quel docente.
 *   POST /api/access/student-logout
 */
final class TeacherCredentialController
{
    private TeacherCredentialRepository $repo;
    private const SESS_KEY = 'fm_teacher_access';

    public function __construct(?TeacherCredentialRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherCredentialRepository();
    }

    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        return Response::json(['ok' => true, 'credentials' => $this->repo->listForTeacher($tid)]);
    }

    public function create(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        try {
            $id = $this->repo->create($tid, [
                'label'        => $req->post['label']        ?? '',
                'username'     => $req->post['username']     ?? '',
                'password'     => $req->post['password']     ?? '',
                'indirizzo'    => $req->post['indirizzo']    ?? null,
                'classe'       => $req->post['classe']       ?? null,
                'institute_id' => $req->post['institute_id'] ?? null,
            ]);
            return Response::json(['ok' => true, 'id' => $id]);
        } catch (\InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 400);
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function delete(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $ok = $this->repo->delete($tid, (int)($params['id'] ?? 0));
        return Response::json(['ok' => $ok]);
    }

    public function toggle(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $active = !empty($req->post['active']);
        $ok = $this->repo->setActive($tid, (int)($params['id'] ?? 0), $active);
        return Response::json(['ok' => $ok, 'active' => $active]);
    }

    /**
     * Endpoint per studente: login alle risorse di un docente.
     *
     * Tenta in 2 fasi:
     *   1. teacher_access_credentials (codici condivisi che il docente
     *      ha generato per i propri studenti)
     *   2. fallback: Auth::attempt() su account utente standard
     *      (admin/teacher loggato → grant equivalente a "self-access").
     *      Permette al docente/admin di usare le proprie credenziali
     *      direttamente senza dover prima generare un access code.
     */
    public function studentLogin(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $username = trim((string)($req->post['username'] ?? ''));
        $password = (string)($req->post['password'] ?? '');
        if ($username === '' || $password === '') {
            return Response::json(['error' => 'missing_credentials'], 400);
        }

        // 1) Try teacher_access_credentials
        $row = $this->repo->verify($username, $password);
        if ($row) {
            $grant = [
                'teacher_id'   => (int)$row['teacher_id'],
                'label'        => (string)$row['label'],
                'indirizzo'    => $row['indirizzo'],
                'classe'       => $row['classe'],
                'institute_id' => $row['institute_id'] ? (int)$row['institute_id'] : null,
                'source'       => 'teacher_access_credentials',
                'granted_at'   => time(),
            ];
            Session::put(self::SESS_KEY, $grant);
            return Response::json(['ok' => true, 'grant' => $grant]);
        }

        // 2) Fallback: account utente reale (admin/teacher self-access)
        [$user, $reason] = Auth::attempt($username, $password);
        unset($reason);
        if ($user && in_array($user->role, ['administrator', 'teacher', 'collaborator'], true)) {
            $tid = $this->lookupUserIdByUsername($user->username);
            $grant = [
                'teacher_id'   => $tid,
                'label'        => "Self-access ({$user->role}): {$user->username}",
                'indirizzo'    => null,
                'classe'       => null,
                'institute_id' => null,
                'source'       => 'user_account',
                'granted_at'   => time(),
            ];
            Session::put(self::SESS_KEY, $grant);
            return Response::json(['ok' => true, 'grant' => $grant]);
        }

        return Response::json(['error' => 'invalid_credentials'], 401);
    }

    public function studentLogout(Request $req): Response
    {
        Session::put(self::SESS_KEY, null);
        return Response::json(['ok' => true]);
    }

    public function studentStatus(Request $req): Response
    {
        $g = Session::get(self::SESS_KEY);
        return Response::json(['ok' => true, 'grant' => $g]);
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
    }

    private function lookupUserIdByUsername(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }

    private function teacherId(): int
    {
        $u = Auth::user();
        if (!$u) {
            return 0;
        }
        return \App\Support\TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
    }
}
