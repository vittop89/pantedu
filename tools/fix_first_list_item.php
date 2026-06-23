<?php
/** Fix: aggiunge \n\n prima del PRIMO item di una lista flattenata.
 *  Pattern: text block che inizia con "1. " o "• " (senza \n\n iniziale)
 *  E preceduto da un altro text/latex block (non da spacer). */
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];
$total = 0; $items = 0; $files = 0;
foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    $blocks = $it[$k];
                    foreach ($blocks as $idx => &$b) {
                        if (($b['type'] ?? '') !== 'text') continue;
                        $c = $b['content'] ?? '';
                        // Pattern: "1. " o "• " come primo char (no \n\n)
                        // E c'è un block precedente (idx > 0)
                        if ($idx === 0) continue;
                        if (preg_match('/^(?:1\. |• )/', $c)) {
                            $b['content'] = "\n\n" . $c;
                            $total++; $itemChanged = true;
                            break;
                        }
                    }
                    unset($b);
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $items++;
                    $fileChanged = true;
                }
            }
        }
        if ($fileChanged) {
            $files++;
            if ($apply) file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
}
echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "first list item separator: $total / items: $items / files: $files\n";
