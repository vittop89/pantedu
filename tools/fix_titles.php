<?php
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/eser';
$files = glob($dir . '/*.contract.json');
$changes = 0;
$samples = [];
foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($path);
    $fileChanged = false;
    foreach ($j['groups'] as $gi => &$g) {
        if (!isset($g['title'])) continue;
        $old = (string)$g['title'];
        // Rimuove suffisso "Giustifica    Soluzioni" (con spazi multipli o singoli)
        $new = preg_replace('/\s+Giustifica\s+Soluzioni\s*$/u', '', $old) ?? $old;
        // Collassa spazi multipli interni
        $new = preg_replace('/  +/', ' ', $new) ?? $new;
        $new = trim($new);
        if ($new !== $old) {
            $g['title'] = $new;
            $changes++;
            $samples[] = "$name g$gi: '$old' → '$new'";
            $fileChanged = true;
        }
    }
    if ($fileChanged && $apply) {
        file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "totale changes: $changes\n";
echo "\nsamples:\n";
foreach ($samples as $s) echo "  $s\n";
