<?php
declare(strict_types=1);
/**
 * tikz_reorder_first_to_last.php — sposta i blocchi TikZ dal primo all'ultimo
 * posto in `question` e `solution` quando la figura precede il testo del
 * problema. Convenzione editoriale: prima la traccia testuale, poi la figura.
 *
 * Trigger: il primo blocco e' tikz E ci sono blocchi text/latex dopo.
 *   Sposta il tikz in coda (mantenendo l'ordine relativo degli altri).
 *
 * Idempotente: re-run non sposta nulla se il primo blocco non e' piu' tikz.
 *
 * Usage: php tools/tikz_reorder_first_to_last.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalItems = 0;
$movedQ = 0; $movedS = 0;
$filesChanged = 0;

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $totalItems++;
                $itemChanged = false;
                // SOLO question: nelle solution la figura sta vicino alle formule
                // che la referenziano (annotazioni, punti aggiunti, ecc) -> intenzionale.
                foreach (['question'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k]) || count($it[$k]) < 2) continue;
                    $blocks = $it[$k];
                    if (($blocks[0]['type'] ?? '') !== 'tikz') continue;
                    // Verifica che ci sia almeno un blocco prose dopo
                    $hasProseAfter = false;
                    for ($i = 1; $i < count($blocks); $i++) {
                        $t = $blocks[$i]['type'] ?? '';
                        if ($t === 'text' || $t === 'latex') { $hasProseAfter = true; break; }
                    }
                    if (!$hasProseAfter) continue;

                    // Sposta tikz in coda (mantieni ordine relativo del resto)
                    $tikzBlock = array_shift($blocks);
                    $blocks[] = $tikzBlock;
                    $it[$k] = $blocks;
                    $itemChanged = true;
                    if ($k === 'question') $movedQ++; else $movedS++;
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $fileChanged = true;
                }
            }
            unset($it);
        }
        unset($g);
        if ($fileChanged) {
            $filesChanged++;
            if ($apply) {
                file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Items scanned:   $totalItems\n";
echo "Question reorder: $movedQ\n";
echo "Solution reorder: $movedS\n";
echo "Files changed:   $filesChanged\n";
