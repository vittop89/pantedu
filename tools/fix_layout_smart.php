<?php
/**
 * Fixer 2 regole apprese da review utente:
 *   R-A) Step matematici consecutivi: ≥3 blocchi latex/tikz consecutivi senza
 *        text in mezzo → inserisci blocco text "\n" tra ognuno (split su righe).
 *   R-B) Opzioni inline: pattern `\n+\s*\n+` (multi-newlines) in text block → ` ` (singolo spazio).
 *        NB: applico SOLO quando il pattern e' ovviamente un break orfano
 *        (es. tra "(vero)" e "b.", "(falso)" e "c.", ecc.).
 *        Per non rompere casi legittimi tipo "Verifica 1\n\nVerifica 2",
 *        applico R-B SOLO quando preceduto da `(vero)`/`(falso)`/punteggiatura
 *        chiusura E seguito da label opzione `[a-z]\.` o letterale italiano.
 *
 * Scope: cartella verifiche/ + esercizi/.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
];

$totalA = 0; $totalB = 0;
$samplesA = []; $samplesB = [];
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
                foreach (['question','justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;

                    // R-B: rimuovi multi-newlines in text blocks (caso opzioni inline)
                    foreach ($it[$k] as $bi => &$b) {
                        if (($b['type'] ?? '') !== 'text') continue;
                        $c = (string)($b['content'] ?? '');
                        // Pattern: terminator (vero|falso|frase) + multi-newline + label opzione (a-z\.)
                        $newC = preg_replace_callback(
                            '/(\([vV]ero\)|\([fF]also\)|\.|;|\)|:)\s*\n+\s*\n+\s*([a-z]\.|\)|[A-Z][a-z])/u',
                            function ($m) use (&$totalB, &$samplesB, $name, $gi, $ii, $k, $bi) {
                                $totalB++;
                                if (count($samplesB) < 8) $samplesB[] = "$name g$gi i$ii $k.b$bi: '" . substr($m[0], 0, 40) . "...'";
                                return $m[1] . ' ' . $m[2];
                            },
                            $c
                        );
                        if ($newC !== null && $newC !== $c) {
                            $b['content'] = $newC;
                            $itemChanged = true;
                        }
                    }
                    unset($b);

                    // R-A DISABLED: troppo aggressivo, rompe latex inline legittimi.
                    // Applicabile solo per-item con review utente.
                    if (false) {
                    $blocks = $it[$k];
                    $newBlocks = [];
                    $consecutiveLatex = 0;
                    $consecutiveStart = -1;
                    foreach ($blocks as $idx => $b) {
                        $t = $b['type'] ?? '';
                        if (in_array($t, ['latex', 'tikz'], true)) {
                            if ($consecutiveLatex === 0) $consecutiveStart = $idx;
                            $consecutiveLatex++;
                        } else {
                            $consecutiveLatex = 0;
                        }
                    }
                    // Re-scan e inserisci separatori se conta ≥3
                    if (count($blocks) >= 3) {
                        $maxConsec = 0;
                        $cur = 0;
                        foreach ($blocks as $b) {
                            if (in_array($b['type'] ?? '', ['latex','tikz'], true)) {
                                $cur++; if ($cur > $maxConsec) $maxConsec = $cur;
                            } else $cur = 0;
                        }
                        if ($maxConsec >= 3) {
                            $insertedHere = 0;
                            $prevType = null;
                            foreach ($blocks as $b) {
                                $tt = $b['type'] ?? '';
                                if ($prevType === 'latex' && in_array($tt, ['latex','tikz'], true)) {
                                    // Verifica che non ci sia gia' un separator
                                    $newBlocks[] = ['type' => 'text', 'content' => "\n"];
                                    $insertedHere++;
                                }
                                $newBlocks[] = $b;
                                $prevType = $tt;
                            }
                            if ($insertedHere > 0) {
                                $totalA += $insertedHere;
                                if (count($samplesA) < 6) $samplesA[] = "$name g$gi i$ii $k: $maxConsec consec → +$insertedHere separators";
                                $it[$k] = $newBlocks;
                                $itemChanged = true;
                            }
                        }
                    }
                    } // end if(false)
                }
                if ($itemChanged) {
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
echo "R-A inserted (\\n separators between latex steps): $totalA\n";
echo "R-B removed (multi-newlines orfan): $totalB\n";
echo "files touched: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nSamples R-A:\n";
foreach ($samplesA as $s) echo "  $s\n";
echo "\nSamples R-B:\n";
foreach ($samplesB as $s) echo "  $s\n";
