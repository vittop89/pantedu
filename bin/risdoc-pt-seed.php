<?php declare(strict_types=1);

/**
 * Risdoc Portable Text seed CLI (Phase 22.4b + 22.6).
 *
 * Popola il campo `default` (PT AST) nei schema JSON risdoc dai blocchi
 * `%[BeginTesto]...%[EndTesto]` dei template .tex legacy.
 *
 * Args:
 *   --schema=FILE     schema singolo (es. piano-annuale-docente.json)
 *   --all             tutti gli schemi in schemas/risdoc/*.json
 *   --apply           scrivi (default: dry-run)
 *   --verbose         stampa PT AST per ogni field seeded
 *   --auto-annotate   (Phase 22.6) prima del seed, fuzzy-match field labels
 *                      con sectionbox del .tex e popola tex_source hints
 *                      mancanti. Idempotente: se tex_source presente, skip.
 *
 * Esempi:
 *   php bin/risdoc-pt-seed.php --schema=piano-annuale-docente.json
 *   php bin/risdoc-pt-seed.php --all --auto-annotate --apply
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Services\Risdoc\Pt\SchemaSeeder;
use App\Services\Risdoc\Pt\TexSourceAutoDetector;

// ── parse args ──

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--')) {
        $kv = explode('=', substr($arg, 2), 2);
        $opts[$kv[0]] = $kv[1] ?? true;
    }
}

$apply        = !empty($opts['apply']);
$verbose      = !empty($opts['verbose']);
$all          = !empty($opts['all']);
$single       = $opts['schema'] ?? null;
$autoAnnotate = !empty($opts['auto-annotate']);

if (!$all && !$single) {
    fwrite(STDERR, "Usage: php bin/risdoc-pt-seed.php [--schema=FILE | --all] [--apply] [--verbose]\n");
    exit(1);
}

$repoRoot  = dirname(__DIR__);
$schemaDir = $repoRoot . '/schemas/risdoc';
$texRoot   = $repoRoot . '/storage/templates/risdoc';

$schemaFiles = $all
    ? array_filter(scandir($schemaDir) ?: [], fn($f) =>
        str_ends_with($f, '.json')
        && $f !== 'template.schema.json'
        && !str_starts_with($f, '_'))
    : [$single];

$seeder   = new SchemaSeeder($texRoot);
$detector = new TexSourceAutoDetector($texRoot);
$totals   = ['annotated' => 0, 'seeded' => 0, 'skipped' => 0, 'schemas_modified' => 0];

foreach ($schemaFiles as $file) {
    $full = $schemaDir . '/' . $file;
    if (!is_file($full)) {
        echo "MISS  $file (file non trovato)\n";
        continue;
    }
    $schema = json_decode((string)file_get_contents($full), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($schema)) {
        echo "SKIP  $file (JSON non-object)\n";
        continue;
    }

    // Phase 22.6 — auto-annotate tex_source prima del seed (idempotente).
    if ($autoAnnotate) {
        $autoResult = $detector->annotate($schema);
        $schema = $autoResult['schema'];
        $annotated = 0;
        foreach ($autoResult['report'] as $entry) {
            if (($entry['status'] ?? '') === 'annotated') {
                $annotated++;
                echo sprintf("  @ %s [%s]: %s → sectionbox=%s\n",
                    $entry['name'] ?? '?', $entry['path'] ?? '?',
                    $entry['subsection'] ?? '?', $entry['section'] ?? '?');
            } elseif (($entry['status'] ?? '') === 'no_match' && $verbose) {
                echo sprintf("  ? %s [%s]: nessun match per label \"%s\"\n",
                    $entry['name'] ?? '?', $entry['path'] ?? '?',
                    $entry['label'] ?? '?');
            } elseif (in_array($entry['status'] ?? '', ['no_tex_file', 'tex_unreadable', 'no_blocks_in_tex'], true) && $verbose) {
                echo sprintf("  ! %s: %s\n", $entry['status'], $entry['detail'] ?? $entry['file'] ?? '');
            }
        }
        if ($annotated > 0) $totals['annotated'] += $annotated;
    }

    $result = $seeder->seed($schema);
    $stats  = SchemaSeeder::summarize($result['report']);
    $tag    = $stats['seeded'] > 0 ? '✓' : '—';

    echo sprintf("%s  %-50s  seed=%d skip=%d\n",
        $tag, $file, $stats['seeded'], $stats['skipped']);

    foreach ($result['report'] as $entry) {
        $status = $entry['status'] ?? '?';
        $name   = $entry['name'] ?? '?';
        $path   = $entry['path'] ?? '?';
        $icon   = $status === 'seeded' ? '  +' : '  -';
        echo sprintf("%s %s [%s]: %s", $icon, $name, $path, $status);
        if (isset($entry['blocks'])) echo " ({$entry['blocks']} blocks)";
        if (isset($entry['detail'])) echo " — {$entry['detail']}";
        echo "\n";
        if ($verbose && $status === 'seeded') {
            // Grep PT inject
            $ref = &findFieldByPath($result['schema'], (string)($entry['path'] ?? ''));
            if (is_array($ref) && isset($ref['default'])) {
                echo "    " . str_replace("\n", "\n    ",
                    (string)json_encode($ref['default'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "\n";
            }
        }
    }

    // Modify se: (a) seed ha popolato defaults OR (b) auto-annotate ha
    // aggiunto tex_source hints → in entrambi i casi lo schema in-memory
    // è diverso da disk e va persistito.
    $originalSchema = json_decode((string)file_get_contents($full), true);
    $schemaChanged = $result['schema'] !== $originalSchema;
    $totals['seeded'] += $stats['seeded'];
    if ($schemaChanged) {
        $totals['schemas_modified']++;
        if ($apply) {
            $newJson = json_encode($result['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            file_put_contents($full, (string)$newJson . "\n");
            echo "    → written $file\n";
        }
    }
    $totals['skipped'] += $stats['skipped'];
}

echo "\n";
echo "============================================================\n";
echo sprintf(" Complete: %d annotated, %d seeded, %d skipped, %d schemas modified\n",
    $totals['annotated'], $totals['seeded'], $totals['skipped'], $totals['schemas_modified']);
if (!$apply && $totals['seeded'] > 0) {
    echo " Dry-run mode. Relanciare con --apply per scrivere su disco.\n";
}
echo "============================================================\n";

// ── helpers ──

/** Trova il field nel schema dato il path dotted. */
function &findFieldByPath(array &$schema, string $path)
{
    $null = null;
    if ($path === '') return $null;
    $parts = explode('.', $path);
    $ref = &$schema;
    foreach ($parts as $p) {
        $k = is_numeric($p) ? (int)$p : $p;
        if (!is_array($ref) || !array_key_exists($k, $ref)) return $null;
        $ref = &$ref[$k];
    }
    return $ref;
}
