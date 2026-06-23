<?php
/**
 * V2: euristiche più intelligenti per casi comuni nelle verifiche/esercizi.
 *
 * R-A2: lower threshold to ≥3 latex consecutivi in solution[] → \n\n separators
 *       (era 4, ma molti calcoli step hanno 3 step)
 *
 * R-C: text che inizia con " per " e contiene "per a=" patterns multipli
 *       (valutazioni multiple) → split in righe con \n\n
 *
 * R-D: text che precede latex con \begin{array} o \begin{cases} o \begin{align}
 *       → aggiungi \n\n prima del latex se text non termina già con \n\n
 *
 * R-E: text che segue latex con \end{array} ecc → aggiungi \n\n prima del text
 *      se text non inizia già con \n\n
 *
 * Solo solution+justification, NON question (rischio rovinare flow narrativo).
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totA = 0; $totD = 0; $totE = 0;
$samples = [];
$itemsTouched = 0;
$filesTouched = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $name = basename($path);
        $fileChanged = false;

        foreach ($j['groups'] as $gi => &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as $ii => &$it) {
                $itemChanged = false;
                foreach (['justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    $blocks = $it[$k];
                    $n = count($blocks);
                    if ($n < 3) continue;

                    // R-A2: ≥3 latex consecutivi → insert \n\n separator
                    $maxConsec = 0; $cur = 0;
                    foreach ($blocks as $b) {
                        if (in_array($b['type'] ?? '', ['latex','tikz'], true)) { $cur++; if ($cur>$maxConsec) $maxConsec=$cur; }
                        else $cur = 0;
                    }

                    $newBlocks = [];
                    $prevType = null;
                    $prevContent = '';
                    $changesHere = 0;
                    foreach ($blocks as $b) {
                        $t = $b['type'] ?? '';
                        $c = (string)($b['content'] ?? '');

                        // R-A2: separator latex+latex consecutivi (soglia 3)
                        if ($prevType === 'latex' && $t === 'latex' && $maxConsec >= 3) {
                            // Skip se gia' separator presente nel block precedente
                            $newBlocks[] = ['type' => 'text', 'content' => "\n\n"];
                            $totA++; $changesHere++;
                        }

                        // R-D: text seguito da latex \begin{array|cases|align} → ensure \n\n at end of text
                        if ($prevType === 'text' && $t === 'latex' && preg_match('/\\\\begin\{(?:array|cases|align)/', $c)) {
                            // Modifica il prevContent (già nel newBlocks ultima entry)
                            $lastIdx = count($newBlocks) - 1;
                            if ($lastIdx >= 0 && ($newBlocks[$lastIdx]['type'] ?? '') === 'text') {
                                $pc = $newBlocks[$lastIdx]['content'];
                                if (!preg_match('/\n\n\s*$/u', $pc) && trim($pc) !== '') {
                                    $newBlocks[$lastIdx]['content'] = rtrim($pc, " \t\n") . "\n\n";
                                    $totD++; $changesHere++;
                                }
                            }
                        }

                        // R-E: text dopo latex con \end{array|cases|align} → \n\n al testo
                        if ($prevType === 'latex' && $t === 'text' && preg_match('/\\\\end\{(?:array|cases|align)/', $prevContent)) {
                            if (!preg_match('/^\s*\n\n/u', $c) && trim($c) !== '') {
                                $c = "\n\n" . ltrim($c);
                                $b['content'] = $c;
                                $totE++; $changesHere++;
                            }
                        }

                        $newBlocks[] = $b;
                        $prevType = $t;
                        $prevContent = $c;
                    }
                    if ($changesHere > 0) {
                        $it[$k] = $newBlocks;
                        $itemChanged = true;
                        if (count($samples) < 12) {
                            $samples[] = "$name g$gi i$ii $k: +$changesHere changes (maxConsec=$maxConsec)";
                        }
                    }
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $itemsTouched++;
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
echo "R-A2 (latex+latex separator, soglia 3): $totA\n";
echo "R-D  (text→matrix-latex, \\n\\n prepend matrix): $totD\n";
echo "R-E  (matrix-latex→text, \\n\\n prepend text): $totE\n";
echo "items: $itemsTouched\n";
echo "files: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nSamples:\n";
foreach ($samples as $s) echo "  $s\n";
