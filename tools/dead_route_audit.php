<?php
/**
 * Phase 18 — Audit route morte.
 *
 * Scansiona routes/web.php per pattern route, poi grep nei JS/PHP per
 * vedere se esistono ancora caller. Flagga quelle senza referenze.
 *
 * Run:
 *   php tools/dead_route_audit.php
 */

declare(strict_types=1);

$base = \dirname(__DIR__);
$web  = \file_get_contents($base . '/routes/web.php') ?: '';

if (!\preg_match_all("#'(/[-\w/{}.*]+)'#", $web, $m)) exit("no routes found\n");
$patterns = \array_unique($m[1]);

$sourceFiles = [];
$iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if (!$f->isFile()) continue;
    $ext = \strtolower($f->getExtension());
    if (!\in_array($ext, ['php', 'js', 'ts', 'html'], true)) continue;
    $path = (string)$f->getPathname();
    if (\str_contains($path, '/vendor/') || \str_contains($path, '\\vendor\\')) continue;
    if (\str_contains($path, '/node_modules/') || \str_contains($path, '\\node_modules\\')) continue;
    if (\str_contains($path, '_archive_phase18')) continue;
    if (\str_contains($path, '/log/')) continue;
    if (\str_contains($path, 'routes/web.php')) continue;
    $sourceFiles[] = $path;
}

echo "Scansione " . \count($sourceFiles) . " file per " . \count($patterns) . " route pattern...\n\n";

$dead = [];
foreach ($patterns as $p) {
    // Estrai la parte statica della pattern (prima di '{')
    $static = \preg_replace('#\{[^}]*\}.*$#', '', $p);
    if (\strlen($static) < 4) continue; // troppo generica
    $found = false;
    foreach ($sourceFiles as $f) {
        $content = @\file_get_contents($f);
        if ($content === false) continue;
        if (\str_contains($content, $static)) { $found = true; break; }
    }
    if (!$found) $dead[] = $p;
}

if (!$dead) {
    echo "OK — nessuna route morta.\n";
    exit(0);
}

echo "Route morte (nessun riferimento JS/PHP esterno a routes/web.php):\n";
foreach ($dead as $d) echo "  $d\n";
echo "\nTotale: " . \count($dead) . "\n";
