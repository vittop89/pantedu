<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Policies\ExerciseAccessPolicy;
use App\Repositories\TeacherContentRepository;

/**
 * StudyHeaderController — estratto da ContentStudyController (ADR-029).
 * Metodi: headerPageJson, headerPageStudentJson, headerPageSave.
 * Helper condivisi duplicati: resolveUserId, firstInstituteId.
 */
final class StudyHeaderController
{
    private TeacherContentRepository $repo;

    public function __construct(?TeacherContentRepository $repo = null)
    {
        $this->repo = $repo ?? new TeacherContentRepository();
    }

    /** Phase 16 — GET /api/teacher/header-page.json — contenuto personalizzato
     *  di `#header_page` del docente (privacy disclaimer + preferenze).
     *  Formato: `{html: string, auto_citations: bool}`.
     *  Default se file assente: disclaimer standard + auto_citations=true. */
    public function headerPageJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        return Response::json($this->loadHeaderPage($iid, $tid));
    }

    /**
     * Phase 25.Q.16 — GET /api/study/header-page.json — endpoint read-only
     * per studenti: legge il template `#header_page` del docente di
     * riferimento (specificato via ?teacher_id=N, oppure primo teacher
     * dell'istituto dello studente come fallback).
     *
     * Sicurezza:
     *  - Auth required (no guest)
     *  - Validazione: teacher_id deve appartenere allo stesso istituto
     *    dello studente (impedisce leak cross-istituto)
     *  - Read-only: solo html + auto_citations, niente storage internals
     */
    public function headerPageStudentJson(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $userId = (int)($u['id'] ?? 0);
        if ($userId <= 0) {
            return Response::json(['error' => 'invalid_user'], 401);
        }
        $pdo = \App\Core\Database::connection();
        $role = (string)Auth::role();
        // Risolvi istituto utente.
        $instituteId = 0;
        if ($role === 'student') {
            $stmt = $pdo->prepare('SELECT institute_id FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $instituteId = (int)$stmt->fetchColumn();
        } else {
            $stmt = $pdo->prepare(
                'SELECT institute_id FROM teacher_institutes WHERE user_id = ?
                 ORDER BY created_at, institute_id LIMIT 1'
            );
            $stmt->execute([$userId]);
            $instituteId = (int)$stmt->fetchColumn();
        }
        if ($instituteId <= 0) {
            return Response::json($this->defaultHeaderPage());
        }
        // Teacher target: query param ?teacher_id=N validato vs istituto,
        // altrimenti fallback al primo teacher dell'istituto (most-used).
        $reqTid = (int)($req->query['teacher_id'] ?? 0);
        $teacherId = 0;
        if ($reqTid > 0) {
            $stmt = $pdo->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1'
            );
            $stmt->execute([$reqTid, $instituteId]);
            if ($stmt->fetchColumn()) {
                $teacherId = $reqTid;
            }
        }
        if ($teacherId === 0) {
            $stmt = $pdo->prepare(
                'SELECT user_id FROM teacher_institutes WHERE institute_id = ?
                 ORDER BY created_at, user_id LIMIT 1'
            );
            $stmt->execute([$instituteId]);
            $teacherId = (int)$stmt->fetchColumn();
        }
        if ($teacherId <= 0) {
            return Response::json($this->defaultHeaderPage());
        }
        return Response::json($this->loadHeaderPage($instituteId, $teacherId));
    }

    private function loadHeaderPage(int $iid, int $tid): array
    {
        $key = "institutes/$iid/private/$tid/header_page.json";
        try {
            $bytes = \App\Support\Storage\StorageFactory::default()->get($key);
            $data = json_decode((string)$bytes, true);
            if (!is_array($data)) {
                throw new \RuntimeException('invalid_json');
            }
            return $data;
        } catch (\Throwable) {
            return $this->defaultHeaderPage();
        }
    }

    private function defaultHeaderPage(): array
    {
        return [
            'html' => "<p>Il contenuto di questa pagina presenta solo un'elaborazione parziale di alcuni esercizi (traccia e soluzioni escluse) citati con numero e pagina del libro di testo in dotazione dagli studenti seguiti dal sottoscritto.<br><u>È vietato condividere a terzi username e password di accesso a questa pagina web.</u></p>",
            'auto_citations' => true,
        ];
    }

    /** Phase 16 — PUT /api/teacher/header-page.json — salva il template
     *  personale. Body: `{html: string, auto_citations: bool}`. Sanitizza
     *  html (tag permessi: p, br, strong, em, u, ul, ol, li, a). */
    public function headerPageSave(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = $this->resolveUserId((string)($u['username'] ?? ''));
        if (!$tid) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = $this->firstInstituteId($tid);
        if (!$iid) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }
        $raw = (string)@file_get_contents('php://input');
        if (strlen($raw) > 32768) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return Response::json(['error' => 'invalid_payload'], 422);
        }
        $html = (string)($data['html'] ?? '');
        $allowed = '<p><br><strong><em><u><ul><ol><li><a>';
        $html = strip_tags($html, $allowed);
        $payload = [
            'html' => $html,
            'auto_citations' => !empty($data['auto_citations']),
        ];
        $key = "institutes/$iid/private/$tid/header_page.json";
        try {
            \App\Support\Storage\StorageFactory::default()
                ->put($key, (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            return Response::json(['error' => 'storage_error', 'message' => $e->getMessage()], 500);
        }
        return Response::json(['ok' => true]);
    }

    // ---- helper condivisi (copia da ContentStudyController, ADR-029) ----

    private function resolveUserId(string $username): int
    {
        return \App\Support\TeacherContextResolver::userIdFromUsername($username);
    }

    private function firstInstituteId(int $teacherId): int
    {
        return \App\Support\TeacherContextResolver::firstInstituteId($teacherId);
    }
}
