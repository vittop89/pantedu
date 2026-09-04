<?php
/**
 * R-A conservative: insert blocco text "\n\n" (paragraph break) tra latex
 * consecutivi nel key `solution` quando ci sono >=4 latex consecutivi.
 *
 * Scope: cartella verifiche/ + esercizi/. Solo solution[]. Soglia 4 evita
 * di rompere casi inline tipo "\(a\) \(b\) \(c\)" (lista variabili).
 *
 * Idempotente: se gia' c'e' un text block tra due latex, non duplica.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
];

$totalInsert = 0;
$itemsTouched = 0;
$filesTouched = [];
$samples = [];
$THRESHOLD = 4;

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $name = basename($path);
        $fileChanged = false;

        foreach ($j['groups'] as $gi => &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as $ii => &$it) {
                if (!isset($it['solution']) || !is_array($it['solution'])) continue;
                $blocks = $it['solution'];
                $n = count($blocks);
                if ($n < $THRESHOLD) continue;

                // Conta max consecutivi latex
                $maxConsec = 0; $cur = 0;
                foreach ($blocks as $b) {
                    if (in_array($b['type'] ?? '', ['latex','tikz'], true)) { $cur++; if ($cur>$maxConsec) $maxConsec=$cur; }
                    else $cur = 0;
                }
                if ($maxConsec < $THRESHOLD) continue;

                // Inserisce text "\n\n" tra consecutivi latex/tikz
                $newBlocks = [];
                $prevType = null;
                $insertedHere = 0;
                foreach ($blocks as $b) {
                    $t = $b['type'] ?? '';
                    if ($prevType === 'latex' && in_array($t, ['latex','tikz'], true)) {
                        $newBlocks[] = ['type' => 'text', 'content' => "\n\n"];
                        $insertedHere++;
                    } elseif ($prevType === 'tikz' && in_array($t, ['latex','tikz'], true)) {
                        $newBlocks[] = ['type' => 'text', 'content' => "\n\n"];
                        $insertedHere++;
                    }
                    $newBlocks[] = $b;
                    $prevType = $t;
                }
                if ($insertedHere > 0) {
                    $totalInsert += $insertedHere;
                    $itemsTouched++;
                    if (count($samples) < 8) $samples[] = "$name g$gi i$ii: $maxConsec consec → +$insertedHere separators";
                    $it['solution'] = $newBlocks;
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $fileChanged = true;
                }
            }
        }

        if ($fileChanged) {
            $filesTouched[] = $name;
            if ($apply) {
                file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "soglia: $THRESHOLD consecutivi minimo\n";
echo "separators inseriti: $totalInsert\n";
echo "items: $itemsTouched\n";
echo "files: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nsamples:\n";
foreach ($samples as $s) echo "  $s\n";
