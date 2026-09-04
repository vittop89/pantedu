<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Repositories\SidebarSectionRepository;
use App\Repositories\TeacherContentRepository;

/**
 * ADR-027 Step 7 — UI super_admin per la configurazione della sidebar.
 *
 * Opera sul template GLOBALE (institute_id = 0): rinomina, colore, visibilità
 * agli studenti, attivazione, ordine, aggiunta/rimozione di sezioni custom.
 * Lo scoping per-istituto è una raffinazione successiva.
 *
 * Route nel group `auth + role:admin + super_admin_required`.
 */
final class AdminSidebarConfigController
{
    private SidebarSectionRepository $repo;

    public function __construct(?SidebarSectionRepository $repo = null)
    {
        $this->repo = $repo ?? new SidebarSectionRepository();
    }

    /** GET /admin/sidebar-config */
    public function page(Request $req): Response
    {
        $view = View::default();
        // WS4 — arricchisci ogni sezione con i docenti assegnati (colonna 'docenti').
        $sections = $this->repo->listForAdmin(0);
        $assign = $this->repo->assignmentsFor(array_map(fn($s) => (int)$s['id'], $sections));
        foreach ($sections as &$_s) {
            $_s['teacher_ids'] = $assign[(int)$_s['id']] ?? [];
        }
        unset($_s);
        $body = $view->render('admin/sidebar-config', [
            'csrf'            => Csrf::token(),
            'sections'        => $sections,
            'teachers'        => $this->teacherList(),
            'institutes'      => $this->instituteList(),
            'all_types'       => TeacherContentRepository::TYPES,
            'template_groups' => $this->availableTemplateGroups(),
            'flash'           => (string)($req->query['flash'] ?? ''),
            'flash_kind'      => (string)($req->query['kind'] ?? 'info'),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Configurazione sidebar — Admin',
            'body'  => $body,
        ]));
    }

    /** WS4 — elenco docenti (+ super-admin) per la colonna 'docenti'. @return list<array{id:int,label:string}> */
    private function teacherList(): array
    {
        if (!\App\Core\Database::isAvailable()) {
            return [];
        }
        try {
            $rows = \App\Core\Database::connection()->query(
                "SELECT id, username, first_name, last_name FROM users
                 WHERE role IN ('teacher','collaborator') OR is_super_admin = 1
                 ORDER BY last_name, first_name, username"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_map(static function ($r) {
                $name = trim((string)($r['last_name'] ?? '') . ' ' . (string)($r['first_name'] ?? ''));
                return ['id' => (int)$r['id'], 'label' => $name !== '' ? $name : (string)$r['username']];
            }, $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** WS4 — elenco istituti per la modalità 'scope'. @return list<array{id:int,label:string}> */
    private function instituteList(): array
    {
        if (!\App\Core\Database::isAvailable()) {
            return [];
        }
        try {
            $rows = \App\Core\Database::connection()->query(
                "SELECT id, COALESCE(NULLIF(name,''), code) AS label FROM institutes WHERE active = 1 ORDER BY label"
            )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            return array_map(static fn($r) => ['id' => (int)$r['id'], 'label' => (string)$r['label']], $rows);
        } catch (\Throwable) {
            return [];
        }
    }

    /** POST /admin/sidebar-config/save — upsert di una sezione (globale). */
    public function save(Request $req): Response
    {
        try {
            $key = $this->slug((string)($req->post['section_key'] ?? ''));
            $label = trim((string)($req->post['label'] ?? ''));
            if ($key === '' || $label === '') {
                return $this->back('section_key e label obbligatori', 'error');
            }
            $isNew = ($req->post['_new'] ?? '') === '1';
            // Le 6 default non possono cambiare key/loader/group/tipi: si edita solo
            // estetica/visibilità/posizione/active. Per le custom si accetta tutto.
            $existing = $this->repo->listForAdmin(0);
            $existingByKey = [];
            foreach ($existing as $s) {
                $existingByKey[$s['section_key']] = $s;
            }
            $cur = $existingByKey[$key] ?? null;

            if ($isNew && $cur) {
                return $this->back("La sezione '$key' esiste già", 'error');
            }
            if ($isNew && in_array($key, ['mappe','lab','eser','verif','bes','risdoc'], true)) {
                return $this->back("'$key' è una key riservata", 'error');
            }

            $color = $this->hexOrNull((string)($req->post['color'] ?? ''));
            if (($req->post['color'] ?? '') !== '' && $color === null) {
                return $this->back('Colore non valido (atteso #rrggbb)', 'error');
            }
            $visibleStudent = !empty($req->post['visible_student']);
            $visibleRoles = $visibleStudent ? ['student','teacher','admin'] : ['teacher','admin'];

            // ADR-027 unificazione — distinzioni significative: VISUALIZZAZIONE
            // (group_mode) + FORK template (allow_template_fork + origine). Niente
            // più scelta di content_type: ogni sezione ammette tutti i tipi (il
            // formato deriva dal Modello documento al momento della creazione).
            $group = in_array(($req->post['group_mode'] ?? ''), ['subject','category'], true)
                ? (string)$req->post['group_mode'] : 'category';
            // ADR-027 — partizioni template FLAT (category) selezionate per il fork.
            $groups = array_values(array_filter(
                (array)($req->post['template_groups'] ?? []),
                fn($g) => is_string($g) && preg_match('#^[A-Za-z0-9_ -]{1,64}$#', $g)
            ));
            $allowFork = !empty($req->post['allow_template_fork']) || !empty($groups);
            // Phase 25 — lock categorie predefinite (rinomina/elimina dal docente).
            $lockDefaultCats = !empty($req->post['lock_default_categories']);
            // Phase 25 — blocca creazione categorie custom dal docente.
            $lockCustomCats = !empty($req->post['lock_custom_categories']);
            // Phase 25 — categorie predefinite editate dall'admin (SSOT documenti).
            // Solo in modalità "per categoria". Sanitizzate + deduplicate, ordine
            // preservato. In "per materia" → null (le categorie non si applicano:
            // il banner client avvisa della perdita prima del cambio).
            $defaultCats = null;
            if ($group === 'category') {
                $seen = [];
                foreach ((array)($req->post['default_categories'] ?? []) as $c) {
                    $c = trim((string)$c);
                    if ($c === '' || !preg_match('#^[A-Za-z0-9_ -]{1,32}$#', $c)) {
                        continue;
                    }
                    if (isset($seen[$c])) {
                        continue;
                    }
                    $seen[$c] = true;
                    $defaultCats[] = $c;
                }
            }
            $origin = trim((string)($req->post['template_origin'] ?? ''));  // legacy, non più derivato dai gruppi
            $loader = $allowFork ? 'risdoc' : 'db';   // loader derivato (interno)
            $allTypes = TeacherContentRepository::TYPES;

            if ($cur && $cur['is_default']) {
                // default: estetica/visibilità/ordine/active + visualizzazione + fork.
                // key resta di sistema.
                $data = $cur;
                $data['label']               = $label;
                $data['color']               = $color;
                $data['visible_roles']       = $visibleRoles;
                $data['position']            = (int)($req->post['position'] ?? $cur['position']);
                $data['active']              = !empty($req->post['active']);
                $data['group_mode']          = $group;
                $data['loader_kind']         = $loader;
                $data['custom_categories']   = $group === 'category';
                $data['default_categories']  = $defaultCats;
                $data['allow_template_fork'] = $allowFork;
                $data['lock_default_categories'] = $lockDefaultCats;
                $data['lock_custom_categories']  = $lockCustomCats;
                $data['template_origin']     = $allowFork ? ($origin ?: 'risdoc') : null;
                $data['template_groups']     = $allowFork ? $groups : null;
                $data['allowed_content_types'] = $allTypes;
            } else {
                // custom (nuova o esistente)
                $data = [
                    'section_key'           => $key,
                    'label'                 => $label,
                    'color'                 => $color,
                    'position'              => (int)($req->post['position'] ?? 99),
                    'loader_kind'           => $loader,
                    'group_mode'            => $group,
                    'allowed_content_types' => $allTypes,
                    'default_content_type'  => 'document',
                    'custom_categories'     => $group === 'category',
                    'default_categories'    => $defaultCats,
                    'allow_template_fork'   => $allowFork,
                    'lock_default_categories' => $lockDefaultCats,
                    'lock_custom_categories'  => $lockCustomCats,
                    'template_origin'       => $allowFork ? ($origin ?: 'risdoc') : null,
                    'template_groups'       => $allowFork ? $groups : null,
                    'visible_roles'         => $visibleRoles,
                    'active'                => $isNew ? true : !empty($req->post['active']),
                    'is_default'            => false,
                ];
            }
            // WS4 — pubblicazione pubblica (no login) + modalità 'docenti'.
            $data['publish_public'] = !empty($req->post['publish_public']);
            $scope = in_array(($req->post['teacher_scope'] ?? 'all'), ['all', 'scope', 'teachers'], true)
                ? (string)$req->post['teacher_scope'] : 'all';
            $data['teacher_scope'] = $scope;
            // 'scope' = istituto/indirizzo/classe (dimensioni vuote = nessun vincolo).
            $data['teacher_scope_value'] = $scope === 'scope' ? array_filter([
                'institute_id' => (int)($req->post['scope_institute_id'] ?? 0) ?: null,
                'indirizzo'    => trim((string)($req->post['scope_indirizzo'] ?? '')) ?: null,
                'classe'       => trim((string)($req->post['scope_classe'] ?? '')) ?: null,
            ], fn($v) => $v !== null) : null;
            $id = $this->repo->upsert($data, 0);
            // pivot docenti solo in modalità 'teachers'; altrimenti svuota.
            $teacherIds = $scope === 'teachers' ? array_map('intval', (array)($req->post['teacher_ids'] ?? [])) : [];
            $this->repo->setTeacherIds($id, $teacherIds);
            return $this->back('Sezione salvata', 'success');
        } catch (\Throwable $e) {
            return $this->back('Errore: ' . $e->getMessage(), 'error');
        }
    }

    /** POST /admin/sidebar-config/delete — elimina una sezione custom. */
    public function delete(Request $req): Response
    {
        $id = (int)($req->post['id'] ?? 0);
        $ok = $id > 0 && $this->repo->delete($id);
        return $this->back($ok ? 'Sezione eliminata' : 'Impossibile eliminare (default o inesistente)', $ok ? 'success' : 'error');
    }

    /** POST /admin/sidebar-config/reorder — body: order[]=id in nuovo ordine. */
    public function reorder(Request $req): Response
    {
        $order = (array)($req->post['order'] ?? []);
        $map = [];
        foreach (array_values($order) as $pos => $id) {
            $map[(int)$id] = $pos;
        }
        $this->repo->setPositions($map);
        return Response::json(['ok' => true]);
    }

    /**
     * Gruppi template disponibili (category = partizione) da risdoc_templates,
     * per il multi-select del fork. (Phase 24.58 — colonna `origin` rimossa.)
     * @return list<array{key:string,label:string}>
     */
    private function availableTemplateGroups(): array
    {
        try {
            // ADR-027 — partizioni template FLAT = category (modelli/risorse/altro/bes).
            $stmt = \App\Core\Database::connection()->query(
                "SELECT DISTINCT category FROM risdoc_templates WHERE category <> '' ORDER BY category"
            );
            $out = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $cat) {
                $out[] = ['key' => (string)$cat, 'label' => mb_strtoupper((string)$cat)];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function back(string $msg, string $kind): Response
    {
        return Response::redirect('/admin/sidebar-config?flash=' . urlencode($msg) . '&kind=' . $kind);
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s));
        $s = (string)preg_replace('/[^a-z0-9_-]/', '', $s);
        return substr($s, 0, 32);
    }

    private function hexOrNull(string $s): ?string
    {
        $s = trim($s);
        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $s) ? $s : null;
    }
}
