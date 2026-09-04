<?php
/** Dump struttura blocchi di item specifici per review. */
declare(strict_types=1);
$specs = [
    // [path_relative, group_idx, item_idx]
    ['verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json', 2, 0],   // Sia a∈Z (RM)
    ['verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json', 5, 0],   // Espressioni numeriche calcolo step
    ['verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json', 5, 1],   // Espressioni numeriche calcolo step (2)
    ['verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json', 7, 2],   // Agricoltore
];

$base = __DIR__ . '/../storage/objects/institutes/106/private/77/';
foreach ($specs as $i => $sp) {
    [$rel, $gi, $ii] = $sp;
    $j = json_decode(file_get_contents($base . $rel), true);
    $g = $j['groups'][$gi];
    $it = $g['items'][$ii];
    echo "═══════════ ITEM " . ($i+1) . " ═══════════\n";
    echo "FILE: $rel\n";
    echo "GROUP $gi: \"" . ($g['title'] ?? '?') . "\" type=" . ($g['type'] ?? '?') . "\n";
    echo "ITEM $ii: ex_num=" . ($it['ex_num'] ?? '-') . " page=" . ($it['page'] ?? '-') . " diff=" . ($it['difficulty'] ?? '-') . " badge=" . json_encode($it['badge'] ?? null) . "\n";
    foreach (['question','options','justification','solution'] as $k) {
        if (empty($it[$k])) continue;
        echo "[$k]:\n";
        foreach ($it[$k] as $bi => $b) {
            if (is_array($b) && isset($b['type'])) {
                $c = (string)($b['content'] ?? '');
                $vis = str_replace(["\n","\r","\t"], ['↵','\r','→'], substr($c, 0, 200));
                echo "  $k.b$bi (" . $b['type'] . "): [$vis]\n";
            } elseif (is_array($b)) {
                // option (RM/VF)
                $cont = $b['content'] ?? [];
                if (is_array($cont)) {
                    foreach ($cont as $cbi => $cb) {
                        $cc = is_array($cb) ? ($cb['content'] ?? '') : (string)$cb;
                        echo "  opt$bi (letter=" . ($b['letter'] ?? '?') . " correct=" . var_export($b['correct'] ?? null, true) . ").b$cbi (" . ($cb['type'] ?? '?') . "): [" . str_replace("\n","↵",substr((string)$cc,0,150)) . "]\n";
                    }
                }
            }
        }
    }
    echo "\n";
}
