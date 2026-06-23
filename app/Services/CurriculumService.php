<?php

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * G22.S15.bis Fase 5+ — Catalog curriculum per istituto.
 *
 * Da scope globale (single curriculum.json) a scope per-istituto:
 * ogni istituto (Galileo, ITIS, ...) ha il proprio catalog di
 * indirizzi/classi/materie. Source of truth = DB (curriculum_entries
 * con institute_id NOT NULL per nuove righe; NULL = legacy fallback).
 *
 * Il fallback JSON locale (storage/data/curriculum.json) e' mantenuto
 * solo come read-only seed quando il DB non e' disponibile (boot
 * iniziale o disaster recovery): in quel caso ritorna entries
 * "legacy globali" (institute_id NULL semantica).
 */
final class CurriculumService
{
    public const KINDS = ['indirizzi', 'classi', 'materie'];

    /**
     * Convenzione code per kind (G22.S15.bis Fase 5+ post-cleanup).
     *  - indirizzi/materie: 3-6 lettere maiuscole (MAT, SCI, AFM, ...)
     *  - classi: numero singolo 1-9 + suffix opzionale alpha singolo (es. 1b)
     *
     * Le entries legacy con code lowercase (sc, ar, ling) restano per
     * back-compat con risorse esistenti, ma NUOVE entries devono
     * rispettare la convenzione.
     */
    private const CODE_PATTERNS = [
        'indirizzi' => '#^[A-Z]{3,6}$#',
        'classi'    => '#^[1-9][A-Z0-9]{0,3}$#', // num + suffix uppercase libero (es. 1, 2B, 3X)
        'materie'   => '#^[A-Z]{3,6}$#',
    ];

    public function __construct(
        private readonly string $jsonPath,
        private readonly ?string $backupDir = null,
    ) {
    }

    /**
     * Catalog per istituto. Indirizzi/classi sono per-istituto, materie sono
     * per-docente: se $teacherId fornito, lista solo le materie di proprieta'
     * del docente (owner_user_id=$teacherId); altrimenti restituisce le righe
     * institute-level (owner_user_id IS NULL), usate da admin/exercises.
     *
     * @param int|null $instituteId NULL = solo legacy globali (admin view)
     * @param int|null $teacherId Se fornito, filtra materie per owner
     * @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>}
     */
    public function all(?int $instituteId = null, ?int $teacherId = null): array
    {
        if (Config::get('database.enabled') && Database::isAvailable()) {
            return $this->loadFromDb($instituteId, $teacherId);
        }
        return $this->loadFromJsonFallback();
    }

    /**
     * G22.S22 — Catalog ownership refactor full:
     * TUTTI i kind (indirizzi, classi, materie) sono per-docente. Le righe
     * anchor (owner_user_id IS NULL) servono solo a exercises catalog.
     *
     * Scenari:
     *  (a) $instituteId + $teacherId: scoping istituto + owner. Caso comune
     *      per pagina profilo "Curriculum dell'istituto attivo".
     *  (b) $instituteId solo (admin/legacy): anchor institute-level.
     *  (c) $teacherId solo (instituteId=null, scope=all): TUTTE le righe
     *      del docente cross-institute.
     *  (d) ne' istituto ne' teacher: vuoto post-cleanup.
     *
     * @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>}
     */
    private function loadFromDb(?int $instituteId, ?int $teacherId = null): array
    {
        $out = ['indirizzi' => [], 'classi' => [], 'materie' => []];
        $pdo = Database::connection();

        // G22.S22 — LEFT JOIN institutes per esporre nome scuola accanto a
        // ogni entry (UI usa "altro istituto" → "Liceo Musicale", ecc).
        $base = "SELECT ce.id, ce.kind, ce.institute_id, ce.owner_user_id,
                        ce.code, ce.label, ce.grp, ce.active, ce.shared_with_pool,
                        COALESCE(i.name, '') AS institute_name,
                        COALESCE(i.code, '') AS institute_code
                   FROM curriculum_entries ce
                   LEFT JOIN institutes i ON i.id = ce.institute_id
                  WHERE ce.kind IN ('indirizzi','classi','materie') ";

        if ($instituteId !== null && $teacherId !== null && $teacherId > 0) {
            $stmt = $pdo->prepare($base . "AND ce.institute_id = ? AND ce.owner_user_id = ?
                                           ORDER BY ce.kind, ce.label");
            $stmt->execute([$instituteId, $teacherId]);
        } elseif ($instituteId !== null) {
            $stmt = $pdo->prepare($base . "AND ce.institute_id = ? AND ce.owner_user_id IS NULL
                                           ORDER BY ce.kind, ce.label");
            $stmt->execute([$instituteId]);
        } elseif ($teacherId !== null && $teacherId > 0) {
            $stmt = $pdo->prepare($base . "AND ce.owner_user_id = ?
                                           ORDER BY ce.kind, ce.institute_id, ce.label");
            $stmt->execute([$teacherId]);
        } else {
            $stmt = $pdo->query($base . "AND ce.institute_id IS NULL AND ce.owner_user_id IS NULL
                                          ORDER BY ce.kind, ce.label");
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $kind = $row['kind'];
            if (!isset($out[$kind])) {
                continue;
            }
            $out[$kind][] = $this->rowToShape($row);
        }
        return $out;
    }

    /** Read-only fallback JSON (DB unavailable). */
    private function loadFromJsonFallback(): array
    {
        if (!is_file($this->jsonPath)) {
            return ['indirizzi' => [], 'classi' => [], 'materie' => []];
        }
        $raw = file_get_contents($this->jsonPath);
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            return ['indirizzi' => [], 'classi' => [], 'materie' => []];
        }
        foreach (self::KINDS as $k) {
            $data[$k] = $data[$k] ?? [];
        }
        return $data;
    }

    /** @return list<array> */
    public function listActive(string $kind, ?int $instituteId = null, ?int $teacherId = null): array
    {
        $this->assertKind($kind);
        return array_values(array_filter(
            $this->all($instituteId, $teacherId)[$kind] ?? [],
            fn(array $row) => (bool)($row['active'] ?? false)
        ));
    }

    /**
     * Lookup pubblico per registrazione studente: classi/indirizzi/materie
     * attivi di un istituto a prescindere dall'owner.
     *
     * Le entry curriculum sono per-docente (owner_user_id = id docente, mai
     * NULL nel modello G22.S22), quindi listActive(..., null) — che filtra
     * owner_user_id IS NULL — non trova nulla. Lo studente in registrazione
     * deve invece vedere TUTTE le classi che esistono in quell'istituto,
     * create da qualunque docente, deduplicate per code.
     *
     * @return list<array>
     */
    public function listActiveForInstitute(string $kind, int $instituteId): array
    {
        $this->assertKind($kind);
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return [];
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "SELECT ce.id, ce.kind, ce.institute_id, ce.owner_user_id,
                    ce.code, ce.label, ce.grp, ce.active, ce.shared_with_pool,
                    COALESCE(i.name, '') AS institute_name,
                    COALESCE(i.code, '') AS institute_code
               FROM curriculum_entries ce
               LEFT JOIN institutes i ON i.id = ce.institute_id
              WHERE ce.kind = ? AND ce.institute_id = ? AND ce.active = 1
              ORDER BY ce.label"
        );
        $stmt->execute([$kind, $instituteId]);

        $out = [];
        $seen = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = (string)($row['code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = $this->rowToShape($row);
        }
        return $out;
    }

    /**
     * Curriculum completo (indirizzi/classi/materie) ATTIVO di un istituto a
     * prescindere dall'owner, deduplicato per code. Stessa forma di all().
     *
     * Usato dalla sidebar studio per gli STUDENTI: lo studente vede l'offerta
     * formativa del suo istituto (indirizzi/classi/materie create da qualunque
     * docente), non possiede entries proprie. all($iid, null) filtrerebbe
     * owner IS NULL → vuoto nel modello per-docente G22.S22. Vedi
     * listActiveForInstitute() per il razionale.
     *
     * @return array{indirizzi: list<array>, classi: list<array>, materie: list<array>}
     */
    public function allActiveForInstitute(int $instituteId): array
    {
        return [
            'indirizzi' => $this->listActiveForInstitute('indirizzi', $instituteId),
            'classi'    => $this->listActiveForInstitute('classi', $instituteId),
            'materie'   => $this->listActiveForInstitute('materie', $instituteId),
        ];
    }

    /**
     * Crea entry curriculum.
     *  - kind indirizzi/classi: owner_user_id NULL (per-istituto, admin-managed).
     *  - kind materie: owner_user_id obbligatorio (per-docente). Se non fornito,
     *    crea l'institute-anchor (admin/super-admin). Per docenti owner=teacher.
     */
    public function add(
        string $kind,
        array $item,
        ?int $instituteId = null,
        ?int $ownerUserId = null,
    ): array {
        $this->assertKind($kind);
        if ($instituteId === null) {
            throw new RuntimeException('institute_id_required');
        }
        $code  = trim((string)($item['code']  ?? ''));
        $label = trim((string)($item['label'] ?? ''));
        $group = trim((string)($item['group'] ?? ''));
        $active = (bool)($item['active'] ?? true);

        $pattern = self::CODE_PATTERNS[$kind] ?? null;
        if (!$pattern || !preg_match($pattern, $code)) {
            throw new RuntimeException('invalid_code_for_' . $kind);
        }
        if ($label === '' || strlen($label) > 120) {
            throw new RuntimeException('invalid_label');
        }
        if (strlen($group) > 60) {
            throw new RuntimeException('invalid_group');
        }

        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            throw new RuntimeException('db_required_for_add');
        }
        // G22.S22 — Tutti i kind sono per-docente. owner_user_id usato per
        // qualunque kind se fornito; NULL solo per admin (anchor institute).
        $owner = $ownerUserId;

        // G22.S22 — Defense: se owner è teacher (non admin path), valida
        // che sia collegato all'istituto. Previene entries orfane (es.
        // owner=140 inst=106 ma Marco non in teacher_institutes 106).
        if ($owner !== null && $owner > 0) {
            $stmt = Database::connection()->prepare(
                'SELECT 1 FROM teacher_institutes WHERE user_id=? AND institute_id=? LIMIT 1'
            );
            $stmt->execute([$owner, $instituteId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('not_linked_to_institute');
            }
        }

        $pdo = Database::connection();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO curriculum_entries
                    (kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
            );
            $stmt->execute([
                $kind,
                $instituteId,
                $owner,
                $code,
                $label,
                $group !== '' ? $group : null,
                (int)$active,
            ]);
            $id = (int)$pdo->lastInsertId();
            return [
                'id'             => $id,
                'institute_id'   => $instituteId,
                'owner_user_id'  => $owner,
                'code'           => $code,
                'label'          => $label,
                'group'          => $group !== '' ? $group : null,
                'active'         => $active,
                'shared_with_pool' => false,
                'is_legacy'      => $instituteId === null,
            ];
        } catch (\PDOException $e) {
            // Duplicate key (kind, code, institute_id, owner_key) → 409
            if ((int)$e->errorInfo[1] === 1062) {
                throw new RuntimeException('duplicate_code');
            }
            throw $e;
        }
    }

    /** Update entry by ID (sicuro contro injection di code). */
    public function updateById(int $entryId, array $patch): array
    {
        if ($entryId <= 0) {
            throw new RuntimeException('invalid_id');
        }
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            throw new RuntimeException('db_required_for_update');
        }
        $pdo = Database::connection();
        $cur = $pdo->prepare('SELECT id, kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool FROM curriculum_entries WHERE id = ?');
        $cur->execute([$entryId]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RuntimeException('entry_not_found');
        }

        $sets = [];
        $vals = [];
        if (isset($patch['label'])) {
            $lbl = trim((string)$patch['label']);
            if ($lbl === '' || strlen($lbl) > 120) {
                throw new RuntimeException('invalid_label');
            }
            $sets[] = 'label = ?';
            $vals[] = $lbl;
        }
        if (isset($patch['group'])) {
            $g = trim((string)$patch['group']);
            if (strlen($g) > 60) {
                throw new RuntimeException('invalid_group');
            }
            $sets[] = 'grp = ?';
            $vals[] = $g !== '' ? $g : null;
        }
        if (array_key_exists('active', $patch)) {
            // NB: il valore arriva come STRINGA "true"/"false" dal FormData.
            // (bool)"false" === true in PHP → usare filter_var per non perdere
            // mai la disattivazione (bug: la spunta "Attiva" tornava sempre on).
            $sets[] = 'active = ?';
            $vals[] = (int)filter_var($patch['active'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('shared_with_pool', $patch)) {
            // G22.S21 — toggle condivisione materia (solo per kind='materie' owner-set).
            // Stesso fix stringa→bool di 'active'.
            $sets[] = 'shared_with_pool = ?';
            $vals[] = (int)filter_var($patch['shared_with_pool'], FILTER_VALIDATE_BOOLEAN);
        }
        if (!$sets) {
            return $this->rowToShape($row);
        }

        $vals[] = $entryId;
        $stmt = $pdo->prepare('UPDATE curriculum_entries SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($vals);
        $cur->execute([$entryId]);
        return $this->rowToShape((array)$cur->fetch(PDO::FETCH_ASSOC));
    }

    /** Remove entry by ID. Cascade su curriculum_users via FK. */
    public function removeById(int $entryId): bool
    {
        if ($entryId <= 0) {
            return false;
        }
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            throw new RuntimeException('db_required_for_remove');
        }
        $stmt = Database::connection()->prepare('DELETE FROM curriculum_entries WHERE id = ?');
        $stmt->execute([$entryId]);
        return $stmt->rowCount() > 0;
    }

    /** Lookup entry by id + verifica institute (per auth check). */
    public function getById(int $entryId): ?array
    {
        if ($entryId <= 0) {
            return null;
        }
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, kind, institute_id, owner_user_id, code, label, grp, active, shared_with_pool
               FROM curriculum_entries WHERE id = ?'
        );
        $stmt->execute([$entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->rowToShape($row) : null;
    }

    /**
     * G22.S21 — Lista materie shared_with_pool=1 di tutti i docenti dello stesso
     * istituto, escluso $teacherId. Output: list di righe materia con owner info.
     * Usato dal Pool browse "Recupera da altri docenti".
     *
     * @return list<array{id:int, code:string, label:string, owner_user_id:int, owner_name:string}>
     */
    public function listSharedFromColleagues(int $instituteId, int $teacherId): array
    {
        if (!Config::get('database.enabled') || !Database::isAvailable()) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            "SELECT ce.id, ce.code, ce.label, ce.owner_user_id,
                    COALESCE(u.first_name, u.username, '') AS owner_first,
                    COALESCE(u.last_name, '') AS owner_last
               FROM curriculum_entries ce
               JOIN users u ON u.id = ce.owner_user_id
               JOIN teacher_institutes ti ON ti.user_id = ce.owner_user_id AND ti.institute_id = ce.institute_id
              WHERE ce.kind = 'materie'
                AND ce.institute_id = ?
                AND ce.shared_with_pool = 1
                AND ce.owner_user_id <> ?
                AND ce.active = 1
              ORDER BY ce.label, owner_last"
        );
        $stmt->execute([$instituteId, $teacherId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim(($r['owner_first'] ?? '') . ' ' . ($r['owner_last'] ?? ''));
            $out[] = [
                'id'            => (int)$r['id'],
                'code'          => (string)$r['code'],
                'label'         => (string)$r['label'],
                'owner_user_id' => (int)$r['owner_user_id'],
                'owner_name'    => $name !== '' ? $name : ('user#' . $r['owner_user_id']),
            ];
        }
        return $out;
    }

    private function rowToShape(array $row): array
    {
        return [
            'id'             => (int)$row['id'],
            'kind'           => $row['kind'],
            'institute_id'   => $row['institute_id'] !== null ? (int)$row['institute_id'] : null,
            'owner_user_id'  => isset($row['owner_user_id']) && $row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null,
            'code'           => $row['code'],
            'label'          => $row['label'],
            'group'          => $row['grp'],
            'active'         => (bool)$row['active'],
            'shared_with_pool' => isset($row['shared_with_pool']) ? (bool)$row['shared_with_pool'] : false,
            'is_legacy'      => $row['institute_id'] === null,
            'institute_name' => isset($row['institute_name']) ? (string)$row['institute_name'] : '',
            'institute_code' => isset($row['institute_code']) ? (string)$row['institute_code'] : '',
        ];
    }

    private function assertKind(string $kind): void
    {
        if (!\in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('invalid_kind');
        }
    }
}
