<?php
declare(strict_types=1);
$path = __DIR__ . '/../storage/objects/' . ($argv[1] ?? '');
$gIdx = (int)($argv[2] ?? 0);
$iIdx = (int)($argv[3] ?? 0);
$key  = (string)($argv[4] ?? 'question');
$bIdx = isset($argv[5]) ? (int)$argv[5] : -1;
$j = json_decode(file_get_contents($path), true);
$blocks = $j['groups'][$gIdx]['items'][$iIdx][$key] ?? [];
if ($bIdx >= 0) {
    echo "=== block[$bIdx] type=" . ($blocks[$bIdx]['type'] ?? '?') . " ===\n";
    echo $blocks[$bIdx]['content'] ?? '';
    echo "\n=== END ===\n";
} else {
    foreach ($blocks as $bi => $blk) {
        echo "=== block[$bi] type=" . ($blk['type'] ?? '?') . " ===\n";
        echo $blk['content'] ?? '';
        echo "\n";
    }
}
