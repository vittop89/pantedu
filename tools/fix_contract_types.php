<?php
// One-shot: normalizza groups[].type da `type_Collect` → `Collect` in tutti i contract.json.
declare(strict_types=1);

$root = __DIR__ . '/../storage/objects';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$fixed = 0;
$total = 0;
foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if (!str_ends_with($file->getFilename(), '.contract.json')) continue;
    $total++;
    $path = $file->getPathname();
    $raw = file_get_contents($path);
    if ($raw === false) continue;
    $j = json_decode($raw, true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $changed = false;
    foreach ($j['groups'] as &$g) {
        if (isset($g['type']) && preg_match('/^type_(Collect|VF|RM|Mixed|Text)$/', $g['type'], $m)) {
            $g['type'] = $m[1];
            $changed = true;
        }
    }
    unset($g);
    if ($changed) {
        file_put_contents($path, json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        echo "fixed: $path\n";
        $fixed++;
    }
}
echo "\nTotal contracts scanned: $total\nFixed: $fixed\n";
