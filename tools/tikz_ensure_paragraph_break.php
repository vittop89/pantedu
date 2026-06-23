<?php
declare(strict_types=1);
/**
 * tikz_ensure_paragraph_break.php — assicura che ogni blocco TikZ sia
 * preceduto da un paragraph break (\n\n) nel blocco prose precedente,
 * cosi' il renderer HTML lo mostra come blocco separato e non inline al
 * testo che fluisce attorno.
 *
 * Per ogni `question` e `solution`:
 *   - Per ogni indice i dove il blocco e' tikz E il precedente e' text/latex
 *   - Se il precedente NON termina con \n\n (o whitespace+\n\n)
 *     → appende \n\n al suo content
 *
 * Idempotente. Conservativo: non tocca tikz preceduti da blocchi non-prose
 * (es. tikz seguito da tikz, o tikz dopo geogebra).
 *
 * Usage: php tools/tikz_ensure_paragraph_break.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalScanned = 0;
$breaksAdded  = 0;
$itemsTouched = 0;
$filesChanged = 0;

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['question', 'solution', 'options', 'justification'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    $blocks = $it[$k];
                    $n = count($blocks);
                    for ($i = 1; $i < $n; $i++) {
                        $totalScanned++;
                        if (($blocks[$i]['type'] ?? '') !== 'tikz') continue;
                        $prevType = $blocks[$i-1]['type'] ?? '';
                        if ($prevType !== 'text' && $prevType !== 'latex') continue;
                        $prevContent = (string)($blocks[$i-1]['content'] ?? '');
                        // Already ends with paragraph break?
                        if (preg_match('/\n\s*\n\s*$/u', $prevContent)) continue;
                        // Append \n\n
                        $blocks[$i-1]['content'] = rtrim($prevContent) . "\n\n";
                        $breaksAdded++;
                        $itemChanged = true;
                    }
                    if ($itemChanged) $it[$k] = $blocks;
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $itemsTouched++;
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
echo "Tikz blocks scanned (post-prose): $totalScanned\n";
echo "Paragraph breaks added:           $breaksAdded\n";
echo "Items touched:                    $itemsTouched\n";
echo "Files changed:                    $filesChanged\n";
