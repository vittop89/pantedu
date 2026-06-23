<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\InstituteRepository;
use Throwable;

/**
 * REST per gestione istituti (Phase 13).
 *
 *   GET  /api/institutes                       → lista pubblica (per registrazione)
 *   POST /api/institutes                       → crea/upsert (admin)
 *   GET  /api/teacher/institutes               → istituti del docente loggato
 *   POST /api/teacher/institutes/link          → docente si associa a istituto
 *   POST /api/teacher/institutes/{id}/unlink   → docente si disassocia
 */
final class InstituteController
{
    private InstituteRepository $repo;

    public function __construct(?InstituteRepository $repo = null)
    {
        $this->repo = $repo ?? new InstituteRepository();
    }

    public function index(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        return Response::json(['ok' => true, 'institutes' => $this->repo->listActive()]);
    }

    /** Admin only — crea/upsert istituto. */
    public function create(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        if (!Auth::hasAccess('admin')) {
            return Response::json(['error' => 'forbidden'], 403);
        }

        $code   = trim((string)($req->post['code']   ?? ''));
        $name   = trim((string)($req->post['name']   ?? ''));
        $city   = trim((string)($req->post['city']   ?? '')) ?: null;
        $region = trim((string)($req->post['region'] ?? '')) ?: null;
        if (!preg_match('/^[A-Za-z0-9_-]{2,32}$/', $code)) {
            return Response::json(['error' => 'invalid_code'], 400);
        }
        if ($name === '' || mb_strlen($name) > 250) {
            return Response::json(['error' => 'invalid_name'], 400);
        }

        try {
            // upsertCanonical: evita duplicati per la stessa scuola (dedupKey).
            $id = $this->repo->upsertCanonical($code, $name, $city, $region);
            return Response::json(['ok' => true, 'id' => $id]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'persist_failed', 'detail' => $e->getMessage()], 500);
        }
    }

    public function listForTeacher(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['ok' => true, 'institutes' => []]);
        }
        return Response::json(['ok' => true, 'institutes' => $this->repo->listForTeacher($tid)]);
    }

    public function link(Request $req): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        if (!$tid) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        $iid  = (int)($req->post['institute_id'] ?? 0);
        $role = trim((string)($req->post['role'] ?? '')) ?: null;
        $inst = $iid > 0 ? $this->repo->findById($iid) : null;
        if (!$inst) {
            return Response::json(['error' => 'invalid_institute'], 400);
        }
        // listForTeacher() mostra solo istituti active=1: collegare un inattivo
        // (es. duplicato unito/soft-deleted da InstituteMergeService) creerebbe
        // un link "fantasma" mai visibile nel pannello né nella sidebar.
        if ((int)($inst['active'] ?? 0) !== 1) {
            return Response::json(['error' => 'institute_inactive'], 400);
        }
        // linkTeacher è INSERT IGNORE → rowCount 0 se già collegato. Distinguiamo
        // così il client non mostra "✓ Collegato" quando in realtà nulla è cambiato.
        $inserted = $this->repo->linkTeacher($tid, $iid, $role);
        return Response::json(['ok' => true, 'already_linked' => !$inserted]);
    }

    public function unlink(Request $req, array $params): Response
    {
        if (!$this->dbReady()) {
            return Response::json(['error' => 'db_unavailable'], 503);
        }
        $tid = $this->teacherId();
        $iid = (int)($params['id'] ?? 0);
        if (!$tid || !$iid) {
            return Response::json(['error' => 'invalid_params'], 400);
        }
        $this->repo->unlinkTeacher($tid, $iid);
        return Response::json(['ok' => true]);
    }

    private function dbReady(): bool
    {
        return (bool)Config::get('database.enabled') && Database::isAvailable();
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
