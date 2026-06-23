<?php
/** RM justification: list ordered:true → false (bulleted invece di numerato).
 *  L'elenco ha senso bulleted quando le opzioni (cells) non hanno indicatori
 *  numerici/letterali visibili. Le V./F. prefix interni a ciascun item della
 *  list servono già da label. */
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
            $isRM = ($g['type'] ?? '') === 'RM';
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as &$b) {
                        if (($b['type'] ?? '') === 'list' && !empty($b['ordered'])) {
                            // Solo per RM o se gli items non sembrano una sequenza numerica
                            if ($isRM) {
                                $b['ordered'] = false;
                                $total++; $itemChanged = true;
                            }
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
echo "RM list ordered→false: $total / items: $items / files: $files\n";
