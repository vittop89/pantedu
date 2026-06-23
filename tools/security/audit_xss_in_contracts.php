<?php

declare(strict_types=1);

/**
 * G24.phase5 — One-time audit dei contract JSON storati per pattern XSS.
 *
 * NON modifica niente. Solo report dei file/path che contengono pattern
 * sospetti (`<script`, `javascript:`, `onclick=`, `onerror=`, ecc).
 *
 * Usage:
 *   php tools/security/audit_xss_in_contracts.php
 *   php tools/security/audit_xss_in_contracts.php --root=storage/objects
 *   php tools/security/audit_xss_in_contracts.php --json (output machine-readable)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Parse CLI args
$opts = getopt('', ['root::', 'json', 'verbose']);
$rootDir = $opts['root'] ?? (__DIR__ . '/../../storage/objects');
$jsonOut = isset($opts['json']);
$verbose = isset($opts['verbose']);

if (!is_dir($rootDir)) {
    fwrite(STDERR, "Root dir not found: $rootDir\n");
    exit(1);
}

// Pattern XSS noti (case-insensitive)
$patterns = [
    'script_tag'       => '#<script\b#i',
    'javascript_uri'   => '#javascript:#i',
    'vbscript_uri'     => '#vbscript:#i',
    'onload_handler'   => '#\bonload\s*=#i',
    'onclick_handler'  => '#\bonclick\s*=#i',
    'onerror_handler'  => '#\bonerror\s*=#i',
    'onmouseover'      => '#\bonmouseover\s*=#i',
    'iframe_tag'       => '#<iframe\b#i',
    'object_tag'       => '#<object\b#i',
    'embed_tag'        => '#<embed\b#i',
    'data_uri_html'    => '#data:text/html#i',
    'meta_refresh'     => '#<meta[^>]*http-equiv\s*=\s*["\']?refresh#i',
];

$findings = [];
$scanned  = 0;

$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rootDir));
foreach ($rii as $file) {
    if (!$file->isFile()) continue;
    $path = $file->getPathname();
    if (!str_ends_with($path, '.contract.json')) continue;
    $scanned++;
    $content = @file_get_contents($path);
    if ($content === false) continue;

    foreach ($patterns as $name => $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            $findings[] = [
                'file'     => str_replace($rootDir . DIRECTORY_SEPARATOR, '', $path),
                'pattern'  => $name,
                'matches'  => count($matches[0]),
                'samples'  => array_slice($matches[0], 0, 3),
            ];
        }
    }
}

// Output
if ($jsonOut) {
    echo json_encode([
        'scanned' => $scanned,
        'findings' => $findings,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit($findings ? 1 : 0);
}

echo "=== G24 XSS audit — contracts scan ===\n";
echo "Root: $rootDir\n";
echo "Scanned: $scanned files\n";
echo "Findings: " . count($findings) . "\n\n";

if (!$findings) {
    echo "✓ No suspicious patterns detected.\n";
    exit(0);
}

// Group by file
$byFile = [];
foreach ($findings as $f) {
    $byFile[$f['file']][] = $f;
}
foreach ($byFile as $file => $items) {
    echo "🚨 $file\n";
    foreach ($items as $item) {
        echo "   {$item['pattern']}: {$item['matches']} match(es)\n";
        if ($verbose) {
            foreach ($item['samples'] as $sample) {
                echo "      → " . substr($sample, 0, 80) . "\n";
            }
        }
    }
    echo "\n";
}

echo "Run with --verbose for samples, --json for machine-readable output.\n";
exit(1);
