<?php

declare(strict_types=1);

namespace App\Services\TexBuilder;

use Throwable;

/**
 * G27.badge.style — Preset admin per BadgeStyle.
 *
 * Layout (mirror della cascade di TemplateFileStore — istituto → _default):
 *   storage/templates/verifiche/_default/badge_styles/_default.json   ← seed
 *   storage/templates/verifiche/_default/badge_styles/compact.json
 *   storage/templates/verifiche/_default/badge_styles/wide.json
 *   storage/templates/verifiche/{instituteCode}/badge_styles/{name}.json
 *
 * Read cascade: prima {instituteCode}/badge_styles/{name}.json, fallback
 * _default/badge_styles/{name}.json. Write: scrive sempre nello scope
 * esplicito passato (admin sceglie quale).
 *
 * Allowlist nome preset: [a-zA-Z0-9_-]{1,64} per evitare path traversal.
 */
final class BadgeStylePresetStore
{
    public const SCOPE_DEFAULT  = '_default';
    public const PRESET_DEFAULT = '_default';
    private const NAME_RE       = '/^[a-zA-Z0-9_-]{1,64}$/';

    public static function rootDir(): string
    {
        return \dirname(__DIR__, 3) . '/storage/templates/verifiche';
    }

    public static function assertNameValid(string $name): void
    {
        if (!preg_match(self::NAME_RE, $name)) {
            throw new \RuntimeException('badge_style_preset:invalid_name:' . $name);
        }
    }

    public static function assertScopeValid(string $scope): void
    {
        if ($scope === self::SCOPE_DEFAULT) {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9_-]{2,64}$/', $scope)) {
            throw new \RuntimeException('badge_style_preset:invalid_scope:' . $scope);
        }
    }

    /**
     * Lista i preset disponibili nello scope (con fallback a _default).
     * Ritorna nomi distinti (un preset definito sia in {scope} sia in
     * _default appare una volta, con override scope vincente in load()).
     *
     * @return list<string> nomi preset
     */
    public static function listAvailable(string $scope): array
    {
        self::assertScopeValid($scope);
        $names = [];
        foreach (array_unique([$scope, self::SCOPE_DEFAULT]) as $sc) {
            $dir = self::rootDir() . "/$sc/badge_styles";
            if (!is_dir($dir)) {
                continue;
            }
            foreach ((array)glob($dir . '/*.json') as $f) {
                $base = basename((string)$f, '.json');
                if (preg_match(self::NAME_RE, $base)) {
                    $names[$base] = true;
                }
            }
        }
        $list = array_keys($names);
        sort($list);
        return $list;
    }

    /**
     * Cascade load: {scope}/badge_styles/{name}.json → _default/.../{name}.json.
     * Se nemmeno _default ha il preset richiesto, ritorna BadgeStyle hardcoded
     * defaults (no error: pattern resilient).
     */
    public static function loadPreset(string $scope, string $name): BadgeStyle
    {
        self::assertScopeValid($scope);
        self::assertNameValid($name);
        foreach (array_unique([$scope, self::SCOPE_DEFAULT]) as $sc) {
            $path = self::rootDir() . "/$sc/badge_styles/$name.json";
            if (is_file($path)) {
                try {
                    $data = json_decode((string)@file_get_contents($path), true);
                    if (\is_array($data)) {
                        return BadgeStyle::fromArray($data);
                    }
                } catch (Throwable) {
                    // Continua col fallback
                }
            }
        }
        return new BadgeStyle();
    }

    /**
     * Salva preset nello scope esplicito. Crea la dir se manca.
     * @throws \RuntimeException su write failure o input invalido
     */
    public static function savePreset(string $scope, string $name, BadgeStyle $style): void
    {
        self::assertScopeValid($scope);
        self::assertNameValid($name);
        $dir = self::rootDir() . "/$scope/badge_styles";
        if (!is_dir($dir) && !@mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new \RuntimeException('badge_style_preset:mkdir_failed:' . $dir);
        }
        $path = "$dir/$name.json";
        $payload = $style->toArray();
        $payload['preset_name'] = $name;
        $payload['scope']       = $scope;
        $payload['generated_at'] = date('c');
        $bytes = (string)json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('badge_style_preset:write_failed:' . $path);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('badge_style_preset:rename_failed:' . $path);
        }
    }

    /**
     * Elimina un preset dello scope esplicito (NON cancella mai _default
     * cross-scope: per rimuovere un preset dal sistema serve eliminare
     * anche dal scope _default). Idempotente: no-op se file assente.
     */
    public static function deletePreset(string $scope, string $name): void
    {
        self::assertScopeValid($scope);
        self::assertNameValid($name);
        if ($name === self::PRESET_DEFAULT && $scope === self::SCOPE_DEFAULT) {
            throw new \RuntimeException('badge_style_preset:cannot_delete_seed_default');
        }
        $path = self::rootDir() . "/$scope/badge_styles/$name.json";
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
