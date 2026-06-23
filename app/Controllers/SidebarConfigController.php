<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Repositories\SidebarSectionRepository;

/**
 * ADR-027 Step 4 — GET /api/sidebar/config.
 *
 * Espone le sezioni sidebar risolte (template istituto + override docente)
 * filtrate per il RUOLO + ISTITUTO del chiamante, nella forma attesa dal
 * registry client (sidepage-registry.hydrate). Serve a rendere funzionali le
 * sezioni custom configurate dall'admin senza hardcodare il registry JS.
 *
 * Sicurezza: solo le sezioni visibili al ruolo (visible_roles); niente campi
 * di edit. Istituto-scoped (no leak cross-tenant). Cache privata per ruolo.
 */
final class SidebarConfigController
{
    public function config(Request $req): Response
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            // fallback client (base hardcoded) gestisce l'assenza
            return Response::json(['ok' => true, 'sections' => []]);
        }
        $u = Auth::user();
        $role = $this->role();
        $instituteId = $this->instituteId($u, $role);
        $teacherId = in_array($role, ['teacher', 'admin'], true) ? (int)($u['id'] ?? 0) : null;

        $sections = (new SidebarSectionRepository())
            ->forRender($instituteId, $teacherId ?: null, $role);

        $out = array_map(static function (array $s): array {
            return [
                'key'              => $s['section_key'],
                'panel'            => 'fm-sp-' . $s['section_key'],
                'loader'           => $s['loader_kind'] === 'db' ? 'db' : 'risdoc',
                'type'             => $s['default_content_type'],
                'group'            => $s['group_mode'],
                'allowedTypes'     => $s['allowed_content_types'],
                'customCategories' => (bool)$s['custom_categories'],
                'allowTemplateFork' => (bool)($s['allow_template_fork'] ?? false),
                'lockDefaultCategories' => (bool)($s['lock_default_categories'] ?? true),
                'lockCustomCategories'  => (bool)($s['lock_custom_categories'] ?? false),
                'templateOrigin'   => $s['template_origin'] ?? null,
                'templateGroups'   => $s['template_groups'] ?? [],
                'origin'           => $s['origin'],
                'categories'       => $s['default_categories'] ?: null,
                'supportsFork'     => (bool)$s['supports_fork'],
            ];
        }, $sections);

        $resp = Response::json(['ok' => true, 'sections' => $out]);
        $resp->headers['Cache-Control'] = 'private, max-age=60';
        return $resp;
    }

    private function role(): string
    {
        if (Auth::check() && Auth::hasAccess('admin')) {
            return 'admin';
        }
        return Auth::role() === 'teacher' ? 'teacher' : 'student';
    }

    private function instituteId(?array $u, string $role): int
    {
        $uid = (int)($u['id'] ?? 0);
        if ($uid <= 0) {
            return 0;
        }
        try {
            if ($role === 'student') {
                $stmt = Database::connection()->prepare('SELECT institute_id FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([$uid]);
                return (int)$stmt->fetchColumn();
            }
            return (int)(Auth::currentInstitute() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
