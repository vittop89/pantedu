<?php
declare(strict_types=1);
$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/eser';
$files = glob($dir . '/*.contract.json');
foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    foreach (($j['groups'] ?? []) as $gi => $g) {
        foreach (($g['items'] ?? []) as $ii => $it) {
            foreach (($it['question'] ?? []) as $bi => $blk) {
                $c = (string)($blk['content'] ?? '');
                if (str_contains($c, 'overset') && str_contains($c, 'bbox')) {
                    echo "=== " . basename($path) . " g$gi i$ii b$bi ===\n";
                    echo $c . "\n\n";
                    if (--$argv[1] === '-1') exit;
                }
            }
        }
    }
}
