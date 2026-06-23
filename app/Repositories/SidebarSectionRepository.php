<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * ADR-027 — Sidebar dinamica.
 *
 * Template per-istituto (`sidebar_sections`, institute_id=0 = default globale)
 * + override estetici per-docente (`sidebar_section_overrides`).
 *
 * resolveFor() applica il merge: globale → istituto → override docente.
 * Tutti i metodi sono no-op-safe se il DB non è disponibile o le tabelle non
 * esistono ancora (ritornano []): il caller (sidebar.php) fa fallback al
 * markup hardcoded.
 */
final class SidebarSectionRepository
{
    /** Ruoli ammessi nel campo visible_roles. */
    public const ROLES = ['student', 'teacher', 'admin'];

    /**
     * Sezioni risolte per (istituto, docente), già decodificate e ordinate
     * per position. Override docente applicati. Include sia attive che non
     * (filtra il caller via forRender o ->['active']).
     *
     * @return list<array<string,mixed>>
     */
    public function resolveFor(int $instituteId, ?int $teacherId = null): array
    {
        if (!Database::isAvailable()) {
            return [];
        }
        $pdo = Database::connection();
        try {
            // Globale (0) + istituto in una query; l'istituto vince per section_key.
            $stmt = $pdo->prepare(
                'SELECT * FROM sidebar_sections WHERE institute_id IN (0, ?) ORDER BY institute_id DESC, position'
            );
            $stmt->execute([$instituteId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // Tabella assente (migration non ancora applicata) → fallback caller.
            return [];
        }

        // Merge per section_key: institute_id DESC ⇒ la riga-istituto precede la
        // globale, quindi il primo visto per key vince.
        $byKey = [];
        foreach ($rows as $r) {
            $key = (string)$r['section_key'];
            if (!isset($byKey[$key])) {
                $byKey[$key] = $r;
            }
        }

        // Override per-docente sulle section_id risolte.
        $overrides = [];
        if ($teacherId && $byKey) {
            // array_values: $byKey è associativo (key=section_key); lo spread
            // ...$ids con chiavi-stringa romperebbe execute() (named-arg unpack).
            $ids = array_values(array_map(fn($r) => (int)$r['id'], $byKey));
            $in  = implode(',', array_fill(0, count($ids), '?'));
            try {
                $ostmt = $pdo->prepare(
                    "SELECT * FROM sidebar_section_overrides WHERE teacher_id = ? AND section_id IN ($in)"
                );
                $ostmt->execute([$teacherId, ...$ids]);
                foreach ($ostmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
                    $overrides[(int)$o['section_id']] = $o;
                }
            } catch (\Throwable $e) {
/* tabella override assente → ignora */
            }
        }

        $out = [];
        foreach ($byKey as $r) {
            $out[] = $this->shape($r, $overrides[(int)$r['id']] ?? null);
        }
        usort($out, fn($a, $b) => $a['position'] <=> $b['position']);

        // ADR-028 Fase 3 — Gate 2: visibilità sezioni per-docente. In INSTITUTE
        // il profilo del docente può limitare quali sezioni vede (oltre a
        // visible_roles). No-op in SINGLE (policy full-permissive).
        if ($teacherId) {
            $out = (new \App\Services\TeacherCapabilityPolicy($pdo))->filterSidebarSections($teacherId, $out);
        }
        return $out;
    }

    /**
     * Sezioni ATTIVE visibili a un ruolo, pronte per il render della sidebar.
     * admin vede sempre tutto (gestione).
     *
     * @return list<array<string,mixed>>
     */
    public function forRender(int $instituteId, ?int $teacherId, string $role): array
    {
        $list = array_values(array_filter(
            $this->resolveFor($instituteId, $teacherId),
            fn($s) => $s['active'] && ($role === 'admin' || in_array($role, $s['visible_roles'], true))
        ));
        // WS4 — gate 'docenti' per modalità teacher_scope (admin bypassa):
        //   all       → visibile a tutti i docenti
        //   indirizzo → solo ai docenti che hanno contenuti in teacher_scope_value
        //   teachers  → solo ai docenti elencati (pivot sidebar_section_teachers)
        if ($role !== 'admin' && $teacherId) {
            $list = array_values(array_filter($list, function ($s) use ($teacherId) {
                $scope = (string)($s['teacher_scope'] ?? 'all');
                if ($scope === 'scope') {
                    return $this->teacherMatchesScope((int)$teacherId, (array)($s['teacher_scope_value'] ?? []));
                }
                if ($scope === 'teachers') {
                    return in_array((int)$teacherId, $this->teacherIdsFor((int)$s['id']), true);
                }
                return true; // 'all'
            }));
        }
        return $list;
    }

    /**
     * Tutte le sezioni di un istituto (default 0 = template globale), incluse
     * le non attive, per l'editor admin. Già shaped, ordinate per position.
     *
     * @return list<array<string,mixed>>
     */
    public function listForAdmin(int $instituteId = 0): array
    {
        if (!Database::isAvailable()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sidebar_sections WHERE institute_id = ? ORDER BY position'
        );
        $stmt->execute([$instituteId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[] = $this->shape($r, null);
        }
        return $out;
    }

    /** Singola sezione per id (shaped) o null. */
    public function findById(int $id): ?array
    {
        if (!Database::isAvailable()) {
            return null;
        }
        $stmt = Database::connection()->prepare('SELECT * FROM sidebar_sections WHERE id = ?');
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        return $r ? $this->shape($r, null) : null;
    }

    /**
     * Upsert di una sezione template (per (institute_id, section_key)).
     * $data: section_key, label, color?, color_border?, position?, loader_kind?,
     * group_mode?, allowed_content_types(list), default_content_type, origin?,
     * default_categories(list)?, custom_categories?, supports_fork?,
     * visible_roles(list), active?. Ritorna l'id.
     */
    public function upsert(array $data, int $instituteId = 0): int
    {
        $pdo = Database::connection();
        $jsonOf = static fn($v) => json_encode(array_values((array)$v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $params = [
            'institute_id'          => $instituteId,
            'section_key'           => (string)$data['section_key'],
            'label'                 => (string)$data['label'],
            'icon'                  => $data['icon'] ?? null,
            'color'                 => ($data['color'] ?? '') !== '' ? (string)$data['color'] : null,
            'color_border'          => ($data['color_border'] ?? '') !== '' ? (string)$data['color_border'] : null,
            'position'              => (int)($data['position'] ?? 0),
            'loader_kind'           => (string)($data['loader_kind'] ?? 'db'),
            'group_mode'            => (string)($data['group_mode'] ?? 'subject'),
            'allowed_content_types' => $jsonOf($data['allowed_content_types'] ?? []),
            'default_content_type'  => (string)$data['default_content_type'],
            'origin'                => ($data['origin'] ?? '') !== '' ? (string)$data['origin'] : null,
            'default_categories'    => isset($data['default_categories']) ? $jsonOf($data['default_categories']) : null,
            'custom_categories'     => !empty($data['custom_categories']) ? 1 : 0,
            'supports_fork'         => !empty($data['supports_fork']) ? 1 : 0,
            'allow_template_fork'   => !empty($data['allow_template_fork']) ? 1 : 0,
            'lock_default_categories' => array_key_exists('lock_default_categories', $data)
                ? (int)(bool)$data['lock_default_categories'] : 1,
            'lock_custom_categories' => array_key_exists('lock_custom_categories', $data)
                ? (int)(bool)$data['lock_custom_categories'] : 0,
            'template_origin'       => ($data['template_origin'] ?? '') !== '' ? (string)$data['template_origin'] : null,
            'template_groups'       => !empty($data['template_groups']) && is_array($data['template_groups'])
                ? $jsonOf($data['template_groups']) : null,
            'visible_roles'         => $jsonOf($data['visible_roles'] ?? ['teacher', 'admin']),
            'publish_public'        => !empty($data['publish_public']) ? 1 : 0,
            'teacher_scope'         => in_array(($data['teacher_scope'] ?? 'all'), ['all','scope','teachers'], true) ? (string)$data['teacher_scope'] : 'all',
            'teacher_scope_value'   => (!empty($data['teacher_scope_value']) && is_array($data['teacher_scope_value'])) ? json_encode($data['teacher_scope_value'], JSON_UNESCAPED_UNICODE) : null,
            'active'                => array_key_exists('active', $data) ? (int)(bool)$data['active'] : 1,
            'is_default'            => !empty($data['is_default']) ? 1 : 0,
        ];
        $cols = array_keys($params);
        $place = implode(',', array_fill(0, count($cols), '?'));
        // colonne aggiornabili su conflitto (NON section_key/institute_id/is_default)
        $upd = [];
        foreach ($cols as $c) {
            if (in_array($c, ['institute_id', 'section_key', 'is_default'], true)) {
                continue;
            }
            $upd[] = "$c=VALUES($c)";
        }
        $sql = 'INSERT INTO sidebar_sections (' . implode(',', $cols) . ") VALUES ($place) "
             . 'ON DUPLICATE KEY UPDATE ' . implode(',', $upd);
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $id = (int)$pdo->lastInsertId();
        if ($id === 0) { // update path → recupera id
            $g = $pdo->prepare('SELECT id FROM sidebar_sections WHERE institute_id=? AND section_key=?');
            $g->execute([$instituteId, $params['section_key']]);
            $id = (int)$g->fetchColumn();
        }
        return $id;
    }

    /** Elimina una sezione NON default. Ritorna true se eliminata. */
    public function delete(int $id): bool
    {
        if (!Database::isAvailable()) {
            return false;
        }
        $stmt = Database::connection()->prepare('DELETE FROM sidebar_sections WHERE id = ? AND is_default = 0');
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** Aggiorna le position in batch. $idToPos = [id => position]. */
    public function setPositions(array $idToPos): void
    {
        if (!Database::isAvailable() || !$idToPos) {
            return;
        }
        $stmt = Database::connection()->prepare('UPDATE sidebar_sections SET position = ? WHERE id = ?');
        foreach ($idToPos as $id => $pos) {
            $stmt->execute([(int)$pos, (int)$id]);
        }
    }

    /** Normalizza una riga + override in una forma comoda per il render. */
    private function shape(array $r, ?array $override): array
    {
        $jsonArr = static function ($v): array {
            if (is_array($v)) {
                return $v;
            }
            $d = json_decode((string)$v, true);
            return is_array($d) ? $d : [];
        };
        $s = [
            'id'                    => (int)$r['id'],
            'institute_id'          => (int)$r['institute_id'],
            'section_key'           => (string)$r['section_key'],
            'label'                 => (string)$r['label'],
            'icon'                  => $r['icon'] !== null ? (string)$r['icon'] : null,
            'color'                 => $r['color'] !== null ? (string)$r['color'] : null,
            'color_border'          => $r['color_border'] !== null ? (string)$r['color_border'] : null,
            'position'              => (int)$r['position'],
            'loader_kind'           => (string)$r['loader_kind'],
            'group_mode'            => (string)$r['group_mode'],
            'allowed_content_types' => $jsonArr($r['allowed_content_types']),
            'default_content_type'  => (string)$r['default_content_type'],
            'origin'                => $r['origin'] !== null ? (string)$r['origin'] : null,
            'default_categories'    => $jsonArr($r['default_categories'] ?? null),
            'custom_categories'     => (bool)$r['custom_categories'],
            'supports_fork'         => (bool)$r['supports_fork'],
            'allow_template_fork'   => isset($r['allow_template_fork']) ? (bool)$r['allow_template_fork'] : false,
            'lock_default_categories' => isset($r['lock_default_categories']) ? (bool)$r['lock_default_categories'] : true,
            'lock_custom_categories' => isset($r['lock_custom_categories']) ? (bool)$r['lock_custom_categories'] : false,
            'template_origin'       => isset($r['template_origin']) && $r['template_origin'] !== null ? (string)$r['template_origin'] : null,
            'template_groups'       => $jsonArr($r['template_groups'] ?? null),
            'visible_roles'         => $jsonArr($r['visible_roles']),
            'publish_public'        => isset($r['publish_public']) ? (bool)$r['publish_public'] : false,
            'teacher_scope'         => isset($r['teacher_scope']) ? (string)$r['teacher_scope'] : 'all',
            'teacher_scope_value'   => $jsonArr($r['teacher_scope_value'] ?? null), // {institute_id,indirizzo,classe}
            'active'                => (bool)$r['active'],
            'is_default'            => (bool)$r['is_default'],
        ];
        if ($override) {
            if ($override['label']    !== null && $override['label']    !== '') {
                $s['label']    = (string)$override['label'];
            }
            if ($override['color']    !== null && $override['color']    !== '') {
                $s['color']    = (string)$override['color'];
            }
            if ($override['icon']     !== null && $override['icon']     !== '') {
                $s['icon']     = (string)$override['icon'];
            }
            if ($override['position'] !== null) {
                $s['position'] = (int)$override['position'];
            }
            if ($override['active']   !== null) {
                $s['active']   = (bool)$override['active'];
            }
        }
        return $s;
    }

    /** WS4 — docenti assegnati a una sezione (allowlist 'docenti'). @return list<int> */
    public function teacherIdsFor(int $sectionId): array
    {
        if (!Database::isAvailable() || $sectionId <= 0) {
            return [];
        }
        try {
            $stmt = Database::connection()->prepare('SELECT teacher_id FROM sidebar_section_teachers WHERE section_id = ?');
            $stmt->execute([$sectionId]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * WS4 — true se il docente "appartiene" allo scope istituto/indirizzo/classe.
     * Le dimensioni vuote NON vincolano; tutte vuote → true (= tutti i docenti).
     * @param array{institute_id?:int|string,indirizzo?:string,classe?:string} $scope
     */
    public function teacherMatchesScope(int $teacherId, array $scope): bool
    {
        $inst = (int)($scope['institute_id'] ?? 0);
        $ind  = trim((string)($scope['indirizzo'] ?? ''));
        $cls  = trim((string)($scope['classe'] ?? ''));
        if ($inst <= 0 && $ind === '' && $cls === '') {
            return true; // nessun vincolo → tutti
        }
        if (!Database::isAvailable() || $teacherId <= 0) {
            return false;
        }
        try {
            $pdo = Database::connection();
            if ($inst > 0) {
                $s = $pdo->prepare('SELECT 1 FROM teacher_institutes WHERE user_id = ? AND institute_id = ? LIMIT 1');
                $s->execute([$teacherId, $inst]);
                if (!$s->fetchColumn()) {
                    return false;
                }
            }
            if ($ind !== '' || $cls !== '') {
                $w = ['teacher_id = ?'];
                $a = [$teacherId];
                if ($ind !== '') { $w[] = 'indirizzo = ?'; $a[] = $ind; }
                if ($cls !== '') { $w[] = 'classe = ?'; $a[] = $cls; }
                $s = $pdo->prepare('SELECT 1 FROM teacher_content WHERE ' . implode(' AND ', $w) . ' LIMIT 1');
                $s->execute($a);
                if (!$s->fetchColumn()) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** WS4 — assegnazioni docenti per più sezioni. @return array<int,list<int>> */
    public function assignmentsFor(array $sectionIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $sectionIds), fn($i) => $i > 0));
        if (!Database::isAvailable() || !$ids) {
            return [];
        }
        try {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = Database::connection()->prepare(
                "SELECT section_id, teacher_id FROM sidebar_section_teachers WHERE section_id IN ($in)"
            );
            $stmt->execute($ids);
            $out = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[(int)$r['section_id']][] = (int)$r['teacher_id'];
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** WS4 — imposta l'allowlist docenti per una sezione (replace). */
    public function setTeacherIds(int $sectionId, array $teacherIds): void
    {
        if (!Database::isAvailable() || $sectionId <= 0) {
            return;
        }
        $pdo = Database::connection();
        try {
            $pdo->prepare('DELETE FROM sidebar_section_teachers WHERE section_id = ?')->execute([$sectionId]);
            $ins = $pdo->prepare('INSERT IGNORE INTO sidebar_section_teachers (section_id, teacher_id) VALUES (?, ?)');
            foreach (array_unique(array_map('intval', $teacherIds)) as $tid) {
                if ($tid > 0) {
                    $ins->execute([$sectionId, $tid]);
                }
            }
        } catch (\Throwable) {
            /* tabella assente → no-op */
        }
    }

    /**
     * WS4 — sezione pubblica (publish_public=1, attiva) per key, dal template
     * globale (institute_id=0). Per il render pubblico SENZA login.
     * @return array<string,mixed>|null
     */
    public function publicSectionByKey(string $key): ?array
    {
        if (!Database::isAvailable() || $key === '') {
            return null;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM sidebar_sections WHERE institute_id = 0 AND section_key = ? AND active = 1 AND publish_public = 1 LIMIT 1'
            );
            $stmt->execute([$key]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            return $r ? $this->shape($r, null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * WS4/security — id di TUTTE le sezioni publish_public attive (qualunque
     * istituto), per il gate per-sezione dei contenuti pubblici (impedisce che
     * marcare pubblica una sezione 'document' (es. bes) esponga i documenti di
     * un'altra sezione 'document' (es. risdoc riservato) via content_type.
     * @return list<int>
     */
    public function publicSectionIds(): array
    {
        if (!Database::isAvailable()) {
            return [];
        }
        try {
            $ids = Database::connection()->query(
                'SELECT id FROM sidebar_sections WHERE active = 1 AND publish_public = 1'
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
            return array_map('intval', $ids);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * WS4 — tutte le sezioni pubbliche (publish_public=1, attive) dal template
     * globale (institute_id=0), per la sidebar guest (visualizzazione senza login).
     * @return list<array<string,mixed>>
     */
    public function publicSections(): array
    {
        if (!Database::isAvailable()) {
            return [];
        }
        try {
            $rows = Database::connection()->query(
                'SELECT * FROM sidebar_sections WHERE institute_id = 0 AND active = 1 AND publish_public = 1 ORDER BY position'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(fn($r) => $this->shape($r, null), $rows);
        } catch (\Throwable) {
            return [];
        }
    }
}
