<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\TexBuilder\BadgeStyle;
use App\Services\TexBuilder\BadgeStylePresetStore;
use App\Services\TexBuilder\BadgeStyleRepository;
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
        $scope = self::scopeFromQuery();
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
        $scope = self::scopeFromQuery();
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
        $scope = self::scopeFromQuery();
        $raw = (string)@file_get_contents('php://input');
        if (\strlen($raw) > 32768) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return Response::json(['error' => 'invalid_json'], 422);
        }
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
        $scope = self::scopeFromQuery();
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
        $iid = TeacherContextResolver::firstInstituteId($tid);
        if ($iid <= 0) {
            return Response::json(['error' => 'institute_not_found'], 404);
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
        $iid = TeacherContextResolver::firstInstituteId($tid);
        if ($iid <= 0) {
            return Response::json(['error' => 'institute_not_found'], 404);
        }

        $raw = (string)@file_get_contents('php://input');
        if (\strlen($raw) > 16384) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        $data = json_decode($raw, true);
        if (!\is_array($data)) {
            return Response::json(['error' => 'invalid_json'], 422);
        }
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
    private static function scopeFromQuery(): string
    {
        $scope = trim((string)($_GET['scope'] ?? BadgeStylePresetStore::SCOPE_DEFAULT));
        if ($scope === '') {
            $scope = BadgeStylePresetStore::SCOPE_DEFAULT;
        }
        return $scope;
    }
}
