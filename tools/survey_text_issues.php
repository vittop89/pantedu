<?php
declare(strict_types=1);

$contracts = [
    'institutes/106/private/77/eser/1.0_MAT-Numeri_naturali_e_interi-SCI1.contract.json',
    'institutes/106/private/77/eser/2.0_MAT-Numeri_razionali-SCI1.contract.json',
    'institutes/106/private/77/eser/3.0_MAT-Monomi_e_Polinomi-SCI1.contract.json',
    'institutes/106/private/77/eser/4.0_MAT-Equazioni_intere_di_primo_grado-SCI1.contract.json',
    'institutes/106/private/77/eser/5.0_MAT-Funzioni-SCI1.contract.json',
    'institutes/106/private/77/eser/6.0_MAT-Scomposizione_di_polinomi-SCI1.contract.json',
    'institutes/106/private/77/eser/7.0_MAT-Equazioni_fratte_e_letterali_di_primo_grado-SCI1.contract.json',
    'institutes/106/private/77/eser/2.0_FIS-Moti_nel_piano-SCI2.contract.json',
    'institutes/106/private/77/eser/2.0_MAT-Sistemi_lineari-SCI2.contract.json',
    'institutes/106/private/77/eser/3.0_MAT-Radicali-SCI2.contract.json',
    'institutes/106/private/77/eser/4.0_MAT-Rette_fasci_di_rette_e_piani-SCI2.contract.json',
    'institutes/106/private/77/eser/5.0_MAT-Equazioni_di_secondo_grado-SCI2.contract.json',
    'institutes/106/private/77/eser/6.0_MAT-Parabola_ed_equazioni_di_grado_superiore_al_secondo-SCI2.contract.json',
    'institutes/106/private/77/eser/7.0_MAT-Disequazioni_di_secondo_grado-SCI2.contract.json',
    'institutes/106/private/77/eser/8.0_MAT-Equazioni_e_disequazioni_irrazionali_e_con_valore_assoluto-SCI2.contract.json',
];
$base = __DIR__ . '/../storage/objects/';
$totals = [
    'titles_with_giustifica_soluzioni' => 0,
    'titles_with_multispace' => 0,
    'blocks_multispace' => 0,
    'blocks_triple_newlines' => 0,
    'blocks_leading_ws' => 0,
    'blocks_trailing_ws' => 0,
    'blocks_tabs' => 0,
    'blocks_crlf' => 0,
    'blocks_total' => 0,
];
$samples = [];

foreach ($contracts as $rel) {
    $path = $base . $rel;
    if (!is_file($path)) { echo "MISSING: $rel\n"; continue; }
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($rel);

    foreach ($j['groups'] as $gi => $g) {
        $title = (string)($g['title'] ?? '');
        if (preg_match('/Giustifica\s+Soluzioni/u', $title)) {
            $totals['titles_with_giustifica_soluzioni']++;
            if (count($samples) < 5) $samples['title'][] = "$name g$gi: '$title'";
        }
        if (preg_match('/  +/', $title)) $totals['titles_with_multispace']++;

        foreach (($g['items'] ?? []) as $ii => $it) {
            foreach (['question', 'justification', 'solution'] as $k) {
                foreach (($it[$k] ?? []) as $bi => $blk) {
                    $totals['blocks_total']++;
                    $c = (string)($blk['content'] ?? '');
                    $type = $blk['type'] ?? '?';
                    // Skip TikZ: hanno proprio formato. Latex incluso.
                    if ($type === 'tikz') continue;
                    if (preg_match('/  +/', $c)) {
                        $totals['blocks_multispace']++;
                        if (count($samples['multispace'] ?? []) < 3) {
                            $samples['multispace'][] = "$name g$gi i$ii $k b$bi: '" . substr($c, 0, 100) . "...'";
                        }
                    }
                    if (preg_match('/\n\n\n+/', $c)) {
                        $totals['blocks_triple_newlines']++;
                        if (count($samples['triple_nl'] ?? []) < 3) {
                            $samples['triple_nl'][] = "$name g$gi i$ii $k b$bi: " . substr($c, 0, 100);
                        }
                    }
                    if (preg_match('/^[\s\n]+/', $c) && $c !== '') {
                        $totals['blocks_leading_ws']++;
                        if (count($samples['leading'] ?? []) < 3) {
                            $vis = str_replace(["\r","\n","\t"], ['\r','\n','\t'], substr($c, 0, 80));
                            $samples['leading'][] = "$name g$gi i$ii $k b$bi: '$vis'";
                        }
                    }
                    if (preg_match('/[\s\n]+$/', $c) && $c !== '') {
                        $totals['blocks_trailing_ws']++;
                        if (count($samples['trailing'] ?? []) < 3) {
                            $vis = str_replace(["\r","\n","\t"], ['\r','\n','\t'], substr($c, max(0, strlen($c)-80)));
                            $samples['trailing'][] = "$name g$gi i$ii $k b$bi: ...'$vis'";
                        }
                    }
                    if (strpos($c, "\t") !== false) {
                        $totals['blocks_tabs']++;
                    }
                    if (strpos($c, "\r") !== false) {
                        $totals['blocks_crlf']++;
                    }
                }
            }
        }
    }
}

echo "=== TOTALI (15 contract) ===\n";
foreach ($totals as $k => $v) {
    printf("%-40s %d\n", $k, $v);
}
echo "\n=== SAMPLES ===\n";
foreach ($samples as $kind => $arr) {
    echo "\n[$kind]\n";
    foreach ($arr as $s) echo "  - $s\n";
}
