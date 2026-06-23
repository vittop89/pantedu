<?php
/** Aggiunge \n\n dopo blocchi latex contenenti DATI/INCOGNITE table. */
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
                    $prevWasDati = false;
                    foreach ($blocks as $idx => $b) {
                        $isDatiLatex = false;
                        if (($b['type'] ?? '') === 'latex') {
                            $c = $b['content'] ?? '';
                            // Detect DATI/INCOGNITE table pattern
                            if (preg_match('/\\\\text\{DATI\}|\\\\text\{INCOGNITE\}|\\\\text\s*\{\s*DATI\s*\}/i', $c)) {
                                $isDatiLatex = true;
                            }
                        }
                        // Inserisci text "\n\n" PRIMA del block successivo se prevWasDati
                        if ($prevWasDati && ($b['type'] ?? '') === 'text') {
                            $c = $b['content'] ?? '';
                            if (!preg_match('/^\s*\n\n/u', $c) && trim($c) !== '') {
                                $b['content'] = "\n\n" . ltrim($c);
                                $total++; $itemChanged = true;
                            }
                        } elseif ($prevWasDati && ($b['type'] ?? '') !== 'text') {
                            // Inserisci separator block
                            $newBlocks[] = ['type' => 'text', 'content' => "\n\n"];
                            $total++; $itemChanged = true;
                        }
                        $newBlocks[] = $b;
                        $prevWasDati = $isDatiLatex;
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
echo "DATI separators: $total / items: $items / files: $files\n";
