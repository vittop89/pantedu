<?php
$j = json_decode(file_get_contents(__DIR__ . '/../storage/objects/institutes/106/private/77/eser/2.0_MAT-Sistemi_lineari-SCI2.contract.json'), true);
$it = $j['groups'][0]['items'][0];
foreach (['question','options','solution','justification'] as $k) {
    foreach ($it[$k] ?? [] as $bi => $b) {
        if (($b['type'] ?? '') !== 'tikz') continue;
        $s = $b['script'] ?? '';
        echo "=== $k[$bi] (len " . strlen($s) . ") ===\n";
        echo '  has \def\textu: ' . (str_contains($s, '\def\textu') ? 'Y' : 'N') . "\n";
        echo '  uses \disegnaScena: ' . (str_contains($s, '\disegnaScena') ? 'Y' : 'N') . "\n";
        echo '  defines \disegnaScena: ' . (str_contains($s, 'newcommand{\disegnaScena}') ? 'Y' : 'N') . "\n";
        // Show first 600 chars
        echo "  ---begin script---\n  " . substr($s, 0, 800) . "\n  ---end script---\n";
    }
}
