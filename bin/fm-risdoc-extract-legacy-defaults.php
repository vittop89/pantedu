<?php
declare(strict_types=1);

/**
 * Legacy defaults extractor — Phase 21 Plan B.
 *
 * Scope: per template 16–25 (MODELLI + RISORSE), legge i .tex legacy e
 * popola:
 *   - `options`  dei checkbox-group  → da  %[BeginList-show]\item[\xcheckbox]LABEL
 *   - `default_rows`  dei dynamic-table  → da righe hardcoded in .tex (se applicabile)
 *
 * Modalità:
 *   --dry-run     stampa report without scrivere (default)
 *   --apply       modifica schemi in place (backup in /tmp)
 *
 * Pair-by-order: N-esimo BeginList-show → N-esimo checkbox-group (con options vuote).
 * Schema-tree walk: depth-first.
 */

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$root   = dirname(__DIR__);
$apply  = in_array('--apply', $argv, true);

/**
 * Explicit mapping: schema_group_name → tex_block_index.
 * Derived from dry-run inspection (bin/fm-risdoc-extract-legacy-defaults.php --dry).
 * Groups not listed here stay vuoti (caricati runtime da external JSON es.
 * competenze_DM2007.json, o non presenti nel legacy).
 */
$explicitMap = [
    16 => [
        'livelli_ingresso'           => 0,
        'metodologie_didattiche'     => 9,
        'strumenti_didattici'        => 10,
        'spazi_didattici'            => 11,
        'prove_strutturate'          => 12,
        'prove_semistrutturate'      => 13,
        'prove_non_strutturate'      => 14,
        'prove_traduzione'           => 15,
        'criteri_valutazione_finale' => 16,
        'recupero_curricolare'       => 17,
        'recupero_extracurricolare'  => 18,
        'valorizzazione_eccellenze'  => 19,
    ],
    17 => ['obiettivi_ptof' => 0],
    19 => [
        'metodologie_didattiche'    => 0,
        'strumenti_didattici'       => 1,
        'recupero_curricolare'      => 2,
        'recupero_extracurricolare' => 3,
        'potenziamento'             => 4,
    ],
    20 => [
        'carenze_riscontrate' => 0,
        'consigli_recupero'   => 1,
    ],
    21 => [
        'metodologie_didattiche' => 0,
        'strumenti_didattici'    => 1,
        'spazi_didattici'        => 2,
        'abilita_specifiche'     => 3,
    ],
];

/**
 * Textarea/input default values hard-coded nel legacy PHP.
 * Mapping: template_id → field_name → default_value.
 * field_name corrisponde al `name` nel schema (textarea/info-field/nota).
 */
$textDefaults = [
    19 => [
        'educazione_civica' => 'Nessuna.',
    ],
    21 => [
        'periodo' => 'dal 01/09/2023 al 30/06/2024',
        'risultati_specifici' => 'Particolari difficoltà sono state riscontrate per gli studenti delle classi ... specialmente per quanto riguarda le seguenti abilità',
    ],
];

$db   = Database::connection();
$rows = $db->query(
    'SELECT id, argomento, schema_path, source_dir, tex_file
       FROM risdoc_templates
      WHERE id BETWEEN 16 AND 25 AND schema_path IS NOT NULL AND schema_path <> "" AND tex_file IS NOT NULL
   ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC);

$totalTemplates = count($rows);
$totalGroupsFilled = 0;
$totalBlocksExtracted = 0;

foreach ($rows as $r) {
    $tid = (int)$r['id'];
    $label = $r['argomento'];
    // Map php dir → tex dir
    $texDir  = str_replace('/php', '/tex', $r['source_dir']);
    $texPath = $root . '/' . $texDir . '/' . $r['tex_file'];
    $schemaPath = $root . '/' . $r['schema_path'];

    echo str_repeat('=', 80) . PHP_EOL;
    echo "Template $tid — $label" . PHP_EOL;
    echo "  tex:    " . $r['source_dir'] . '/' . $r['tex_file'] . PHP_EOL;
    echo "  schema: " . $r['schema_path'] . PHP_EOL;

    if (!is_file($texPath)) {
        echo "  SKIP (tex not found)\n";
        continue;
    }
    if (!is_file($schemaPath)) {
        echo "  SKIP (schema not found)\n";
        continue;
    }

    $tex = (string)file_get_contents($texPath);
    $schema = json_decode((string)file_get_contents($schemaPath), true);
    if (!is_array($schema)) {
        echo "  SKIP (schema JSON parse error)\n";
        continue;
    }

    // 1. Extract all BeginList-show blocks that contain checkboxes
    $blocks = extractCheckboxBlocks($tex);
    echo "  extracted " . count($blocks) . " checkbox blocks from tex\n";
    foreach ($blocks as $i => $items) {
        echo sprintf("    [%d] (%d items) %s\n", $i, count($items), implode(' | ', array_slice($items, 0, 3)));
    }

    // 2. Find all checkbox-groups with empty options (depth-first)
    $emptyGroups = [];
    walkCheckboxGroups($schema['sections'] ?? [], $emptyGroups);
    echo "  found " . count($emptyGroups) . " checkbox-groups with empty options\n";

    // 3. Apply explicit mapping (schema_group_name → tex_block_index)
    $map = $explicitMap[$tid] ?? [];
    $willFill = 0;
    foreach ($emptyGroups as $i => $ref) {
        $node = $ref['node'];
        $name = $node['name'] ?? '';
        $mapped = $map[$name] ?? null;
        $status = $mapped !== null && isset($blocks[$mapped])
            ? sprintf('→ tex[%d] (%d items)', $mapped, count($blocks[$mapped]))
            : '(skipped — no mapping)';
        if ($mapped !== null && isset($blocks[$mapped])) $willFill++;
        echo sprintf("    [%d] name=%s title=%s  %s\n",
            $i, $name, $node['title'] ?? '(none)', $status);
    }
    echo "  WILL FILL: $willFill groups\n";

    // 4. Text defaults (textarea/info-field) legacy hardcoded values
    $defs = $textDefaults[$tid] ?? [];
    if ($defs) echo "  TEXT DEFAULTS: " . count($defs) . " (" . implode(', ', array_keys($defs)) . ")\n";

    $totalBlocksExtracted += count($blocks);
    $totalGroupsFilled    += $willFill;

    if ($apply && ($willFill > 0 || $defs)) {
        if ($willFill > 0) applyMappedOptions($schema['sections'], $blocks, $map);
        if ($defs) applyTextDefaults($schema['sections'], $defs);
        $out = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @mkdir(sys_get_temp_dir() . '/fm-risdoc-backup', 0777, true);
        $bkpath = sys_get_temp_dir() . '/fm-risdoc-backup/' . basename($schemaPath);
        copy($schemaPath, $bkpath);
        file_put_contents($schemaPath, $out);
        echo "  APPLIED — backup: $bkpath\n";
    }
}

echo str_repeat('=', 80) . PHP_EOL;
echo "SUMMARY: $totalTemplates templates processed\n";
echo "  $totalBlocksExtracted blocks extracted\n";
echo "  $totalGroupsFilled groups " . ($apply ? "populated" : "WOULD BE populated (dry-run)") . "\n";
if (!$apply) echo "\nRun with --apply to write changes.\n";

// ───────────────── helpers ─────────────────

/**
 * Extract checkbox items from %[BeginList-show]...%[EndList-show] blocks.
 * Returns array of arrays, each inner = list of label strings in order.
 * Blocks without checkbox items are skipped (e.g. tex snippets).
 */
function extractCheckboxBlocks(string $tex): array
{
    $blocks = [];
    $rx = '/%\s*\[BeginList-show\]([\s\S]*?)%\s*\[EndList-show\]/';
    if (!preg_match_all($rx, $tex, $m, PREG_SET_ORDER)) return [];

    foreach ($m as $match) {
        $body = $match[1];
        $items = [];
        // \item[\xcheckbox] or \item[\checkbox]
        if (preg_match_all('/\\\\item\[\\\\x?checkbox\]\s*([^\r\n]+)/', $body, $it, PREG_SET_ORDER)) {
            foreach ($it as $line) {
                $label = trim($line[1]);
                // strip trailing LaTeX commands/comments
                $label = preg_replace('/\s*%.*$/', '', $label);
                $label = preg_replace('/\\\\\\\\\s*$/', '', $label); // \\ end-of-line
                $label = trim($label);
                if ($label !== '') $items[] = $label;
            }
        }
        if (!empty($items)) $blocks[] = $items;
    }
    return $blocks;
}

/**
 * Depth-first walk: collect references to checkbox-group nodes with empty `options`.
 * Skips `items` of type checkbox-group (those are containers).
 */
function walkCheckboxGroups(array $nodes, array &$out): void
{
    foreach ($nodes as $n) {
        if (!is_array($n)) continue;
        if (($n['type'] ?? '') === 'checkbox-group') {
            $hasEmpty = isset($n['options']) && is_array($n['options']) && empty($n['options']);
            if ($hasEmpty) {
                $out[] = ['node' => $n];
            }
            if (isset($n['items']) && is_array($n['items'])) {
                walkCheckboxGroups($n['items'], $out);
            }
        } elseif (isset($n['items']) && is_array($n['items'])) {
            walkCheckboxGroups($n['items'], $out);
        }
    }
}

/**
 * Apply `default` on matching textarea/info-field by name.
 */
function applyTextDefaults(array &$nodes, array $defs): void
{
    foreach ($nodes as &$n) {
        if (!is_array($n)) continue;
        $type = $n['type'] ?? '';
        $name = $n['name'] ?? '';
        if (in_array($type, ['nota-textarea', 'text-section', 'info-field'], true) && isset($defs[$name])) {
            $n['default'] = $defs[$name];
        }
        if (isset($n['items']) && is_array($n['items'])) {
            applyTextDefaults($n['items'], $defs);
        }
    }
    unset($n);
}

/**
 * Apply options via name→block-index map.
 */
function applyMappedOptions(array &$nodes, array $blocks, array $map): void
{
    foreach ($nodes as &$n) {
        if (!is_array($n)) continue;
        if (($n['type'] ?? '') === 'checkbox-group') {
            $hasEmpty = isset($n['options']) && is_array($n['options']) && empty($n['options']);
            $name = $n['name'] ?? '';
            if ($hasEmpty && isset($map[$name]) && isset($blocks[$map[$name]])) {
                $labels = $blocks[$map[$name]];
                $n['options'] = array_map(fn($l) => ['value' => $l, 'label' => $l], $labels);
            }
            if (isset($n['items']) && is_array($n['items'])) {
                applyMappedOptions($n['items'], $blocks, $map);
            }
        } elseif (isset($n['items']) && is_array($n['items'])) {
            applyMappedOptions($n['items'], $blocks, $map);
        }
    }
    unset($n);
}
