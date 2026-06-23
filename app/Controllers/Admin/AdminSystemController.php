<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Support\DeploymentMode;

/**
 * Phase S2 F3 (ADR-017) — Pannello /admin/system/deployment.
 *
 * UI super_admin per:
 *   - Visualizzare stato corrente (mode + DPO + institute name + source)
 *   - Switch single → institute via wizard (richiede DPO email + ragione sociale)
 *   - Switch institute → single (consentito SOLO se attivi <= 1 utente, per
 *     evitare di "dimenticare" account orfani)
 *   - Reset al default env (rimuove runtime override)
 *
 * Persistenza: storage/config/deployment.json (atomic write).
 * Audit: ogni switch logga in privileged_access_log con action='deployment_mode_switch'.
 */
final class AdminSystemController
{
    /** GET /admin/system/deployment */
    public function deploymentPage(Request $req): Response
    {
        $view = View::default();
        $snap = DeploymentMode::snapshot();

        // Active user count (escluso superadmin) per safety check down-switch
        $activeUsers = $this->activeUserCount();

        $body = $view->render('admin/system/deployment', [
            'csrf'              => Csrf::token(),
            'snapshot'          => $snap,
            'active_users'      => $activeUsers,
            'flash'             => (string)($req->query['flash'] ?? ''),
            'flash_kind'        => (string)($req->query['kind']  ?? 'info'),
            // WS3 — modalità acquisizione dati registrazione studenti.
            'student_reg'       => \App\Support\StudentRegistration::snapshot(),
            // ADR-028 Fase 1 — classi ammesse alla registrazione (trasversale, ogni istituto).
            'allowed_classes'   => (new \App\Services\RegistrationPolicy())->allAny(),
            'institutes'        => $this->institutesForSelect(),
            // Esempi placeholder DINAMICI dai codici realmente in uso (no hardcoded
            // legacy "1s/2s/3s" — i codici indirizzo/classe sono liberi per istituto).
            'code_samples'      => $this->registrationCodeSamples(),
            // ADR-028 Fase 4 — governance capabilities (solo in INSTITUTE).
            'cap_profiles'      => DeploymentMode::isInstitute()
                ? (new \App\Services\TeacherCapabilityPolicy())->listProfiles() : [],
            'cap_teachers'      => DeploymentMode::isInstitute() ? $this->teacherUsers() : [],
            'cap_doc_types'     => ['mappa', 'esercizio', 'verifica', 'document', 'fork', 'link', 'custom'],
            // Sezioni reali (section_key + label) per le checkbox del profilo,
            // così non si scrive il section_key a mano e le sezioni nuove
            // compaiono da sole. Vuoto in SINGLE.
            'cap_sections'      => DeploymentMode::isInstitute() ? $this->sidebarSectionsList() : [],
        ]);

        return Response::html($view->render('layout/shell', [
            'title' => 'Deployment Mode — Admin',
            'body'  => $body,
        ]));
    }

    /** POST /admin/system/deployment/switch */
    public function deploymentSwitch(Request $req): Response
    {
        $action = (string)($req->post['action'] ?? '');
        $actor  = Auth::user()['username'] ?? '?';

        try {
            switch ($action) {
                case 'to_institute':
                    $email = trim((string)($req->post['institute_owner_email'] ?? ''));
                    $name  = trim((string)($req->post['institute_legal_name']  ?? ''));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return $this->redirectFlash('invalid_email', 'error');
                    }
                    if ($name === '' || strlen($name) > 255) {
                        return $this->redirectFlash('invalid_name', 'error');
                    }
                    DeploymentMode::persistRuntime([
                        'mode'                  => DeploymentMode::INSTITUTE,
                        'institute_owner_email' => $email,
                        'institute_legal_name'  => $name,
                    ]);
                    $this->auditSwitch($actor, 'single→institute', "email=$email; name=$name");
                    return $this->redirectFlash('switched_to_institute', 'ok');

                case 'to_single':
                    // Safety: bloccato se > 1 utente attivo
                    $count = $this->activeUserCount();
                    if ($count > 1) {
                        return $this->redirectFlash('down_switch_blocked', 'error');
                    }
                    DeploymentMode::persistRuntime([
                        'mode'                  => DeploymentMode::SINGLE,
                        'institute_owner_email' => '',
                        'institute_legal_name'  => '',
                    ]);
                    $this->auditSwitch($actor, 'institute→single', "active_users=$count");
                    return $this->redirectFlash('switched_to_single', 'ok');

                case 'reset_to_env':
                    // Rimuove runtime override, ricasca su .env
                    $path = (string) \App\Core\Config::get('app.paths.storage') . '/config/deployment.json';
                    if (is_file($path)) {
                        @unlink($path);
                    }
                    DeploymentMode::resetCache();
                    $this->auditSwitch($actor, 'reset_to_env', 'runtime override removed');
                    return $this->redirectFlash('reset_done', 'ok');

                default:
                    return $this->redirectFlash('invalid_action', 'error');
            }
        } catch (\Throwable $e) {
            error_log('[admin/system/deployment] ' . $e->getMessage());
            return $this->redirectFlash('exception', 'error');
        }
    }

    /**
     * Esempi dinamici di codici indirizzo/classe realmente presenti nel sistema,
     * per i placeholder del form (evita suggerimenti legacy hardcoded).
     * @return array{indirizzi:list<string>,classi:list<string>}
     */
    private function registrationCodeSamples(): array
    {
        $fallback = ['indirizzi' => [], 'classi' => []];
        if (!\App\Core\Config::get('database.enabled')) {
            return $fallback;
        }
        try {
            $pdo = Database::connection();
            $ind = $pdo->query(
                "SELECT DISTINCT indirizzo FROM teacher_content
                 WHERE indirizzo IS NOT NULL AND indirizzo <> '' ORDER BY indirizzo LIMIT 3"
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $cls = $pdo->query(
                "SELECT DISTINCT classe FROM teacher_content
                 WHERE classe IS NOT NULL AND classe <> '' ORDER BY classe LIMIT 3"
            )->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            return [
                'indirizzi' => array_map('strval', $ind),
                'classi'    => array_map('strval', $cls),
            ];
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /** POST /admin/system/registration-classes/add — ADR-028 Fase 1 */
    public function registrationClassAdd(Request $req): Response
    {
        $ind = trim((string)($req->post['indirizzo'] ?? ''));
        $cls = trim((string)($req->post['classe'] ?? ''));
        if ($ind === '' || $cls === '') {
            return $this->redirectFlash('class_invalid', 'error');
        }
        $inst = (int)($req->post['institute_id'] ?? 0);
        $by = (string)(Auth::user()['username'] ?? '?');
        $ok = (new \App\Services\RegistrationPolicy())->add($ind, $cls, $inst > 0 ? $inst : null, $by);
        return $this->redirectFlash($ok ? 'class_added' : 'class_invalid', $ok ? 'ok' : 'error');
    }

    /** WS4 — istituti per il selettore "Classi ammesse". @return list<array{id:int,label:string}> */
    private function institutesForSelect(): array
    {
        if (!\App\Core\Config::get('database.enabled')) {
            return [];
        }
        try {
            $rows = Database::connection()->query(
                "SELECT id, COALESCE(NULLIF(name,''), code) AS label, code FROM institutes WHERE active = 1 ORDER BY label"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_map(static fn($r) => [
                'id'    => (int)$r['id'],
                'label' => (string)$r['label'],
                'code'  => (string)$r['code'],
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** POST /admin/system/registration-mode — WS3: modalità dati registrazione studenti */
    public function registrationModeSet(Request $req): Response
    {
        $mode   = (string)($req->post['student_reg_mode'] ?? \App\Support\StudentRegistration::FULL);
        $onlySa = !empty($req->post['only_superadmin_classes']);
        $actor  = (string)(Auth::user()['username'] ?? '?');
        try {
            \App\Support\StudentRegistration::persist($mode, $onlySa);
            // "Solo classi del super-admin": ripopola l'allowlist (registration_allowed_classes)
            // con le coppie (indirizzo,classe) distinte dei contenuti del super-admin.
            if ($onlySa) {
                $this->syncSuperadminAllowedClasses($actor);
            }
            $this->auditSwitch($actor, 'student_registration_mode', "mode={$mode}; only_sa=" . ($onlySa ? '1' : '0'));
            return $this->redirectFlash('reg_mode_saved', 'ok');
        } catch (\Throwable $e) {
            error_log('[admin/system/registration-mode] ' . $e->getMessage());
            return $this->redirectFlash('exception', 'error');
        }
    }

    /** Ripopola l'allowlist classi con quelle del super-admin (is_super_admin=1). */
    private function syncSuperadminAllowedClasses(string $actor): void
    {
        if (!\App\Core\Config::get('database.enabled')) {
            return;
        }
        $pdo  = Database::connection();
        $saId = (int)($pdo->query('SELECT id FROM users WHERE is_super_admin=1 ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
        if ($saId <= 0) {
            return;
        }
        $stmt = $pdo->prepare(
            "SELECT DISTINCT indirizzo, classe FROM teacher_content
             WHERE teacher_id = ? AND indirizzo IS NOT NULL AND indirizzo <> ''
               AND classe IS NOT NULL AND classe <> ''"
        );
        $stmt->execute([$saId]);
        $pairs  = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $policy = new \App\Services\RegistrationPolicy();
        foreach ($policy->all() as $row) {            // reset allowlist esistente
            $policy->remove((int)$row['id']);
        }
        foreach ($pairs as $p) {                      // popola con le classi del super-admin
            $policy->add((string)$p['indirizzo'], (string)$p['classe'], null, $actor);
        }
    }

    /** POST /admin/system/registration-classes/remove — ADR-028 Fase 1 */
    public function registrationClassRemove(Request $req): Response
    {
        $id = (int)($req->post['id'] ?? 0);
        if ($id <= 0) {
            return $this->redirectFlash('class_invalid', 'error');
        }
        (new \App\Services\RegistrationPolicy())->remove($id);
        return $this->redirectFlash('class_removed', 'ok');
    }

    // ── ADR-028 Fase 4 — Governance capabilities (admin UI) ──

    /** @return list<array<string,mixed>> docenti/collaboratori attivi + profilo assegnato */
    private function teacherUsers(): array
    {
        if (!\App\Core\Config::get('database.enabled')) {
            return [];
        }
        try {
            $stmt = Database::connection()->query(
                "SELECT id, username, role, capability_profile_id,
                        TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) AS name
                 FROM users
                 WHERE role IN ('teacher','collaborator') AND active=1 AND deleted_at IS NULL
                 ORDER BY role, username"
            );
            return array_map(static fn($r) => [
                'id'         => (int)$r['id'],
                'username'   => (string)$r['username'],
                'role'       => (string)$r['role'],
                'name'       => trim((string)$r['name']),
                'profile_id' => $r['capability_profile_id'] !== null ? (int)$r['capability_profile_id'] : null,
            ], $stmt ? ($stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []) : []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Sezioni sidebar reali (section_key + label) per le checkbox dei profili.
     * @return list<array{section_key:string,label:string}>
     */
    private function sidebarSectionsList(): array
    {
        try {
            $rows = (new \App\Repositories\SidebarSectionRepository())->listForAdmin(0);
            return array_map(static fn($s) => [
                'section_key' => (string)$s['section_key'],
                'label'       => (string)($s['label'] ?? $s['section_key']),
            ], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Set dei section_key validi (per validare il salvataggio profili). */
    private function validSectionKeys(): array
    {
        return array_map(static fn($s) => $s['section_key'], $this->sidebarSectionsList());
    }

    /** POST /admin/system/capability/profile/save */
    public function capabilityProfileSave(Request $req): Response
    {
        $id   = (int)($req->post['id'] ?? 0) ?: null;
        $name = trim((string)($req->post['name'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            return $this->redirectFlash('cap_invalid', 'error');
        }
        $allTypes = ['mappa', 'esercizio', 'verifica', 'document', 'fork', 'link', 'custom'];
        $docTypes = array_values(array_intersect($allTypes, (array)($req->post['doc_types'] ?? [])));
        $mode = in_array(($req->post['sidebar_mode'] ?? 'all'), ['all', 'allow', 'deny'], true)
            ? (string)$req->post['sidebar_mode'] : 'all';
        // Sezioni: ora arrivano come array di checkbox (sidebar_sections[]).
        // Validate contro le section_key reali (scarta orfane/typo). Back-compat:
        // accetta anche la vecchia stringa CSV.
        $rawSections = $req->post['sidebar_sections'] ?? [];
        if (is_string($rawSections)) {
            $rawSections = array_map('trim', explode(',', $rawSections));
        }
        $valid = $this->validSectionKeys();
        $sections = array_values(array_intersect(
            array_filter(array_map('strval', (array)$rawSections)),
            $valid ?: (array)$rawSections // se il lookup fallisce, non scartare
        ));
        $maxVis = in_array(($req->post['max_visibility'] ?? 'general'), ['class', 'classes', 'general'], true)
            ? (string)$req->post['max_visibility'] : 'general';
        $caps = [
            'sidebar'            => ['mode' => $mode, 'sections' => $sections],
            'can_create_section' => !empty($req->post['can_create_section']),
            'doc_types'          => $docTypes,
            'max_visibility'     => $maxVis,
        ];
        try {
            (new \App\Services\TeacherCapabilityPolicy())->saveProfile($id, $name, $caps);
            return $this->redirectFlash('cap_saved', 'ok');
        } catch (\Throwable $e) {
            error_log('[capabilityProfileSave] ' . $e->getMessage());
            return $this->redirectFlash('cap_invalid', 'error');
        }
    }

    /** POST /admin/system/capability/profile/delete */
    public function capabilityProfileDelete(Request $req): Response
    {
        $id = (int)($req->post['id'] ?? 0);
        $ok = $id > 0 && (new \App\Services\TeacherCapabilityPolicy())->deleteProfile($id);
        return $this->redirectFlash($ok ? 'cap_deleted' : 'cap_invalid', $ok ? 'ok' : 'error');
    }

    /** POST /admin/system/capability/assign */
    public function capabilityAssign(Request $req): Response
    {
        $userId    = (int)($req->post['user_id'] ?? 0);
        $profileId = (int)($req->post['profile_id'] ?? 0) ?: null;
        if ($userId <= 0) {
            return $this->redirectFlash('cap_invalid', 'error');
        }
        (new \App\Services\TeacherCapabilityPolicy())->assignProfile($userId, $profileId);
        return $this->redirectFlash('cap_assigned', 'ok');
    }

    private function redirectFlash(string $msg, string $kind): Response
    {
        return Response::redirect('/admin/system/deployment?flash=' . urlencode($msg) . '&kind=' . urlencode($kind), 303);
    }

    /**
     * Conta utenti attivi (active=1 AND deleted_at IS NULL).
     * Esclude superadmin (sempre 1, considerato baseline).
     */
    private function activeUserCount(): int
    {
        if (!\App\Core\Config::get('database.enabled')) {
            return 0;
        }
        try {
            return (int) Database::connection()
                ->query('SELECT COUNT(*) FROM users WHERE active=1 AND deleted_at IS NULL AND is_super_admin=0')
                ->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function auditSwitch(string $actor, string $transition, string $details): void
    {
        try {
            if (!\App\Core\Config::get('database.enabled')) {
                return;
            }
            $userId   = (int)(Auth::user()['id']   ?? 0);
            $role     = (string)(Auth::user()['role'] ?? 'super_admin');
            $ip       = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : null;
            Database::connection()->prepare(
                'INSERT INTO privileged_access_log
                    (user_id, actor_name, actor_role, action,
                     resource_type, resource_id, reason, outcome, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?, NOW())'
            )->execute([
                $userId,
                $actor,
                $role,
                'deployment_mode_switch',
                'system.deployment',
                $transition . ' — ' . $details,
                'success',
                $ip,
            ]);
        } catch (\Throwable $e) {
            error_log('[deployment_audit] ' . $e->getMessage());
        }
    }
}
