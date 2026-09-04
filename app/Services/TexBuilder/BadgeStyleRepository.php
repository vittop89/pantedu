<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

use App\Support\Storage\StorageFactory;
use Throwable;

/**
 * G27.badge.style — Repository per la preferenza stile-badge del docente.
 *
 * Path canonico:
 *   institutes/{instituteId}/private/{teacherId}/badge_style.json
 *
 * Schema persistenza (NEW v2):
 *   {
 *     "$schema": "pantedu.badge_style_pref.v1",
 *     "preset": "compact",                    ← nome preset admin scelto
 *     "overrides": {                          ← campi sovrascritti dal docente
 *       "fonte": { "title_size": "\\footnotesize" },
 *       "badge": { "bg": "blue", "min_width": "1.5cm" }
 *     }
 *   }
 *
 * loadResolved(iid, tid, instituteCode) → BadgeStyle finale:
 *   1. Carica preset admin (cascade istituto → _default) via PresetStore
 *   2. Applica overrides docente sopra il preset
 */
final class BadgeStyleRepository
{
    /** Restituisce {preset, overrides} grezzi dal file teacher (per UI). */
    public static function loadPreference(int $instituteId, int $teacherId): array
    {
        if ($instituteId <= 0 || $teacherId <= 0) {
            return ['preset' => BadgeStylePresetStore::PRESET_DEFAULT, 'overrides' => []];
        }
        try {
            $bytes = StorageFactory::default()->get(self::pathFor($instituteId, $teacherId));
            $data  = json_decode((string)$bytes, true);
            if (\is_array($data)) {
                $preset    = (string)($data['preset'] ?? BadgeStylePresetStore::PRESET_DEFAULT);
                $overrides = \is_array($data['overrides'] ?? null) ? $data['overrides'] : [];
                if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $preset)) {
                    return ['preset' => $preset, 'overrides' => $overrides];
                }
            }
        } catch (Throwable) {
            // file assente o corrotto → fallback
        }
        return ['preset' => BadgeStylePresetStore::PRESET_DEFAULT, 'overrides' => []];
    }

    /**
     * Carica BadgeStyle finale: preset admin (cascade istituto) + overrides
     * docente. Best-effort: ogni livello ha fallback ai default hardcoded.
     */
    public static function loadResolved(int $instituteId, int $teacherId, string $instituteCode = BadgeStylePresetStore::SCOPE_DEFAULT): BadgeStyle
    {
        $pref = self::loadPreference($instituteId, $teacherId);
        $style = BadgeStylePresetStore::loadPreset($instituteCode, (string)$pref['preset']);
        if (!empty($pref['overrides']) && \is_array($pref['overrides'])) {
            $style->applyArray($pref['overrides']);
        }
        return $style;
    }

    /**
     * Salva la preferenza docente (preset + overrides). Sanitizza il preset
     * name; gli overrides vengono passati cosi' come sono — la sanitizzazione
     * dei valori e' applicata da BadgeStyle::applyArray al load successivo.
     *
     * @throws \RuntimeException su write failure
     */
    public static function savePreference(int $instituteId, int $teacherId, string $preset, array $overrides): void
    {
        if ($instituteId <= 0 || $teacherId <= 0) {
            throw new \RuntimeException('badge_style:invalid_ids');
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $preset)) {
            throw new \RuntimeException('badge_style:invalid_preset_name');
        }
        $payload = [
            '$schema'      => 'pantedu.badge_style_pref.v1',
            'teacher_id'   => $teacherId,
            'institute_id' => $instituteId,
            'preset'       => $preset,
            'overrides'    => self::filterOverrides($overrides),
            'generated_at' => date('c'),
        ];
        $bytes = (string)json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        try {
            StorageFactory::default()->put(self::pathFor($instituteId, $teacherId), $bytes);
        } catch (Throwable $e) {
            throw new \RuntimeException('badge_style:write_failed:' . $e->getMessage(), 0, $e);
        }
    }

    private static function pathFor(int $instituteId, int $teacherId): string
    {
        return "institutes/{$instituteId}/private/{$teacherId}/badge_style.json";
    }

    /**
     * Mantieni solo i campi noti negli overrides (silently strip extra keys).
     * Sanitizzazione di valore avviene al load via BadgeStyle::applyArray.
     */
    private static function filterOverrides(array $overrides): array
    {
        $allowed = [
            'fonte' => ['title_size', 'meta_size', 'row_sep', 'col_spec', 'vpad'],
            'badge' => ['bg', 'txt', 'ex_size', 'min_width', 'diff_max', 'diff_size'],
        ];
        $out = [];
        foreach ($allowed as $section => $fields) {
            if (!isset($overrides[$section]) || !\is_array($overrides[$section])) {
                continue;
            }
            $out[$section] = [];
            foreach ($fields as $f) {
                if (\array_key_exists($f, $overrides[$section])) {
                    $out[$section][$f] = $overrides[$section][$f];
                }
            }
            if (!$out[$section]) {
                unset($out[$section]);
            }
        }
        return $out;
    }
}
