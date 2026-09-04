<?php

declare(strict_types=1);

namespace App\Services\Tikz;

use RuntimeException;

/**
 * G22.S15.bis (Fase 3) — Workspace personale per docente.
 *
 * Modello "personal workspace" (sostituisce il modello "override layer"):
 *   - Ogni docente ha il SUO file JSON con la struttura completa di gruppi
 *     e items (stesso shape di modelli_tikz_elements.json).
 *   - Al primo accesso: copia integrale dei defaults admin (lazy creation).
 *   - Migrazione legacy: se esiste tikz-overrides.json (modello vecchio),
 *     viene applicato sopra i defaults durante la creazione del workspace,
 *     poi il file legacy archiviato come .legacy.bak.
 *   - Il docente può modificare TUTTO: aggiungere/rinominare/eliminare/
 *     riordinare gruppi e items, senza vincoli.
 *   - "Reset all" → wipe + ricopia defaults admin attuali.
 *   - "Import singolo" da admin library con conflict resolution.
 *
 * File: storage/objects/teachers/{teacherId}/tikz-workspace.json
 * Struttura identica ai defaults admin per minimizzare il diff frontend:
 *   { "gruppo-X": [ {label, content, type, data?, _origin?, ts?} ], ... }
 *
 * Differenza: il docente PUO' rinominare la chiave del gruppo (es.
 * "gruppo-X" → "gruppo-mio-studio"), spostare items, ecc.
 */
final class TeacherTemplateWorkspaceService
{
    private const ADMIN_INDEX_REL = 'storage/data/modelli_tikz_elements.json';
    private const TEACHER_DIR_REL = 'storage/objects/teachers';
    private const WORKSPACE_FILE  = 'tikz-workspace.json';
    private const LEGACY_FILE     = 'tikz-overrides.json';
    private const LEGACY_BAK_EXT  = '.legacy.bak';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }

    private function teacherDir(int $teacherId): string
    {
        if ($teacherId <= 0) {
            throw new RuntimeException('invalid_teacher_id');
        }
        $dir = $this->basePath . '/' . self::TEACHER_DIR_REL . '/' . $teacherId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    private function workspaceFile(int $teacherId): string
    {
        return $this->teacherDir($teacherId) . '/' . self::WORKSPACE_FILE;
    }

    private function adminIndexFile(): string
    {
        return $this->basePath . '/' . self::ADMIN_INDEX_REL;
    }

    /** Carica admin defaults (read-only). */
    public function getAdminLibrary(): array
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

    /**
     * Lazy: ritorna il workspace del docente. Se non esiste lo crea
     * copiando i defaults admin + applicando eventuali override legacy
     * (tikz-overrides.json) per garantire migrazione transparent.
     */
    public function getWorkspace(int $teacherId): array
    {
        $file = $this->workspaceFile($teacherId);
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $data = json_decode($raw, true);
                if (is_array($data)) {
                    return $data;
                }
            }
        }
        // Lazy create
        $workspace = $this->buildInitialWorkspace($teacherId);
        $this->writeWorkspace($teacherId, $workspace);
        $this->archiveLegacyIfPresent($teacherId);
        return $workspace;
    }

    /** Ricostruisce il workspace da defaults admin + override legacy applicati. */
    private function buildInitialWorkspace(int $teacherId): array
    {
        $admin = $this->getAdminLibrary();
        $legacy = $this->readLegacyOverrides($teacherId);
        if (empty($legacy)) {
            return $admin;
        }
        // Applica override legacy: chiave "groupKey|label" → patch item.
        $out = [];
        foreach ($admin as $groupKey => $items) {
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
                $key = $groupKey . '|' . $label;
                if (isset($legacy[$key]) && is_array($legacy[$key])) {
                    $ov = $legacy[$key];
                    $merged = $item;
                    if (isset($ov['code'])) {
                        $merged['content'] = (string)$ov['code'];
                    }
                    if (isset($ov['type'])) {
                        $merged['type']    = (string)$ov['type'];
                    }
                    if (isset($ov['data'])) {
                        $merged['data']    = $ov['data'];
                    }
                    $merged['_origin'] = $key;  // tracciatura (informativa)
                    $newItems[] = $merged;
                } else {
                    $newItems[] = $item;
                }
            }
            $out[$groupKey] = $newItems;
        }
        return $out;
    }

    private function readLegacyOverrides(int $teacherId): array
    {
        $f = $this->teacherDir($teacherId) . '/' . self::LEGACY_FILE;
        if (!is_file($f)) {
            return [];
        }
        $raw = @file_get_contents($f);
        if ($raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function archiveLegacyIfPresent(int $teacherId): void
    {
        $f = $this->teacherDir($teacherId) . '/' . self::LEGACY_FILE;
        if (!is_file($f)) {
            return;
        }
        $bak = $f . self::LEGACY_BAK_EXT . '.' . date('Ymd-His');
        @rename($f, $bak);
    }

    private function writeWorkspace(int $teacherId, array $workspace): void
    {
        $file = $this->workspaceFile($teacherId);
        $json = json_encode($workspace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_workspace');
        }
        @chmod($file, 0664);
    }

    // ─────────────────────────── ELEMENT CRUD ───────────────────────────

    /**
     * Upsert di un elemento nel workspace. Se $oldLabel è passato e
     * differisce da $label, rinomina (rimuove l'item con $oldLabel,
     * inserisce/aggiorna quello con $label). Se l'item non esiste, crea.
     */
    public function saveElement(
        int $teacherId,
        string $groupKey,
        string $label,
        string $code,
        string $type = 'tikz',
        ?array $data = null,
        string $oldLabel = '',
    ): array {
        if ($groupKey === '' || $label === '' || $code === '') {
            throw new RuntimeException('group_label_or_code_missing');
        }
        if (!in_array($type, ['tikz', 'latex'], true)) {
            throw new RuntimeException('invalid_type');
        }
        $ws = $this->getWorkspace($teacherId);
        if (!isset($ws[$groupKey])) {
            $ws[$groupKey] = [];
        }
        $items = is_array($ws[$groupKey]) ? $ws[$groupKey] : [];

        $searchLabel = $oldLabel !== '' ? $oldLabel : $label;
        $found = -1;
        foreach ($items as $i => $it) {
            if (is_array($it) && (string)($it['label'] ?? '') === $searchLabel) {
                $found = $i;
                break;
            }
        }

        $newItem = [
            'label'   => $label,
            'content' => $code,
            'type'    => $type,
        ];
        if ($data !== null) {
            $newItem['data'] = $data;
        }
        $newItem['ts'] = time();

        if ($found === -1) {
            $items[] = $newItem;
        } else {
            // Preserva _origin se presente
            if (isset($items[$found]['_origin'])) {
                $newItem['_origin'] = $items[$found]['_origin'];
            }
            $items[$found] = $newItem;
        }

        $ws[$groupKey] = $items;
        $this->writeWorkspace($teacherId, $ws);
        return ['group' => $groupKey, 'label' => $label, 'created' => $found === -1];
    }

    /** Elimina un elemento. Se il gruppo resta vuoto, lo rimuove. */
    public function deleteElement(int $teacherId, string $groupKey, string $label): bool
    {
        if ($groupKey === '' || $label === '') {
            throw new RuntimeException('group_or_label_missing');
        }
        $ws = $this->getWorkspace($teacherId);
        if (!isset($ws[$groupKey]) || !is_array($ws[$groupKey])) {
            return false;
        }
        $before = count($ws[$groupKey]);
        $ws[$groupKey] = array_values(array_filter($ws[$groupKey], static fn($it) =>
            !(is_array($it) && (string)($it['label'] ?? '') === $label)));
        if (count($ws[$groupKey]) === $before) {
            return false;
        }
        if (count($ws[$groupKey]) === 0) {
            unset($ws[$groupKey]);
        }
        $this->writeWorkspace($teacherId, $ws);
        return true;
    }

    // ─────────────────────────── GROUP CRUD ───────────────────────────

    /** Rinomina la chiave di un gruppo. Idempotente. */
    public function renameGroup(int $teacherId, string $oldKey, string $newKey): bool
    {
        if ($oldKey === '' || $newKey === '' || $oldKey === $newKey) {
            return false;
        }
        $newKey = $this->normalizeGroupKey($newKey);
        $ws = $this->getWorkspace($teacherId);
        if (!isset($ws[$oldKey])) {
            throw new RuntimeException('group_not_found');
        }
        if (isset($ws[$newKey])) {
            throw new RuntimeException('target_group_exists');
        }
        // Preserva ordine: ricostruisce array sostituendo la chiave alla stessa posizione
        $rebuilt = [];
        foreach ($ws as $k => $v) {
            $rebuilt[$k === $oldKey ? $newKey : $k] = $v;
        }
        $this->writeWorkspace($teacherId, $rebuilt);
        return true;
    }

    public function deleteGroup(int $teacherId, string $groupKey): bool
    {
        $ws = $this->getWorkspace($teacherId);
        if (!isset($ws[$groupKey])) {
            return false;
        }
        unset($ws[$groupKey]);
        $this->writeWorkspace($teacherId, $ws);
        return true;
    }

    /** Ridordina i gruppi secondo $orderedKeys (chiavi non comprese restano in coda). */
    public function reorderGroups(int $teacherId, array $orderedKeys): bool
    {
        $ws = $this->getWorkspace($teacherId);
        $rebuilt = [];
        foreach ($orderedKeys as $k) {
            $k = (string)$k;
            if (isset($ws[$k])) {
                $rebuilt[$k] = $ws[$k];
                unset($ws[$k]);
            }
        }
        // append le chiavi rimanenti (non specificate)
        foreach ($ws as $k => $v) {
            $rebuilt[$k] = $v;
        }
        $this->writeWorkspace($teacherId, $rebuilt);
        return true;
    }

    // ─────────────────────────── RESET / IMPORT ───────────────────────────

    /** Reset all: sostituisce il workspace con una copia identica dei defaults admin attuali. */
    public function resetAll(int $teacherId): array
    {
        $admin = $this->getAdminLibrary();
        $this->writeWorkspace($teacherId, $admin);
        return $admin;
    }

    /**
     * Import di UN elemento dai defaults admin nel workspace docente.
     * @param string $conflict "abort" | "overwrite" | "rename" (suffisso "(admin)")
     * @return array{group:string,label:string,action:string} action ∈ {created, overwritten, renamed, aborted}
     */
    public function importFromAdmin(
        int $teacherId,
        string $sourceGroupKey,
        string $sourceLabel,
        string $targetGroupKey = '',
        string $conflict = 'abort',
    ): array {
        $admin = $this->getAdminLibrary();
        if (!isset($admin[$sourceGroupKey]) || !is_array($admin[$sourceGroupKey])) {
            throw new RuntimeException('admin_source_group_not_found');
        }
        $srcItem = null;
        foreach ($admin[$sourceGroupKey] as $it) {
            if (is_array($it) && (string)($it['label'] ?? '') === $sourceLabel) {
                $srcItem = $it;
                break;
            }
        }
        if ($srcItem === null) {
            throw new RuntimeException('admin_source_item_not_found');
        }

        $tgtGroup = $targetGroupKey !== '' ? $this->normalizeGroupKey($targetGroupKey) : $sourceGroupKey;
        $ws = $this->getWorkspace($teacherId);
        if (!isset($ws[$tgtGroup]) || !is_array($ws[$tgtGroup])) {
            $ws[$tgtGroup] = [];
        }

        // Conflict detection: stesso label esistente nel target group
        $existIdx = -1;
        foreach ($ws[$tgtGroup] as $i => $it) {
            if (is_array($it) && (string)($it['label'] ?? '') === $sourceLabel) {
                $existIdx = $i;
                break;
            }
        }

        $newItem = $srcItem;
        $newItem['_origin'] = $sourceGroupKey . '|' . $sourceLabel;
        $newItem['ts'] = time();
        $finalLabel = $sourceLabel;
        $action = 'created';

        if ($existIdx >= 0) {
            switch ($conflict) {
                case 'overwrite':
                    $ws[$tgtGroup][$existIdx] = $newItem;
                    $action = 'overwritten';
                    break;
                case 'rename':
                    $finalLabel = $sourceLabel . ' (admin)';
                    // assicura unicità
                    $i = 2;
                    while ($this->labelExists($ws[$tgtGroup], $finalLabel)) {
                        $finalLabel = $sourceLabel . " (admin {$i})";
                        $i++;
                    }
                    $newItem['label'] = $finalLabel;
                    $ws[$tgtGroup][] = $newItem;
                    $action = 'renamed';
                    break;
                case 'abort':
                default:
                    return ['group' => $tgtGroup, 'label' => $sourceLabel, 'action' => 'aborted'];
            }
        } else {
            $ws[$tgtGroup][] = $newItem;
        }

        $this->writeWorkspace($teacherId, $ws);
        return ['group' => $tgtGroup, 'label' => $finalLabel, 'action' => $action];
    }

    // ─────────────────────────── helpers ───────────────────────────

    private function labelExists(array $items, string $label): bool
    {
        foreach ($items as $it) {
            if (is_array($it) && (string)($it['label'] ?? '') === $label) {
                return true;
            }
        }
        return false;
    }

    private function normalizeGroupKey(string $name): string
    {
        if (str_starts_with($name, 'gruppo-')) {
            return $name;
        }
        $norm = preg_replace('/\s+/', ' ', $name) ?? $name;
        $norm = strtolower(trim($norm));
        $norm = str_replace(' ', '-', $norm);
        return 'gruppo-' . $norm;
    }
}
