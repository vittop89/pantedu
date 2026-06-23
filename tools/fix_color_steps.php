<?php
/**
 * Insert text "\n" between consecutive latex blocks in an item's solution.
 * Used per-item dove sono step di calcolo che vanno mostrati uno per riga.
 */
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
$path = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json';
$j = json_decode(file_get_contents($path), true);

// Targets: g=5 (Espressioni numeriche), i=0 e i=1.
$targets = [
    ['g' => 5, 'i' => 0, 'k' => 'solution'],
    ['g' => 5, 'i' => 1, 'k' => 'solution'],
];

foreach ($targets as $t) {
    $blocks = &$j['groups'][$t['g']]['items'][$t['i']][$t['k']];
    if (!is_array($blocks) || count($blocks) < 2) continue;
    $newBlocks = [];
    $prevType = null;
    foreach ($blocks as $b) {
        // Inserisci separator text "\n" prima di un latex se il precedente era latex.
        if ($prevType === 'latex' && ($b['type'] ?? '') === 'latex') {
            $newBlocks[] = ['type' => 'text', 'content' => "\n"];
        }
        $newBlocks[] = $b;
        $prevType = $b['type'] ?? null;
    }
    echo "g={$t['g']} i={$t['i']} {$t['k']}: " . count($blocks) . " → " . count($newBlocks) . " blocks\n";
    $blocks = $newBlocks;
    unset($blocks);
    // Invalida body_html cache
    if (isset($j['groups'][$t['g']]['items'][$t['i']]['body_html'])) {
        unset($j['groups'][$t['g']]['items'][$t['i']]['body_html']);
    }
}

if ($apply) {
    file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "APPLIED\n";
} else {
    echo "DRY-RUN\n";
}
