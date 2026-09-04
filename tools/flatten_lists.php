<?php
/** Converte blocchi type=list in sequenze di blocchi text+latex con prefisso
 *  "• " (bulleted) o "N. " (numbered) → struttura visibile nell'editor textarea.
 *
 *  Beneficio: l'editor extractor (collectRaw + textarea) e' basato su data-raw
 *  e textContent. Senza handler per `list`, blocchi list sono stati ignorati
 *  rendendo la struttura invisibile in edit-mode. Con questa trasformazione,
 *  ogni item del list diventa una sequenza di blocchi inline iniziando con
 *  un text block "• " (visivo) seguito dai blocchi originali dell'item. */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalLists = 0; $totalItems = 0; $files = 0;
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
                    $newBlocks = [];
                    foreach ($blocks as $b) {
                        if (($b['type'] ?? '') !== 'list') {
                            $newBlocks[] = $b;
                            continue;
                        }
                        // Flatten list to inline blocks
                        $ordered = !empty($b['ordered']);
                        $items = $b['items'] ?? [];
                        if (!is_array($items)) {
                            $newBlocks[] = $b; continue;
                        }
                        $totalLists++;
                        $itemChanged = true;
                        foreach ($items as $idx => $listItem) {
                            if (!is_array($listItem)) continue;
                            // Prefix
                            $marker = $ordered ? ($idx + 1) . '. ' : '• ';
                            // Separator (\n\n) before each item except first
                            $prefix = $idx === 0 ? '' : "\n\n";
                            $newBlocks[] = ['type' => 'text', 'content' => $prefix . $marker];
                            // Append original item blocks
                            foreach ($listItem as $sb) {
                                if (is_array($sb)) $newBlocks[] = $sb;
                            }
                        }
                    }
                    if ($itemChanged) $it[$k] = $newBlocks;
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $totalItems++;
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
echo "list blocks flattened: $totalLists / items: $totalItems / files: $files\n";
