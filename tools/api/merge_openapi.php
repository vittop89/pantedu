<?php

declare(strict_types=1);

/**
 * Phase 26 — Merge generated openapi.yaml with manual overlay.
 *
 * Strategy:
 *   1. Load `docs/api/openapi.yaml` (auto-generated scaffold, all 187 ops).
 *   2. Load `docs/api/openapi.overlay.yaml` (manual annotations on ~30 critical
 *      endpoints with full request/response schemas).
 *   3. Deep-merge: overlay paths replace scaffold paths (where defined),
 *      overlay components.schemas append/override scaffold ones.
 *   4. Output to `docs/api/openapi.full.yaml` (final spec, ready for Swagger UI
 *      / Redoc / API client codegen).
 *
 * Usage:
 *   php tools/api/merge_openapi.php > docs/api/openapi.full.yaml
 */

require __DIR__ . '/../../vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$base    = Yaml::parseFile(__DIR__ . '/../../docs/api/openapi.yaml');
$overlay = Yaml::parseFile(__DIR__ . '/../../docs/api/openapi.overlay.yaml');

// Deep merge: overlay overrides on key match
function deepMerge(array $base, array $overlay): array
{
    foreach ($overlay as $k => $v) {
        if (is_array($v) && isset($base[$k]) && is_array($base[$k]) && isAssoc($v) && isAssoc($base[$k])) {
            $base[$k] = deepMerge($base[$k], $v);
        } else {
            $base[$k] = $v;
        }
    }
    return $base;
}

function isAssoc(array $a): bool
{
    if ($a === []) return true;
    return array_keys($a) !== range(0, count($a) - 1);
}

$merged = deepMerge($base, $overlay);

// Output YAML — Symfony serializer dumps keys in insertion order
echo Yaml::dump($merged, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_NULL_AS_TILDE);
