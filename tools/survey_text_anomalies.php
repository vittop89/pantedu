<?php
/** Survey pattern di formattazione sospetti nei text blocks. Solo report. */
declare(strict_types=1);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$counts = [
    'space_before_punct'    => 0,  // " ," " ." " ;"
    'punct_no_space_after'  => 0,  // ",a" ".a" (lettera dopo punteggiatura senza spazio)
    'multi_space_residual'  => 0,  // "  +" residual
    'leading_space'         => 0,  // text che inizia con space (gia' ok generalmente per inline)
    'trailing_space_only'   => 0,  // text che finisce solo con spazio (no contenuto)
    'orphan_period'         => 0,  // text = solo "."
    'orphan_comma'          => 0,  // text = solo ","
    'open_paren_attached'   => 0,  // "(parola" senza spazio prima di (
    'all_caps_label'        => 0,  // SOLUZIONE SOLUZIONE: residual baked-in
];
$samples = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        $name = basename($path);
        foreach ($j['groups'] ?? [] as $gi => $g) {
            foreach ($g['items'] ?? [] as $ii => $it) {
                foreach (['question','justification','solution'] as $k) {
                    foreach ($it[$k] ?? [] as $bi => $b) {
                        if (($b['type'] ?? '') !== 'text') continue;
                        $c = (string)($b['content'] ?? '');
                        if ($c === '') continue;

                        if (preg_match('/\s+[,.;:!]/u', $c)) {
                            $counts['space_before_punct']++;
                            if (count($samples['space_before_punct'] ?? []) < 3)
                                $samples['space_before_punct'][] = "$name g$gi i$ii $k.b$bi: '$c'";
                        }
                        if (preg_match('/[,.;:][\p{L}]/u', $c) && !preg_match('/\d[,.;:]\d/', $c)) {
                            // Esclusione: numeri tipo "1.234" o "3,14"
                            $counts['punct_no_space_after']++;
                            if (count($samples['punct_no_space_after'] ?? []) < 3)
                                $samples['punct_no_space_after'][] = "$name g$gi i$ii $k.b$bi: '" . substr($c, 0, 60) . "'";
                        }
                        if (preg_match('/  +/', $c)) {
                            $counts['multi_space_residual']++;
                        }
                        if (trim($c, " \n") === '.') $counts['orphan_period']++;
                        if (trim($c, " \n") === ',') $counts['orphan_comma']++;
                        if (preg_match('/SOLUZIONE.*SOLUZIONE|GIUSTIFICAZIONE.*GIUSTIFICAZIONE/i', $c)) {
                            $counts['all_caps_label']++;
                        }
                    }
                }
            }
        }
    }
}

echo "=== ANOMALIE TEXT BLOCKS ===\n";
foreach ($counts as $k => $v) printf("  %-25s %d\n", $k, $v);
echo "\n=== SAMPLES ===\n";
foreach ($samples as $kind => $arr) {
    echo "\n[$kind]\n";
    foreach ($arr as $s) echo "  - " . str_replace("\n", "\\n", $s) . "\n";
}
