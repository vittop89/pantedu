<?php

declare(strict_types=1);

namespace App\Services\Shortcuts;

use RuntimeException;

/**
 * Phase 25 — Modello "Scorciatoie LaTeX da tastiera" forkabile.
 *
 * Stesso modello dual-layer dei template TikZ
 * ({@see \App\Services\Tikz\TeacherTemplateOverridesService}):
 *
 *   - Riferimento super-admin: storage/data/latex_shortcuts_default.json
 *     `{ groupKey: [ {label, type, trigger|keys, snippet, desc} ] }`
 *   - Override docente:        storage/objects/teachers/{tid}/latex-shortcuts-overrides.json
 *     map keyed by "{groupKey}|{label}" → { snippet?, trigger?, keys?, enabled?, ts }
 *
 * Semantica:
 *   - Il docente vede il riferimento admin; "Personalizza" su una scorciatoia
 *     scrive un override per QUELLA (snippet/trigger/keys/enabled).
 *   - "Disabilita" = override con enabled=false (resta in lista UI, esclusa dal
 *     motore runtime).
 *   - "Reset" elimina l'override → il docente torna al riferimento admin.
 *   - "Effettivo" = merge admin + override, consumato dal motore JS.
 *
 * Le scorciatoie sono dato di sola produttività (nessun PII): le hotkey di
 * login dell'AHK originale sono volutamente ESCLUSE dal seed.
 */
final class LatexShortcutsService
{
    private const ADMIN_FILE_REL  = 'storage/data/latex_shortcuts_default.json';
    private const TEACHER_DIR_REL  = 'storage/objects/teachers';
    private const TEACHER_FILE      = 'latex-shortcuts-overrides.json';

    private string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim(str_replace('\\', '/', $basePath ?? dirname(__DIR__, 3)), '/');
    }

    private function adminFile(): string
    {
        return $this->basePath . '/' . self::ADMIN_FILE_REL;
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
        return $dir . '/' . self::TEACHER_FILE;
    }

    private function key(string $groupKey, string $label): string
    {
        return $groupKey . '|' . $label;
    }

    /** Riferimento admin (seed super-admin). @return array<string,array<int,array>> */
    public function getAdminDefaults(): array
    {
        $file = $this->adminFile();
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** @return array<string,array{snippet?:string,trigger?:string,keys?:string,enabled?:bool,ts:int}> */
    public function getOverrides(int $teacherId): array
    {
        if ($teacherId <= 0) {
            return [];
        }
        $file = $this->teacherFile($teacherId);
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Merge riferimento admin + override docente.
     * Ritorna la stessa forma `{ groupKey: [ {label, type, trigger|keys, snippet,
     * desc, _override?, _disabled?} ] }` consumata dal motore JS.
     */
    public function getEffective(int $teacherId): array
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
                $ov = $overrides[$this->key($groupKey, $label)] ?? null;
                if (is_array($ov)) {
                    $merged = $item;
                    if (isset($ov['snippet'])) {
                        $merged['snippet'] = (string)$ov['snippet'];
                    }
                    if (isset($ov['trigger'])) {
                        $merged['trigger'] = (string)$ov['trigger'];
                    }
                    if (isset($ov['keys'])) {
                        $merged['keys']    = (string)$ov['keys'];
                    }
                    if (array_key_exists('enabled', $ov)) {
                        $merged['_disabled'] = !((bool)$ov['enabled']);
                    }
                    $merged['_override'] = true;
                    $newItems[] = $merged;
                } else {
                    $newItems[] = $item;
                }
            }
            $out[$groupKey] = $newItems;
        }
        return $out;
    }

    /** Upsert override docente per (group,label). Solo i campi forniti (non-null). */
    public function saveOverride(int $teacherId, string $groupKey, string $label, array $fields): void
    {
        if ($groupKey === '' || $label === '') {
            throw new RuntimeException('group_or_label_missing');
        }
        $overrides = $this->getOverrides($teacherId);
        $entry = $overrides[$this->key($groupKey, $label)] ?? [];
        foreach (['snippet', 'trigger', 'keys'] as $k) {
            if (isset($fields[$k])) {
                $entry[$k] = (string)$fields[$k];
            }
        }
        if (array_key_exists('enabled', $fields)) {
            $entry['enabled'] = (bool)$fields['enabled'];
        }
        $entry['ts'] = time();
        $overrides[$this->key($groupKey, $label)] = $entry;
        $this->writeOverrides($teacherId, $overrides);
    }

    /** Elimina l'override per (group,label). No-op se assente. */
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

    /** Elimina TUTTI gli override del docente (torna integralmente al riferimento). */
    public function resetAll(int $teacherId): int
    {
        $overrides = $this->getOverrides($teacherId);
        $n = count($overrides);
        if ($n > 0) {
            $this->writeOverrides($teacherId, []);
        }
        return $n;
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

    // ───────────────────────── Admin (super-admin) ─────────────────────────

    /** Sovrascrive l'intero riferimento admin (validato dal controller). */
    public function saveAdminDefaults(array $groups): void
    {
        $json = json_encode($groups, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('encode_failed');
        }
        $file = $this->adminFile();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (file_put_contents($file, $json, LOCK_EX) === false) {
            throw new RuntimeException('cannot_write_admin');
        }
        @chmod($file, 0664);
    }
}
