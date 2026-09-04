<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use RuntimeException;

/**
 * G22.S15.bis — Override per docente dei template TikZ/LaTeX globali admin.
 *
 * Modello dual-layer:
 *   - Predefiniti admin: storage/data/modelli_tikz_elements.json
 *     (rigenerato da TikzElementsService a ogni CRUD admin).
 *   - Override docente: storage/objects/teachers/{teacherId}/tikz-overrides.json
 *     map keyed by "{groupKey}|{label}" → {code, data?, ts}.
 *
 * Semantica:
 *   - Il docente vede gli admin defaults; quando "Salva predefinito" su un
 *     template, scrive un override per QUEL template (label+code+data).
 *   - "Reset" elimina l'override → il docente torna a vedere il default
 *     admin (eventualmente aggiornato nel frattempo).
 *   - "Effettivo" = merge: per ogni admin item, se esiste override ne
 *     prende code/data; altrimenti ritorna il default.
 *
 * Schema modulare: l'override può contenere `data` (JSON dei valori filler)
 * oltre al `code` (TikZ rigenerato). Il filler ✏️ legge il `data`
 * (fallback al marker `% __FM_TPL_DATA__:` nel code, fallback default).
 */
final class TeacherTemplateOverridesService
{
    private const ADMIN_INDEX_REL = 'storage/data/modelli_tikz_elements.json';
    private const TEACHER_DIR_REL = 'storage/objects/teachers';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }

    private function teacherFile(int $teacherId): string
    {
        if ($teacherId <= 0) {
            throw new RuntimeException('invalid_teacher_id');
        }
        $dir = $this->basePath . '/' . self::TEACHER_DIR_REL . '/' . $teacherId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir . '/tikz-overrides.json';
    }

    private function adminIndexFile(): string
    {
        return $this->basePath . '/' . self::ADMIN_INDEX_REL;
    }

    private function key(string $groupKey, string $label): string
    {
        return $groupKey . '|' . $label;
    }

    /** @return array<string, array{code:string,data?:array,type?:string,ts:int}> */
    public function getOverrides(int $teacherId): array
    {
        $file = $this->teacherFile($teacherId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }
        return $data;
    }

    /** Salva l'override per (group, label). Crea il file se assente.
     *  $data è opzionale (solo per template tipo schema-modulare). */
    public function saveOverride(int $teacherId, string $groupKey, string $label, string $code, ?array $data = null, string $type = 'tikz'): void
    {
        if ($groupKey === '' || $label === '') {
            throw new RuntimeException('group_or_label_missing');
        }
        if ($code === '') {
            throw new RuntimeException('code_missing');
        }
        $overrides = $this->getOverrides($teacherId);
        $entry = [
            'code' => $code,
            'type' => $type,
            'ts'   => time(),
        ];
        if ($data !== null) {
            $entry['data'] = $data;
        }
        $overrides[$this->key($groupKey, $label)] = $entry;
        $this->writeOverrides($teacherId, $overrides);
    }

    /** Elimina l'override per (group, label). Se non esiste, no-op. */
    public function resetOverride(int $teacherId, string $groupKey, string $label): bool
    {
        $overrides = $this->getOverrides($teacherId);
        $key = $this->key($groupKey, $label);
        if (!isset($overrides[$key])) {
            return false;
        }
        unset($overrides[$key]);
        $this->writeOverrides($teacherId, $overrides);
        return true;
    }

    /** Carica index admin defaults (rigenerato da TikzElementsService). */
    public function getAdminDefaults(): array
    {
        $file = $this->adminIndexFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** Merge: admin defaults overriden da teacher overrides.
     *  Ritorna la stessa struttura di modelli_tikz_elements.json:
     *    {gruppo-key: [ {label, content, type, _override?: bool, _data?: array} ]} */
    public function getEffectiveTemplates(int $teacherId): array
    {
        $defaults  = $this->getAdminDefaults();
        $overrides = $teacherId > 0 ? $this->getOverrides($teacherId) : [];
        if (empty($overrides)) {
            return $defaults;
        }

        $out = [];
        foreach ($defaults as $groupKey => $items) {
            if (!is_array($items)) {
                $out[$groupKey] = $items;
                continue;
            }
            $newItems = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    $newItems[] = $item;
                    continue;
                }
                $label = (string)($item['label'] ?? '');
                $key   = $this->key($groupKey, $label);
                if (isset($overrides[$key])) {
                    $ov = $overrides[$key];
                    $merged = $item;
                    $merged['content']   = (string)($ov['code'] ?? $item['content'] ?? '');
                    $merged['type']      = (string)($ov['type'] ?? $item['type'] ?? 'tikz');
                    $merged['_override'] = true;
                    if (isset($ov['data']) && is_array($ov['data'])) {
                        $merged['_data'] = $ov['data'];
                    }
                    $newItems[] = $merged;
                } else {
                    $newItems[] = $item;
                }
            }
            $out[$groupKey] = $newItems;
        }
        return $out;
    }

    private function writeOverrides(int $teacherId, array $overrides): void
    {
        $file = $this->teacherFile($teacherId);
        $json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_overrides');
        }
        @chmod($file, 0664);
    }

    // ─────────── Cross-teacher migrations (admin CRUD propagation) ───────────
    //
    // Quando l'admin renomina/sposta/elimina un default, gli override di TUTTI
    // i docenti che riferiscono quella chiave devono essere migrati o eliminati
    // per mantenere coerenza. Il chiamante (TikzController) invoca questi
    // metodi DOPO che il default è stato modificato con successo.
    //
    // Ogni metodo ritorna il numero di file teacher modificati.

    /** Itera su tutti i `storage/objects/teachers/{id}/tikz-overrides.json`,
     *  applica $cb (può modificare $overrides by-ref); se $cb ritorna true,
     *  riscrive il file. */
    private function forEachTeacher(callable $cb): int
    {
        $dir = $this->basePath . '/' . self::TEACHER_DIR_REL;
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return 0;
        }
        foreach ($entries as $tid) {
            if ($tid === '.' || $tid === '..' || !ctype_digit($tid)) {
                continue;
            }
            $file = $dir . '/' . $tid . '/tikz-overrides.json';
            if (!is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if ($raw === false || $raw === '') {
                continue;
            }
            $overrides = json_decode($raw, true);
            if (!is_array($overrides)) {
                continue;
            }
            $changed = (bool) $cb($overrides, (int)$tid);
            if (!$changed) {
                continue;
            }
            $json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                continue;
            }
            if (@file_put_contents($file, $json, LOCK_EX) !== false) {
                $count++;
            }
        }
        return $count;
    }

    /** Admin ha rinominato un gruppo `oldGroupKey` → `newGroupKey`.
     *  Migra tutti gli override `oldGroupKey|*` in `newGroupKey|*`. */
    public function migrateRenameGroup(string $oldGroupKey, string $newGroupKey): int
    {
        if ($oldGroupKey === '' || $newGroupKey === '' || $oldGroupKey === $newGroupKey) {
            return 0;
        }
        return $this->forEachTeacher(function (array &$overrides) use ($oldGroupKey, $newGroupKey): bool {
            $changed = false;
            $next = [];
            foreach ($overrides as $key => $val) {
                $parts = explode('|', $key, 2);
                if (($parts[0] ?? '') === $oldGroupKey) {
                    $next[$newGroupKey . '|' . ($parts[1] ?? '')] = $val;
                    $changed = true;
                } else {
                    $next[$key] = $val;
                }
            }
            if ($changed) {
                $overrides = $next;
            }
            return $changed;
        });
    }

    /** Admin ha rinominato la label di un elemento dentro lo stesso gruppo.
     *  Migra `groupKey|oldLabel` → `groupKey|newLabel`. */
    public function migrateRenameLabel(string $groupKey, string $oldLabel, string $newLabel): int
    {
        if ($groupKey === '' || $oldLabel === '' || $newLabel === '' || $oldLabel === $newLabel) {
            return 0;
        }
        $oldKey = $this->key($groupKey, $oldLabel);
        $newKey = $this->key($groupKey, $newLabel);
        return $this->forEachTeacher(function (array &$overrides) use ($oldKey, $newKey): bool {
            if (!isset($overrides[$oldKey])) {
                return false;
            }
            $overrides[$newKey] = $overrides[$oldKey];
            unset($overrides[$oldKey]);
            return true;
        });
    }

    /** Admin ha spostato l'elemento da $oldGroup a $newGroup mantenendo il label.
     *  Migra `oldGroup|label` → `newGroup|label`. */
    public function migrateMoveElement(string $oldGroup, string $newGroup, string $label): int
    {
        if ($oldGroup === '' || $newGroup === '' || $label === '' || $oldGroup === $newGroup) {
            return 0;
        }
        $oldKey = $this->key($oldGroup, $label);
        $newKey = $this->key($newGroup, $label);
        return $this->forEachTeacher(function (array &$overrides) use ($oldKey, $newKey): bool {
            if (!isset($overrides[$oldKey])) {
                return false;
            }
            $overrides[$newKey] = $overrides[$oldKey];
            unset($overrides[$oldKey]);
            return true;
        });
    }

    /** Admin ha eliminato un intero gruppo. Rimuove tutti gli override
     *  `groupKey|*` di ogni docente (cleanup orfani). */
    public function migrateDeleteGroup(string $groupKey): int
    {
        if ($groupKey === '') {
            return 0;
        }
        $prefix = $groupKey . '|';
        $plen = strlen($prefix);
        return $this->forEachTeacher(function (array &$overrides) use ($prefix, $plen): bool {
            $changed = false;
            foreach (array_keys($overrides) as $key) {
                if (strncmp($key, $prefix, $plen) === 0) {
                    unset($overrides[$key]);
                    $changed = true;
                }
            }
            return $changed;
        });
    }

    /** Admin ha eliminato un singolo elemento. Rimuove l'override `groupKey|label`. */
    public function migrateDeleteElement(string $groupKey, string $label): int
    {
        if ($groupKey === '' || $label === '') {
            return 0;
        }
        $key = $this->key($groupKey, $label);
        return $this->forEachTeacher(function (array &$overrides) use ($key): bool {
            if (!isset($overrides[$key])) {
                return false;
            }
            unset($overrides[$key]);
            return true;
        });
    }
}
