<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Support\DeploymentMode;
use PDO;
use Throwable;

/**
 * TeacherCapabilityPolicy — ADR-028 Fase 2/3: capabilities per-docente.
 *
 * Capability effettiva di un utente =
 *   profilo assegnato (o profilo default) ∪ override per-docente (override vince).
 *
 * Regole di sicurezza:
 *   - In modo SINGLE → SEMPRE full-permissive (sei l'unico docente; nessuna
 *     restrizione, retrocompat totale).
 *   - In modo INSTITUTE → capability calcolata dai profili. Capability assente
 *     dallo schema ⇒ default permissivo del profilo "Completo".
 *   - Fail-open su errore DB (availability > restrizione per una feature di
 *     governance, non di auth): logga e ritorna i default permissivi.
 *
 * Schema capabilities (JSON):
 *   { "sidebar": {"mode":"all|allow|deny","sections":[slug,...]},
 *     "can_create_section": bool,
 *     "doc_types": [...], "max_visibility": "own_classes|classes|general" }
 */
final class TeacherCapabilityPolicy
{
    /** Default permissivo (retrocompat / SINGLE / fail-open). */
    public const DEFAULT_CAPS = [
        'sidebar'            => ['mode' => 'all', 'sections' => []],
        'can_create_section' => true,
        'doc_types'          => ['mappa', 'esercizio', 'verifica', 'document', 'fork', 'link', 'custom'],
        'max_visibility'     => 'general',
    ];

    /** Ranghi di visibilità (vocabolario publish_scope, mig 069): class < classes < general. */
    private const VIS_RANK = ['class' => 1, 'classes' => 2, 'general' => 3];

    /** @var array<int,array<string,mixed>> cache per-request: userId → caps */
    private array $cache = [];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    private function db(): PDO
    {
        return $this->pdo ?? Database::connection();
    }

    /**
     * Capability effettiva per un utente.
     * @return array<string,mixed>
     */
    public function effectiveFor(int $userId): array
    {
        if (DeploymentMode::isSingle()) {
            return self::DEFAULT_CAPS;
        }
        if (isset($this->cache[$userId])) {
            return $this->cache[$userId];
        }
        try {
            $caps = $this->resolveProfileCaps($userId);
            $override = $this->getOverride($userId);
            if ($override !== null) {
                $caps = $this->mergeCaps($caps, $override);
            }
            $caps = $this->mergeCaps(self::DEFAULT_CAPS, $caps); // riempie chiavi mancanti
            $caps = $this->pruneSidebarSections($caps);          // scarta section_key orfani
            return $this->cache[$userId] = $caps;
        } catch (Throwable $e) {
            error_log('[TeacherCapabilityPolicy] fail-open: ' . $e->getMessage());
            return self::DEFAULT_CAPS;
        }
    }

    // ── Check usati dai controller (Fase 3) ──

    public function canCreateDocType(int $userId, string $type): bool
    {
        $caps = $this->effectiveFor($userId);
        $types = $caps['doc_types'] ?? self::DEFAULT_CAPS['doc_types'];
        return in_array($type, (array)$types, true);
    }

    public function canCreateSection(int $userId): bool
    {
        return (bool)($this->effectiveFor($userId)['can_create_section'] ?? true);
    }

    /** Lo scope di pubblicazione richiesto è entro il massimo consentito? */
    public function visibilityAllowed(int $userId, string $scope): bool
    {
        $max = (string)($this->effectiveFor($userId)['max_visibility'] ?? 'general');
        $want = self::VIS_RANK[$scope] ?? 1;
        $cap  = self::VIS_RANK[$max] ?? 3;
        return $want <= $cap;
    }

    public function maxVisibility(int $userId): string
    {
        return (string)($this->effectiveFor($userId)['max_visibility'] ?? 'general');
    }

    /**
     * Filtra una lista di sezioni sidebar secondo la capability dell'utente.
     * Ogni sezione deve avere una chiave 'slug' (o 'key'/'id').
     * @param list<array<string,mixed>> $sections
     * @return list<array<string,mixed>>
     */
    public function filterSidebarSections(int $userId, array $sections): array
    {
        $sb = $this->effectiveFor($userId)['sidebar'] ?? self::DEFAULT_CAPS['sidebar'];
        $mode = (string)($sb['mode'] ?? 'all');
        if ($mode === 'all') {
            return $sections;
        }
        $set = array_map('strval', (array)($sb['sections'] ?? []));
        return array_values(array_filter($sections, static function ($s) use ($mode, $set) {
            $slug = (string)($s['section_key'] ?? $s['slug'] ?? $s['key'] ?? $s['id'] ?? '');
            $in = in_array($slug, $set, true);
            return $mode === 'allow' ? $in : !$in; // deny = tutte tranne quelle elencate
        }));
    }

    // ── Risoluzione profilo + merge ──

    /** @return array<string,mixed> caps del profilo assegnato (o default) */
    private function resolveProfileCaps(int $userId): array
    {
        // profilo assegnato all'utente
        $stmt = $this->db()->prepare('SELECT capability_profile_id FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $profileId = $stmt->fetchColumn();
        if ($profileId) {
            $caps = $this->profileCaps((int)$profileId);
            if ($caps !== null) {
                return $caps;
            }
        }
        // fallback: profilo default
        $stmt = $this->db()->query('SELECT capabilities FROM teacher_capability_profiles WHERE is_default = 1 LIMIT 1');
        $json = $stmt ? $stmt->fetchColumn() : false;
        return $json !== false ? $this->decode((string)$json) : self::DEFAULT_CAPS;
    }

    /** @return array<string,mixed>|null */
    private function profileCaps(int $profileId): ?array
    {
        $stmt = $this->db()->prepare('SELECT capabilities FROM teacher_capability_profiles WHERE id = ? LIMIT 1');
        $stmt->execute([$profileId]);
        $json = $stmt->fetchColumn();
        return $json !== false ? $this->decode((string)$json) : null;
    }

    /**
     * Deep-merge: le chiavi di $b vincono su $a. Per 'sidebar' (oggetto) merge
     * shallow; per il resto sostituzione.
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @return array<string,mixed>
     */
    private function mergeCaps(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $k => $v) {
            if ($k === 'sidebar' && is_array($v) && isset($a['sidebar']) && is_array($a['sidebar'])) {
                $out['sidebar'] = array_merge($a['sidebar'], $v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function decode(string $json): array
    {
        try {
            $d = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            return is_array($d) ? $d : self::DEFAULT_CAPS;
        } catch (Throwable) {
            return self::DEFAULT_CAPS;
        }
    }

    /** @var list<string>|null cache per-request dei section_key esistenti */
    private static ?array $validKeys = null;

    /** section_key realmente esistenti (cache statica per-request). */
    private function validSectionKeys(): array
    {
        if (self::$validKeys !== null) {
            return self::$validKeys;
        }
        try {
            $rows = (new \App\Repositories\SidebarSectionRepository())->listForAdmin(0);
            return self::$validKeys = array_map(static fn($s) => (string)$s['section_key'], $rows);
        } catch (Throwable) {
            return self::$validKeys = [];
        }
    }

    /**
     * Pulizia in lettura: rimuove da sidebar.sections i section_key orfani
     * (sezioni eliminate/rinominate). Se il lookup fallisce (DB giù) non pota,
     * per non perdere dati. Idempotente.
     * @param array<string,mixed> $caps
     * @return array<string,mixed>
     */
    private function pruneSidebarSections(array $caps): array
    {
        $valid = $this->validSectionKeys();
        if (!$valid) {
            return $caps; // lookup non disponibile → non potare (safe)
        }
        if (!isset($caps['sidebar']['sections']) || !is_array($caps['sidebar']['sections'])) {
            return $caps;
        }
        $caps['sidebar']['sections'] = array_values(array_intersect(
            array_map('strval', $caps['sidebar']['sections']),
            $valid
        ));
        return $caps;
    }

    // ── Gestione profili / assegnazioni / override (Fase 4 admin UI) ──

    /** @return list<array<string,mixed>> */
    public function listProfiles(): array
    {
        try {
            $stmt = $this->db()->query('SELECT id, name, capabilities, is_default FROM teacher_capability_profiles ORDER BY is_default DESC, name');
            return array_map(fn($r) => [
                'id'           => (int)$r['id'],
                'name'         => (string)$r['name'],
                'is_default'   => (bool)$r['is_default'],
                'capabilities' => $this->pruneSidebarSections($this->decode((string)$r['capabilities'])),
            ], $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : []);
        } catch (Throwable) {
            return [];
        }
    }

    public function saveProfile(?int $id, string $name, array $caps): int
    {
        $json = json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($id) {
            $this->db()->prepare('UPDATE teacher_capability_profiles SET name = ?, capabilities = ? WHERE id = ?')
                ->execute([$name, $json, $id]);
            return $id;
        }
        $this->db()->prepare('INSERT INTO teacher_capability_profiles (name, capabilities, is_default) VALUES (?, ?, 0)')
            ->execute([$name, $json]);
        return (int)$this->db()->lastInsertId();
    }

    public function deleteProfile(int $id): bool
    {
        try {
            // non cancellare il default
            $stmt = $this->db()->prepare('DELETE FROM teacher_capability_profiles WHERE id = ? AND is_default = 0');
            $stmt->execute([$id]);
            // gli utenti col profilo cancellato ricadono sul default (NULL)
            $this->db()->prepare('UPDATE users SET capability_profile_id = NULL WHERE capability_profile_id = ?')->execute([$id]);
            return $stmt->rowCount() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    public function assignProfile(int $userId, ?int $profileId): bool
    {
        try {
            unset($this->cache[$userId]);
            return $this->db()->prepare('UPDATE users SET capability_profile_id = ? WHERE id = ?')
                ->execute([$profileId, $userId]);
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed>|null */
    public function getOverride(int $userId): ?array
    {
        try {
            $stmt = $this->db()->prepare('SELECT capabilities FROM teacher_capability_overrides WHERE user_id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $json = $stmt->fetchColumn();
            return $json !== false ? $this->decode((string)$json) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function setOverride(int $userId, ?array $caps): bool
    {
        try {
            unset($this->cache[$userId]);
            if ($caps === null || $caps === []) {
                return $this->db()->prepare('DELETE FROM teacher_capability_overrides WHERE user_id = ?')->execute([$userId]);
            }
            $json = json_encode($caps, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $this->db()->prepare(
                'INSERT INTO teacher_capability_overrides (user_id, capabilities) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE capabilities = VALUES(capabilities)'
            )->execute([$userId, $json]);
        } catch (Throwable) {
            return false;
        }
    }
}
