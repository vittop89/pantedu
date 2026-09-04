<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Shortcuts\LatexShortcutsService;
use Throwable;

/**
 * Phase 25 — API per il modello "Scorciatoie LaTeX da tastiera".
 *
 * Docente (qualsiasi utente loggato che scrive):
 *   GET  /api/latex-shortcuts/effective   → riferimento admin + override docente
 *   POST /api/latex-shortcuts/save         → upsert override (groupKey,label,fields)
 *   POST /api/latex-shortcuts/reset        → elimina override (groupKey,label)
 *   POST /api/latex-shortcuts/reset-all    → elimina tutti gli override del docente
 *
 * Super-admin (riferimento istituzionale):
 *   GET  /api/admin/latex-shortcuts        → riferimento corrente
 *   POST /api/admin/latex-shortcuts        → sovrascrive il riferimento
 */
final class LatexShortcutsController
{
    private LatexShortcutsService $svc;

    public function __construct(?LatexShortcutsService $svc = null)
    {
        $this->svc = $svc ?? new LatexShortcutsService();
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

    private function teacherId(): int
    {
        $u = Auth::user();
        return (int)($u['id'] ?? 0);
    }

    /** GET /api/latex-shortcuts/effective */
    public function effective(Request $req): Response
    {
        $tid = Auth::check() ? $this->teacherId() : 0;
        try {
            return Response::json(['ok' => true, 'groups' => $this->svc->getEffective($tid)]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/latex-shortcuts/save */
    public function save(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $tid = $this->teacherId();
        if ($tid <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_teacher_id'], 400);
        }
        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
        if ($groupKey === '' || $label === '') {
            return Response::json(['ok' => false, 'error' => 'group_or_label_missing'], 400);
        }
        $fields = [];
        if (isset($body['snippet'])) {
            $snip = (string)$body['snippet'];
            if (strlen($snip) > 8192) {
                return Response::json(['ok' => false, 'error' => 'snippet_too_large'], 413);
            }
            $fields['snippet'] = $snip;
        }
        if (isset($body['trigger'])) {
            $fields['trigger'] = substr((string)$body['trigger'], 0, 64);
        }
        if (isset($body['keys'])) {
            $fields['keys'] = substr(strtolower((string)$body['keys']), 0, 64);
        }
        if (array_key_exists('enabled', $body)) {
            $fields['enabled'] = (bool)$body['enabled'];
        }
        if (empty($fields)) {
            return Response::json(['ok' => false, 'error' => 'nothing_to_save'], 400);
        }
        try {
            $this->svc->saveOverride($tid, $groupKey, $label, $fields);
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/latex-shortcuts/reset */
    public function reset(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $tid = $this->teacherId();
        if ($tid <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_teacher_id'], 400);
        }
        $body = $this->readJsonBody($req);
        $groupKey = trim((string)($body['groupKey'] ?? ''));
        $label    = trim((string)($body['label'] ?? ''));
        if ($groupKey === '' || $label === '') {
            return Response::json(['ok' => false, 'error' => 'group_or_label_missing'], 400);
        }
        try {
            $removed = $this->svc->resetOverride($tid, $groupKey, $label);
            return Response::json(['ok' => true, 'removed' => $removed]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** POST /api/latex-shortcuts/reset-all */
    public function resetAll(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['ok' => false, 'error' => 'auth_required'], 401);
        }
        $tid = $this->teacherId();
        if ($tid <= 0) {
            return Response::json(['ok' => false, 'error' => 'no_teacher_id'], 400);
        }
        try {
            $n = $this->svc->resetAll($tid);
            return Response::json(['ok' => true, 'removed' => $n]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ───────────────────────────── Admin ─────────────────────────────

    /** GET /api/admin/latex-shortcuts */
    public function adminList(Request $req): Response
    {
        if (!Auth::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return Response::json(['ok' => true, 'groups' => $this->svc->getAdminDefaults()]);
    }

    /** POST /api/admin/latex-shortcuts — sovrascrive il riferimento. */
    public function adminSave(Request $req): Response
    {
        if (!Auth::isSuperAdmin()) {
            return Response::json(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $body = $this->readJsonBody($req);
        $groups = $body['groups'] ?? null;
        if (!is_array($groups)) {
            return Response::json(['ok' => false, 'error' => 'invalid_groups'], 400);
        }
        $clean = $this->sanitizeGroups($groups);
        if ($clean === null) {
            return Response::json(['ok' => false, 'error' => 'invalid_shape'], 422);
        }
        try {
            $this->svc->saveAdminDefaults($clean);
            return Response::json(['ok' => true]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /** Valida/normalizza la forma `{ groupKey: [ {label,type,trigger|keys,snippet,desc} ] }`. */
    private function sanitizeGroups($groups): ?array
    {
        if (!is_array($groups)) {
            return null;
        }
        $out = [];
        foreach ($groups as $groupKey => $items) {
            $gk = trim((string)$groupKey);
            if ($gk === '' || !is_array($items)) {
                continue;
            }
            $cleanItems = [];
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $label = trim((string)($it['label'] ?? ''));
                $type  = (string)($it['type'] ?? '');
                $snip  = (string)($it['snippet'] ?? '');
                if ($label === '' || !in_array($type, ['hotstring', 'hotkey'], true) || $snip === '') {
                    continue;
                }
                if (strlen($snip) > 8192) {
                    continue;
                }
                $entry = ['label' => $label, 'type' => $type, 'snippet' => $snip];
                if ($type === 'hotstring') {
                    $trg = trim((string)($it['trigger'] ?? ''));
                    if ($trg === '') {
                        continue;
                    }
                    $entry['trigger'] = substr($trg, 0, 64);
                } else {
                    $keys = strtolower(trim((string)($it['keys'] ?? '')));
                    if ($keys === '') {
                        continue;
                    }
                    $entry['keys'] = substr($keys, 0, 64);
                }
                $entry['desc'] = substr((string)($it['desc'] ?? ''), 0, 256);
                $cleanItems[] = $entry;
            }
            if ($cleanItems) {
                $out[$gk] = $cleanItems;
            }
        }
        return $out ?: null;
    }
}
