<?php

declare(strict_types=1);

/**
 * Wiki cleanup — converte link markdown a codice sorgente in path testuali.
 *
 *   [label](../app/Core/Foo.php)  →  `app/Core/Foo.php`
 *   [Foo.php](app/Core/Foo.php)   →  `app/Core/Foo.php`
 *
 * Razionale: Obsidian indicizza solo wikilink interni `[[wikilink]]`. I link
 * markdown standard a file PHP/JS sporcano il grafo creando "nodi finti"
 * che non sono wiki page (vedi grafo Obsidian: 100+ Foo.php nodes).
 *
 * Usage:
 *   php tools/wiki/strip_code_links.php [--dry-run] [--check]
 *
 * --dry-run: stampa diff senza modificare
 * --check:   exit 1 se ci sono link a code (per CI)
 */

$dryRun = in_array('--dry-run', $argv, true);
$check  = in_array('--check', $argv, true);

$wikiDir = __DIR__ . '/../../wiki';
$pattern = '/\[([^\]]+)\]\(((?:\.\.\/)*(?:app|js|tools|tests|database|public|css|views|routes)\/[^)#]+)(?:#L?\d+(?:-L?\d+)?)?\)/';

$totalFiles    = 0;
$totalReplaced = 0;
$violations    = [];

$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wikiDir));
foreach ($it as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'md') continue;
    $path = $file->getPathname();
    $orig = file_get_contents($path);
    if ($orig === false) continue;

    $count = 0;
    $new = preg_replace_callback($pattern, function ($m) use (&$count) {
        $count++;
        // Strip "../" prefix per uniformità (resta path repo-relative)
        $codePath = preg_replace('#^(\.\./)+#', '', $m[2]);
        return '`' . $codePath . '`';
    }, $orig);

    if ($count > 0) {
        $totalFiles++;
        $totalReplaced += $count;
        $rel = str_replace($wikiDir . DIRECTORY_SEPARATOR, '', $path);
        $rel = str_replace('\\', '/', $rel);
        $violations[] = "$rel: $count link";
        if (!$dryRun && !$check) {
            file_put_contents($path, $new);
        }
    }
}

if ($check) {
    if ($totalReplaced > 0) {
        fprintf(STDERR, "[strip_code_links] FAIL: %d code links in %d wiki files\n", $totalReplaced, $totalFiles);
        foreach ($violations as $v) fprintf(STDERR, "  $v\n");
        exit(1);
    }
    echo "[strip_code_links] OK: 0 code links in wiki/\n";
    exit(0);
}

$action = $dryRun ? 'WOULD REPLACE' : 'REPLACED';
echo "[strip_code_links] $action $totalReplaced links in $totalFiles files\n";
foreach ($violations as $v) echo "  $v\n";
