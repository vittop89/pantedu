<?php
/**
 * Phase 18 — Inventario filesystem content legacy.
 *
 * Scansiona eser/, verifiche/, lab/, mappe/, didattica/, risdoc/,
 * strcomp_bes_altro/, drafts/ e confronta con `teacher_content` DB.
 * Output: file .php filesystem marcati come (migrato | orfano).
 *
 * Orfani = potenzialmente contenuti non importati → archiviare in
 *          `_archive_phase18/filesystem/` dopo review.
 *
 * Run:
 *   php tools/list_filesystem_content.php
 *   php tools/list_filesystem_content.php --csv > report.csv
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;

$csv = \in_array('--csv', $argv, true);
$base = \dirname(__DIR__);

$roots = ['eser', 'verifiche', 'lab', 'mappe', 'didattica', 'risdoc', 'strcomp_bes_altro', 'drafts'];

$dbContracts = [];
if (Config::get('database.enabled') && Database::isAvailable()) {
    $pdo = Database::connection();
    $rows = $pdo->query("SELECT id, content_type, subject_code, topic, title, indirizzo, classe FROM teacher_content")
                ->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $r) {
        $key = \strtolower(\trim($r['title'] ?? '')) . '|' . \strtolower(\trim($r['subject_code'] ?? ''));
        $dbContracts[$key] = true;
    }
}

$total  = 0;
$migrat = 0;
$orphan = 0;

if ($csv) echo "root,path,title_guess,status\n";
foreach ($roots as $root) {
    $dir = $base . '/' . $root;
    if (!\is_dir($dir)) continue;
    $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $f) {
        if (!$f->isFile()) continue;
        if (\strtolower($f->getExtension()) !== 'php') continue;
        $total++;
        $name = $f->getBasename('.php');
        $parts = \explode('-', $name, 3);
        $title = isset($parts[1]) ? \str_replace('_', ' ', $parts[1]) : $name;
        $key = \strtolower(\trim($title)) . '|';
        $status = 'orphan';
        foreach ($dbContracts as $k => $_) {
            if (\str_starts_with($k, \strtolower(\trim($title)) . '|')) { $status = 'migrated'; break; }
        }
        if ($status === 'migrated') $migrat++; else $orphan++;
        if ($csv) {
            echo "$root," . \str_replace(',', ';', (string)$f->getPathname()) . ',' . \str_replace(',', ';', $title) . ",$status\n";
        }
    }
}

if (!$csv) {
    echo "FS scan completato. Totale: $total\n";
    echo "  Migrati:  $migrat\n";
    echo "  Orfani:   $orphan\n";
    echo "Usa --csv per il dettaglio per-file.\n";
}
