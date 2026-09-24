<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder\BadgeStyle;
use App\Services\TexBuilder\BadgeStylePresetStore;
use App\Repositories\TexBuilder\BadgeStyleRepository;
use App\Support\TeacherContextResolver;
use Throwable;

/**
 * G27.badge.style — Endpoints admin (preset management) + teacher
 * (preference + list presets).
 *
 * Admin (richiede Auth::hasAccess('admin')):
 *   GET    /api/admin/badge-style-presets[?scope=_default]
 *   GET    /api/admin/badge-style-presets/{name}[?scope=_default]
 *   PUT    /api/admin/badge-style-presets/{name}[?scope=_default]
 *   DELETE /api/admin/badge-style-presets/{name}[?scope=_default]
 *
 * Teacher (richiede teacher autenticato):
 *   GET /api/teacher/badge-style              → {preset, overrides, presets:[...], resolved:{...}}
 *   PUT /api/teacher/badge-style              → body {preset, overrides}
 */
final class BadgeStyleController
{
    // ─────────────────────── ADMIN ─────────────────────────────────────────

    public function adminList(Request $req): Response
    {
        if (!self::isAdmin()) {
            return self::deny();
        }
        $scope = self::scopeFromQuery($req);
        try {
            $names = BadgeStylePresetStore::listAvailable($scope);
            return Response::json(['scope' => $scope, 'presets' => $names]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'list_failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function adminGet(Request $req, array $params): Response
    {
        if (!self::isAdmin()) {
            return self::deny();
        }
        $name  = (string)($params['name'] ?? '');
        $scope = self::scopeFromQuery($req);
        try {
            $style = BadgeStylePresetStore::loadPreset($scope, $name);
            return Response::json([
                'scope' => $scope,
                'name'  => $name,
                'style' => $style->toArray(),
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'load_failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function adminPut(Request $req, array $params): Response
    {
        if (!self::isAdmin()) {
            return self::deny();
        }
        $name  = (string)($params['name'] ?? '');
        $scope = self::scopeFromQuery($req);
        // Dal lettore comune (ADR-034, A-52): oltre 32 KB 413, non JSON 400.
        $data = $req->jsonObbligatorio(32768);
        try {
            $style = BadgeStyle::fromArray($data);
            BadgeStylePresetStore::savePreset($scope, $name, $style);
            return Response::json([
                'ok'    => true,
                'scope' => $scope,
                'name'  => $name,
                'style' => $style->toArray(),
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'save_failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function adminDelete(Request $req, array $params): Response
    {
        if (!self::isAdmin()) {
            return self::deny();
        }
        $name  = (string)($params['name'] ?? '');
        $scope = self::scopeFromQuery($req);
        try {
            BadgeStylePresetStore::deletePreset($scope, $name);
            return Response::json(['ok' => true, 'scope' => $scope, 'name' => $name]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'delete_failed', 'message' => $e->getMessage()], 400);
        }
    }

    // ─────────────────────── TEACHER ───────────────────────────────────────

    public function teacherGet(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
        if ($tid <= 0) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = TeacherContextResolver::privateFilesInstituteId($tid);
        if ($iid <= 0) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }
        $instituteCode = TeacherContextResolver::instituteCodeForTeacher($tid);

        $pref = BadgeStyleRepository::loadPreference($iid, $tid);
        $resolved = BadgeStyleRepository::loadResolved($iid, $tid, $instituteCode);
        $presets  = BadgeStylePresetStore::listAvailable($instituteCode);

        return Response::json([
            'preset'    => $pref['preset'],
            'overrides' => $pref['overrides'],
            'presets'   => $presets,
            'resolved'  => $resolved->toArray(),
            // metadata UI: defaults hardcoded della macro per "reset" client
            'defaults'  => (new BadgeStyle())->toArray(),
            'sizes'     => BadgeStyle::SIZES,
        ]);
    }

    public function teacherPut(Request $req): Response
    {
        $u = Auth::user();
        if (!$u) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $tid = TeacherContextResolver::userIdFromUsername((string)($u['username'] ?? ''));
        if ($tid <= 0) {
            return Response::json(['error' => 'teacher_not_found'], 404);
        }
        $iid = TeacherContextResolver::privateFilesInstituteId($tid);
        if ($iid <= 0) {
            return Response::json(\App\Services\SenzaScuola::risposta(), 404);
        }

        // Dal lettore comune (ADR-034, A-52): oltre 16 KB 413, non JSON 400.
        $data = $req->jsonObbligatorio(16384);
        $preset    = (string)($data['preset']    ?? BadgeStylePresetStore::PRESET_DEFAULT);
        $overrides = (array)  ($data['overrides'] ?? []);
        try {
            BadgeStyleRepository::savePreference($iid, $tid, $preset, $overrides);
            $instituteCode = TeacherContextResolver::instituteCodeForTeacher($tid);
            $resolved = BadgeStyleRepository::loadResolved($iid, $tid, $instituteCode);
            return Response::json([
                'ok'       => true,
                'preset'   => $preset,
                'overrides' => $overrides,
                'resolved' => $resolved->toArray(),
            ]);
        } catch (Throwable $e) {
            return Response::json(['error' => 'save_failed', 'message' => $e->getMessage()], 400);
        }
    }

    // ─────────────────────── helpers ───────────────────────────────────────

    private static function isAdmin(): bool
    {
        $u = Auth::user();
        return $u !== null && !empty($u['username']) && Auth::hasAccess('admin');
    }

    private static function deny(): Response
    {
        return Response::json(['error' => 'forbidden'], 403);
    }

    /** Estrae scope dalla query string. Default _default. */
    private static function scopeFromQuery(Request $req): string
    {
        $scope = trim((string)($req->query['scope'] ?? BadgeStylePresetStore::SCOPE_DEFAULT));
        if ($scope === '') {
            $scope = BadgeStylePresetStore::SCOPE_DEFAULT;
        }
        return $scope;
    }
}
