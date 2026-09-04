<?php
/**
 * cleanup_dsa_inline_text.php
 *
 * Rimuove `(*F*) ` e `(*GF*) ` inline residui dai contract JSON.
 * Erano stati inseriti dal vecchio handler `applyMarkerVisual` quando le
 * checkbox F/GF venivano cliccate. Ora i pulsanti F/GF non mutano più il
 * testo (la TeX export pipeline leggerà lo stato dataset/sessionStorage),
 * quindi questi marker inline sono duplicati/ridondanti.
 *
 * Pattern matching:
 *   - `(*F*) ` o `(*F*)` (con/senza spazio dopo)
 *   - `(*GF*) ` o `(*GF*)`
 * Solo all'INIZIO di un text block o dopo un newline (per sicurezza).
 *
 * Idempotente: re-run = 0 changes.
 *
 * Usage: php tools/cleanup_dsa_inline_text.php [--apply]
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalBlocks = 0;
$blocksChanged = 0;
$itemsTouched = 0;
$filesChanged = 0;
$samples = [];

// Pattern: (*F*) o (*GF*) ovunque, con eventuale spazio dopo. La sequenza
// non ha senso come matematica/testo legittimo → safe rimuovere ovunque.
$pattern = '/\(\*G?F\*\)\s?/u';

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            // Group-level fields: intro/title (string)
            foreach (['intro', 'title'] as $gField) {
                if (!isset($g[$gField]) || !is_string($g[$gField])) continue;
                $orig = $g[$gField];
                if (strpos($orig, '(*F*)') === false && strpos($orig, '(*GF*)') === false) continue;
                $new = preg_replace($pattern, '', $orig) ?? $orig;
                if ($new !== $orig) {
                    $g[$gField] = $new;
                    $blocksChanged++;
                    $fileChanged = true;
                }
            }
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    cleanBlocksRecursive($it[$k], $pattern, $totalBlocks, $blocksChanged, $itemChanged, $samples, basename($path));
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

/** Walk ricorsivo: per ogni blocco text/latex pulisce content; per blocchi
 *  list (con items annidati) ricorre su ogni item. */
function cleanBlocksRecursive(array &$blocks, string $pattern, int &$totalBlocks, int &$blocksChanged, bool &$itemChanged, array &$samples, string $fileName): void
{
    foreach ($blocks as &$b) {
        if (!is_array($b)) continue;
        $type = $b['type'] ?? '';
        if ($type === 'text' || $type === 'latex') {
            $totalBlocks++;
            $orig = (string)($b['content'] ?? '');
            if (strpos($orig, '(*F*)') === false && strpos($orig, '(*GF*)') === false) continue;
            $new = preg_replace($pattern, '', $orig) ?? $orig;
            if ($new !== $orig) {
                $blocksChanged++;
                $itemChanged = true;
                if (count($samples) < 5) {
                    $samples[] = [
                        'file' => $fileName,
                        'before' => substr($orig, 0, 100),
                        'after'  => substr($new, 0, 100),
                    ];
                }
                $b['content'] = $new;
            }
        } elseif ($type === 'list' && isset($b['items']) && is_array($b['items'])) {
            // list items: array di array di blocchi
            foreach ($b['items'] as &$item) {
                if (is_array($item)) {
                    cleanBlocksRecursive($item, $pattern, $totalBlocks, $blocksChanged, $itemChanged, $samples, $fileName);
                }
            }
            unset($item);
        }
    }
    unset($b);
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Blocks scanned: $totalBlocks\n";
echo "Blocks cleaned: $blocksChanged\n";
echo "Items touched:  $itemsTouched\n";
echo "Files changed:  $filesChanged\n";
if ($samples) {
    echo "\n--- Sample changes ---\n";
    foreach ($samples as $s) {
        echo "FILE: " . $s['file'] . "\n";
        echo "  BEFORE: " . $s['before'] . "\n";
        echo "  AFTER:  " . $s['after'] . "\n\n";
    }
}
