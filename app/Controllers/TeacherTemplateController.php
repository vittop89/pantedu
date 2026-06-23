<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Tikz\TeacherTemplateOverridesService;
use Throwable;

/**
 * G22.S15.bis — Endpoint per gestire override personali dei template
 * TikZ/LaTeX da parte di un docente.
 *
 * Routes:
 *   GET  /tikz/effective-templates       → defaults admin merged con override docente
 *   POST /tikz/teacher-templates/save    → upsert override (groupKey, label, code, data?)
 *   POST /tikz/teacher-templates/reset   → elimina override (groupKey, label)
 *
 * Auth: serve qualsiasi utente loggato (docente, admin). L'override è
 * scoped per teacher_id (Auth::user()['id']).
 */
final class TeacherTemplateController
{
    private TeacherTemplateOverridesService $svc;

    public function __construct(?TeacherTemplateOverridesService $svc = null)
    {
        $this->svc = $svc ?? new TeacherTemplateOverridesService();
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

    /** GET /tikz/effective-templates — admin defaults + teacher overrides merged. */
    public function effective(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $u = Auth::user();
        $teacherId = (int)($u['id'] ?? 0);
        try {
            $data = $this->svc->getEffectiveTemplates($teacherId);
            return Response::json($data);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/teacher-templates/save */
    public function save(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $u = Auth::user();
        $teacherId = (int)($u['id'] ?? 0);
        if ($teacherId <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_teacher_id'], 400);
        }

        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
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
        if (!in_array($type, ['tikz', 'latex'], true)) {
            return Response::json(['ok' => false, 'error' => 'invalid_type'], 400);
        }

        try {
            $this->svc->saveOverride($teacherId, $groupKey, $label, $code, $data, $type);
            return Response::json(['ok' => true, 'success' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /tikz/teacher-templates/reset */
    public function reset(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $u = Auth::user();
        $teacherId = (int)($u['id'] ?? 0);
        if ($teacherId <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_teacher_id'], 400);
        }

        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
        if ($groupKey === '' || $label === '') {
            return Response::json(['ok' => false, 'error' => 'group_or_label_missing'], 400);
        }

        try {
            $removed = $this->svc->resetOverride($teacherId, $groupKey, $label);
            return Response::json(['ok' => true, 'success' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
