<?php
/**
 * Audit scanner for legacy .php/.html pages that live under the
 * content roots (eser/, verifiche/, mappe/, lab/, didattica/).
 *
 * Classifies each page by header shape so the SPA router knows
 * whether it can safely inject the body as a partial.
 *
 * Usage:
 *   php tools/audit_legacy_pages.php
 *   php tools/audit_legacy_pages.php eser/sc > /tmp/audit.txt
 */

$targets = array_slice($argv, 1);
if (!$targets) {
    $targets = ['eser', 'verifiche', 'mappe', 'lab', 'didattica', 'risdoc'];
}

$base = dirname(__DIR__);
$counts = ['total' => 0, 'doctype_html' => 0, 'body_open' => 0,
           'auth_include' => 0, 'mathjax' => 0, 'quilljs' => 0];
$samples = [];

foreach ($targets as $rel) {
    $abs = $base . DIRECTORY_SEPARATOR . $rel;
    if (!is_dir($abs)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $abs, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (!in_array($ext, ['php', 'html'], true)) continue;

        $counts['total']++;
        $head = (string)@file_get_contents($file->getPathname(), false, null, 0, 4096);
        if (stripos($head, '<!doctype html') !== false) $counts['doctype_html']++;
        if (stripos($head, '<body')           !== false) $counts['body_open']++;
        if (stripos($head, 'AuthCode.php')    !== false) $counts['auth_include']++;
        if (stripos($head, 'MathJax')         !== false) $counts['mathjax']++;
        if (stripos($head, 'quilljs')         !== false) $counts['quilljs']++;

        if (count($samples) < 5) $samples[] = str_replace($base, '', $file->getPathname());
    }
}

$pct = fn(int $n) => $counts['total'] ? round(100 * $n / $counts['total'], 1) . '%' : '-';

echo "Legacy content pages audit\n";
echo str_repeat('=', 40), "\n";
echo sprintf("Total files scanned          : %d\n",          $counts['total']);
echo sprintf("  emit <!doctype html>       : %d (%s)\n", $counts['doctype_html'],   $pct($counts['doctype_html']));
echo sprintf("  open <body>                : %d (%s)\n", $counts['body_open'],      $pct($counts['body_open']));
echo sprintf("  include AuthCode.php       : %d (%s)\n", $counts['auth_include'],   $pct($counts['auth_include']));
echo sprintf("  embed MathJax              : %d (%s)\n", $counts['mathjax'],        $pct($counts['mathjax']));
echo sprintf("  embed QuillJS              : %d (%s)\n", $counts['quilljs'],        $pct($counts['quilljs']));
echo "\nSample paths:\n";
foreach ($samples as $s) echo "  $s\n";

echo "\nTargets scanned: " . implode(', ', $targets) . "\n";
