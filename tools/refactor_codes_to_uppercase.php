<?php
/**
 * G22.S15.bis Fase 5+ — Refactor code lowercase legacy → uppercase canonico.
 *
 * Mapping:
 *   indirizzi:  sc → SCI, ar → ART, ling → LIN
 *   classe combinata exercises: sc{N}s → SCI{N}S, ar{N}s → ART{N}S, ling{N}s → LIN{N}S
 *
 * Aggiorna:
 *   - DB: tutte le colonne `indirizzo`/`scope_indirizzo`/`classe` rilevanti
 *   - Curriculum_entries.code (kind='indirizzi')
 *   - Filesystem: contenuto JSON contract + rename file suffix
 *
 * Run: php tools/refactor_codes_to_uppercase.php [--commit]
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;

$dryRun = !in_array('--commit', $_SERVER['argv'] ?? [], true);
$mode = $dryRun ? '🔵 DRY-RUN' : '🟢 COMMIT';
echo "=== Refactor codes — $mode ===\n\n";

if (!Database::isAvailable()) { fwrite(STDERR, "DB unavailable\n"); exit(1); }
$pdo = Database::connection();

$INDIR_MAP = [
    'sc'   => 'SCI',
    'ar'   => 'ART',
    'ling' => 'LIN',
    'afm'  => 'AFM',
];

// Build classe map (G22.S15.bis Fase 5+):
//   - 's' suffix legacy = "standard" (default) → DROP
//   - 'b' suffix = "breve" (Liceo breve) → keep uppercase B
// Esempi: sc1s → SCI1, ar3s → ART3, ling4s → LIN4, sc1b → SCI1B
$CLASSE_MAP = [];
foreach ($INDIR_MAP as $old => $new) {
    foreach (range(1, 5) as $n) {
        $CLASSE_MAP[$old . $n . 's'] = $new . $n;       // drop "s" standard
        $CLASSE_MAP[$old . $n . 'b'] = $new . $n . 'B'; // keep "b" breve
    }
}
// Second pass: cleanup intermedio (file gia migrati a SCIxS / ARTxS dal vecchio
// script) → strip trailing S. Idempotente (no-op se gia drop).
$SECOND_PASS = [];
foreach ($INDIR_MAP as $new) {
    foreach (range(1, 5) as $n) {
        $SECOND_PASS[$new . $n . 'S'] = $new . $n;
    }
}
$CLASSE_MAP = array_merge($CLASSE_MAP, $SECOND_PASS);

// Third pass: classe standalone con suffix lowercase → uppercase (1b → 1B, 2b → 2B, ...).
foreach (range(1, 9) as $n) {
    $CLASSE_MAP[$n . 'b'] = $n . 'B';
    $CLASSE_MAP[$n . 's'] = (string)$n; // legacy "s" standard → drop
}

// ── DB Step ──
// Tabelle/colonne per indirizzo + classe combinata.
$DB_PLAN = [
    // (table, col, type=indirizzo|classe, kind_filter)
    ['exercises',                    'indirizzo',       'indir'],
    ['exercises',                    'classe',          'classe_comb'],
    ['exercises',                    'materia',         'noop'], // skip
    ['teacher_content',              'indirizzo',       'indir'],
    ['print_info',                   'indirizzo',       'indir'],
    ['teacher_access_credentials',   'indirizzo',       'indir'],
    ['risdoc_templates',             'scope_indirizzo', 'indir'],
    ['risdoc_compilations',          'indirizzo',       'indir'],
    ['verifica_documents',           'indirizzo',       'indir'],
    ['verifica_template_packs',      'indirizzo',       'indir'],
    ['published_content_classe_keys','indirizzo',       'indir'],
    ['curriculum_entries',           'code',            'indir', "kind='indirizzi'"],
];

echo "── DB updates ──\n";
$totalDb = 0;
foreach ($DB_PLAN as $row) {
    [$tbl, $col, $type] = $row;
    if ($type === 'noop') continue;
    $filter = isset($row[3]) ? "AND " . $row[3] : '';
    try { $pdo->query("SELECT $col FROM $tbl LIMIT 0"); }
    catch (Throwable $e) { echo "  · skip $tbl.$col (no table/col)\n"; continue; }

    $map = ($type === 'classe_comb') ? $CLASSE_MAP : $INDIR_MAP;
    foreach ($map as $old => $new) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $tbl WHERE $col = ? $filter");
        $stmt->execute([$old]);
        $cnt = (int)$stmt->fetchColumn();
        if ($cnt === 0) continue;
        echo "  $tbl.$col: $cnt × '$old' → '$new'\n";
        if (!$dryRun) {
            $pdo->prepare("UPDATE $tbl SET $col = ? WHERE $col = ? $filter")
                ->execute([$new, $old]);
            $totalDb += $cnt;
        }
    }
}

// ── DB Step 2: storage_objects.storage_key (path string in DB) ──
echo "\n── DB storage_objects.storage_key ──\n";
$skUpdated = 0;
foreach ($CLASSE_MAP as $old => $new) {
    // Pattern: -sc1s.contract.json oppure /sc1s/ in path
    foreach (["-$old.", "_$old.", "/$old."] as $delimPattern) {
        $stmt = $pdo->prepare("SELECT id, storage_key FROM storage_objects WHERE storage_key LIKE ?");
        $stmt->execute(['%' . $delimPattern . '%']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$rows) continue;
        foreach ($rows as $r) {
            $newKey = str_replace($delimPattern, str_replace($old, $new, $delimPattern), $r['storage_key']);
            if ($newKey === $r['storage_key']) continue;
            if (!$dryRun) {
                $pdo->prepare("UPDATE storage_objects SET storage_key = ? WHERE id = ?")
                    ->execute([$newKey, $r['id']]);
            }
            $skUpdated++;
        }
    }
}
echo "  storage_objects.storage_key: $skUpdated rows updated\n";
$totalDb += $skUpdated;

// ── DB Step 3: teacher_content.metadata_json (contract_key + legacy_href + links) ──
echo "\n── DB teacher_content.metadata_json (string replace nei JSON values) ──\n";
$tcUpdated = 0;
$rows = $pdo->query('SELECT id, metadata_json FROM teacher_content WHERE metadata_json IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    $orig = (string)$r['metadata_json'];
    if ($orig === '' || $orig === 'null') continue;
    $new = $orig;
    // Replace classe combinata (sc1s → SCI1, ar3s → ART3, ...)
    foreach ($CLASSE_MAP as $oldC => $newC) {
        $new = str_replace($oldC, $newC, $new);
    }
    if ($new !== $orig) {
        if (!$dryRun) {
            $pdo->prepare('UPDATE teacher_content SET metadata_json = ? WHERE id = ?')
                ->execute([$new, (int)$r['id']]);
        }
        $tcUpdated++;
    }
}
echo "  teacher_content.metadata_json: $tcUpdated rows updated\n";
$totalDb += $tcUpdated;

// ── Filesystem JSON content + filename rename ──
echo "\n── Filesystem (JSON content + rename) ──\n";
$root = dirname(__DIR__);
$objectsRoot = $root . '/storage/objects/institutes';
$fsContent = 0;
$fsRenamed = 0;

if (is_dir($objectsRoot)) {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($objectsRoot, FilesystemIterator::SKIP_DOTS)
    );
    $allFiles = [];
    foreach ($iter as $f) { if ($f->isFile()) $allFiles[] = $f->getPathname(); }

    foreach ($allFiles as $path) {
        // 1. Rewrite JSON content (per file .json)
        if (str_ends_with($path, '.json')) {
            $content = file_get_contents($path);
            if ($content === false) { continue; }
            $orig = $content;
            // Replace per indirizzo
            foreach ($INDIR_MAP as $old => $new) {
                $content = preg_replace(
                    '/("indirizzo"\s*:\s*")' . preg_quote($old, '/') . '(")/u',
                    '${1}' . $new . '${2}',
                    $content
                );
                $content = preg_replace(
                    '/("scope_indirizzo"\s*:\s*")' . preg_quote($old, '/') . '(")/u',
                    '${1}' . $new . '${2}',
                    $content
                );
            }
            // Replace per classe combinata
            foreach ($CLASSE_MAP as $old => $new) {
                $content = preg_replace(
                    '/("classe"\s*:\s*")' . preg_quote($old, '/') . '(")/u',
                    '${1}' . $new . '${2}',
                    $content
                );
            }
            if ($content !== $orig) {
                $fsContent++;
                $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
                if (!$dryRun) file_put_contents($path, $content);
                if ($fsContent <= 5) echo "  ✎ JSON $rel\n";
            }
        }

        // 2. Rename file se filename ha suffix combinato.
        // Pattern: ...-{old}{N}{s|b}.contract.json (es. -sc1s.contract.json)
        $base = basename($path);
        $newBase = $base;
        foreach ($CLASSE_MAP as $old => $new) {
            // -sc1s.contract.json | -sc1s.json | _sc1s.json | etc
            $newBase = preg_replace(
                '/([-_])' . preg_quote($old, '/') . '(\.|[-_])/u',
                '${1}' . $new . '${2}',
                $newBase
            );
        }
        if ($newBase !== $base) {
            $newPath = dirname($path) . '/' . $newBase;
            $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
            if (!$dryRun) {
                if (file_exists($newPath)) {
                    echo "  ⚠️  collision: $newBase already exists, skip rename\n";
                } else {
                    rename($path, $newPath);
                    $fsRenamed++;
                }
            } else {
                $fsRenamed++;
            }
            if ($fsRenamed <= 5) echo "  ↻ rename $rel → $newBase\n";
        }
    }
    if ($fsContent > 5) echo "  … e altri " . ($fsContent - 5) . " file JSON modificati\n";
    if ($fsRenamed > 5) echo "  … e altri " . ($fsRenamed - 5) . " file rinominati\n";
} else {
    echo "  · skip (storage/objects/institutes not found)\n";
}

// ── Summary ──
echo "\n=== Summary ===\n";
if ($dryRun) {
    echo "🔵 DRY-RUN — nessuna modifica. Aggiungi --commit.\n";
}
echo "  DB rows: $totalDb\n";
echo "  JSON content updates: $fsContent\n";
echo "  Filesystem renames: $fsRenamed\n";
