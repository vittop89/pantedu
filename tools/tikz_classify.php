<?php
declare(strict_types=1);
/**
 * Classifica i blocchi TikZ per tipologia in base a pattern strutturali.
 * Output: tabella riepilogativa categorie + esempi.
 */
$files = array_merge(
    glob(__DIR__ . '/../storage/objects/institutes/106/private/77/eser/*.contract.json'),
    glob(__DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/*.contract.json')
);

$cats = [];
$samples = [];
foreach ($files as $f) {
    $j = json_decode(file_get_contents($f), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    foreach ($j['groups'] as $g) {
        if (!isset($g['items'])) continue;
        foreach ($g['items'] as $it) {
            foreach (['question','options','justification','solution'] as $k) {
                if (!isset($it[$k]) || !is_array($it[$k])) continue;
                foreach ($it[$k] as $b) {
                    if (($b['type']??'') !== 'tikz') continue;
                    $s = $b['script'] ?? '';
                    $cat = classify($s);
                    $cats[$cat] = ($cats[$cat]??0)+1;
                    if (!isset($samples[$cat])) {
                        $samples[$cat] = [
                            'file' => basename($f),
                            'len'  => strlen($s),
                            'snip' => substr($s, 0, 250),
                        ];
                    }
                }
            }
        }
    }
}
arsort($cats);
echo "=== Categorie ===\n";
foreach ($cats as $c=>$v) echo "  $c: $v\n";
echo "\n=== Esempi per categoria ===\n";
foreach ($samples as $c=>$s) {
    echo "\n--- $c (file: {$s['file']} len: {$s['len']}) ---\n";
    echo $s['snip'] . "\n";
}

function classify(string $s): string {
    $low = strtolower($s);
    if (str_contains($low, 'pgfplots') || str_contains($low, '\\addplot')) {
        return 'A1_pgfplots';
    }
    if (preg_match('/\\\\draw\\[.*?->.*?\\]\\s*\\(.*?\\)\\s*--\\s*\\(.*?\\)/u', $s)) {
        // ha frecce su segmenti
    }
    if (preg_match('/parabol|\\^2|x\\^2|y\\s*=.*x.*\\^/', $low)) return 'B_parabola_funzione';
    if (str_contains($low, '\\foreach') && (str_contains($low, '\\node') || str_contains($low, '\\filldraw'))) {
        return 'C_griglia_punti';
    }
    if (preg_match('/\\\\draw\\[.*->\\].*?node\\[.*\\].*\\$x\\$/u', $s)
        || (str_contains($s, '$x$') && str_contains($s, '$y$'))) {
        return 'D_assi_cartesiani';
    }
    if (str_contains($low, 'angle=') || str_contains($low, '\\pic') && str_contains($low, 'angle')) {
        return 'E_angoli';
    }
    if (preg_match('/\\\\draw\\s*\\[.*\\]\\s*\\(.+\\)\\s+circle/u', $s)) return 'F_cerchi';
    if (preg_match('/decorations\\.pathmorphing|decorations\\.markings/', $low)) return 'G_decorations';
    if (str_contains($low, 'patterns') && str_contains($low, '\\fill')) return 'H_patterns';
    if (str_contains($low, 'matrix of') || str_contains($low, 'matrix=')) return 'I_matrix';
    if (str_contains($low, 'tikzpeople') || str_contains($low, 'alice') || str_contains($low, 'bob')) {
        return 'J_tikzpeople';
    }
    if (preg_match('/\\\\node.*\\$.*\\$.*\\\\node.*\\$.*\\$/sU', $s) && !str_contains($low, '\\draw')) {
        return 'K_solo_nodi';
    }
    if (preg_match('/numb?er\\s*line|asse\\s*numerico/i', $s)) return 'L_retta_numerica';
    if (preg_match('/\\\\draw\\[->\\]\\s*\\(0,0\\)/', $s)) return 'D_assi_cartesiani';
    if (preg_match('/triangol|triang/', $low)) return 'M_triangolo';
    return 'Z_altro';
}
