<?php declare(strict_types=1);

/**
 * Risdoc PT Unify CLI (Phase 24.8).
 *
 * Annota `pt_unified: true` sulle sezioni degli schemi risdoc che hanno
 * struttura adatta a rendering PT unificato. Il flag abilita il rendering
 * via `<fm-risdoc-pt-section>` invece dei wrapper granulari.
 *
 * Criterio include:
 *   - dynamic-table         (riga/colonne editabili → PT table block)
 *   - checkbox-group        (options → PT checkboxGroup)
 *   - grade-selector        (→ PT select)
 *   - giudizio-group        (→ PT sectionHeader + N select)
 *   - text-section          (container con items misti → walk ricorsivo)
 *
 * Criterio esclude (presentational, no form state):
 *   - header, static-content, privacy-block, signature-block,
 *     glossary-table, info-field (single inline, già handled)
 *
 * Args:
 *   --schema=FILE    schema singolo (es. piano-annuale-docente.json)
 *   --all            tutti schemas/risdoc/*.json
 *   --apply          scrivi (default: dry-run)
 *   --force          annota anche sezioni già con pt_unified:false
 */

require __DIR__ . '/../vendor/autoload.php';

$opts = [];
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--')) {
        $kv = \explode('=', \substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    }
}

$apply  = !empty($opts['apply']);
$force  = !empty($opts['force']);
$all    = !empty($opts['all']);
$single = $opts['schema'] ?? null;

if (!$all && !$single) {
    \fwrite(STDERR, "Usage: php bin/risdoc-pt-unify.php [--schema=FILE | --all] [--apply] [--force]\n");
    exit(1);
}

$repoRoot  = \dirname(__DIR__);
$schemaDir = $repoRoot . '/schemas/risdoc';

$INCLUDE_TYPES = [
    'dynamic-table'     => true,
    'checkbox-group'    => true,
    'grade-selector'    => true,
    'giudizio-group'    => true,
    'giudizio-item'     => true,
    'text-section'      => true,
];

$schemaFiles = $all
    ? \array_filter(\scandir($schemaDir) ?: [], fn($f) =>
        \str_ends_with($f, '.json')
        && $f !== 'template.schema.json'
        && !\str_starts_with($f, '_'))
    : [$single];

$totals = ['annotated' => 0, 'skipped' => 0, 'already' => 0, 'files_modified' => 0];

foreach ($schemaFiles as $file) {
    $full = $schemaDir . '/' . $file;
    if (!\is_file($full)) {
        echo "MISS  $file (file non trovato)\n";
        continue;
    }
    $schema = \json_decode((string)\file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);
    if (!\is_array($schema)) {
        echo "SKIP  $file (JSON non-object)\n";
        continue;
    }

    $report = ['annotated' => 0, 'already' => 0, 'skipped' => 0, 'details' => []];
    $sections = &$schema['sections'];
    if (!\is_array($sections)) {
        echo "—     $file (no sections)\n";
        continue;
    }
    foreach ($sections as $idx => &$section) {
        if (!\is_array($section)) continue;
        $type = (string)($section['type'] ?? '');
        if (!isset($INCLUDE_TYPES[$type])) {
            $report['skipped']++;
            $report['details'][] = "skip [{$idx}] type={$type}";
            continue;
        }
        if (isset($section['pt_unified']) && $section['pt_unified'] === true && !$force) {
            $report['already']++;
            $report['details'][] = "already [{$idx}] " . ($section['title'] ?? $type);
            continue;
        }
        $section['pt_unified'] = true;
        $report['annotated']++;
        $title = (string)($section['title'] ?? $section['name'] ?? $type);
        $report['details'][] = "annotate [{$idx}] ({$type}) {$title}";
    }
    unset($section);

    $tag = $report['annotated'] > 0 ? '✓' : '—';
    echo \sprintf("%s  %-50s  +%d (already=%d, skip=%d)\n",
        $tag, $file, $report['annotated'], $report['already'], $report['skipped']);
    foreach ($report['details'] as $line) echo "    $line\n";

    if ($report['annotated'] > 0) {
        $totals['annotated']      += $report['annotated'];
        $totals['files_modified'] += 1;
        if ($apply) {
            $newJson = \json_encode($schema,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            \file_put_contents($full, (string)$newJson . "\n");
            echo "    → written $file\n";
        }
    }
    $totals['already'] += $report['already'];
    $totals['skipped'] += $report['skipped'];
}

echo "\n============================================================\n";
echo \sprintf(" Unify complete: +%d annotated, %d already, %d skipped, %d files modified\n",
    $totals['annotated'], $totals['already'], $totals['skipped'], $totals['files_modified']);
if (!$apply && $totals['annotated'] > 0) {
    echo " Dry-run mode. Relanciare con --apply per scrivere.\n";
}
echo "============================================================\n";
