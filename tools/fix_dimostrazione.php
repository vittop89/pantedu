<?php
/** Fix multi-proof items: aggiunge \n\n dopo "Dimostrazione:" e prima di latex
 *  contenenti `\xrightarrow` (nuova affermazione di proposizione). */
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
                foreach (['solution','justification'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    $blocks = $it[$k];
                    $newBlocks = [];
                    foreach ($blocks as $idx => $b) {
                        $t = $b['type'] ?? '';
                        $c = $b['content'] ?? '';

                        // Rule 1: text contiene "Dimostrazione:" → ensure ends \n\n
                        if ($t === 'text' && preg_match('/(Dimostrazione)\s*:/u', $c)) {
                            $newC = preg_replace('/(Dimostrazione\s*:)\s*$/u', "$1\n\n", $c);
                            // Caso più generico: "Dim..." in mezzo al testo, append \n\n se non già presente
                            if ($newC === $c && !preg_match('/\n\n\s*$/u', $c)) {
                                $newC = rtrim($c, " \t\n") . "\n\n";
                            }
                            if ($newC !== $c) {
                                $b['content'] = $newC;
                                $total++; $itemChanged = true;
                            }
                        }

                        // Rule 2: latex block contiene \xrightarrow → se preceduto da
                        // un altro latex (nuova proposizione), inserisci separator
                        if ($t === 'latex' && str_contains($c, '\\xrightarrow') && $idx > 0) {
                            $prev = $blocks[$idx - 1] ?? null;
                            if ($prev && ($prev['type'] ?? '') === 'latex') {
                                $newBlocks[] = ['type' => 'text', 'content' => "\n\n"];
                                $total++; $itemChanged = true;
                            }
                        }

                        $newBlocks[] = $b;
                    }
                    if ($itemChanged) $it[$k] = $newBlocks;
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
echo "Dimostrazione/xrightarrow separators: $total / items: $items / files: $files\n";
