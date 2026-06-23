<?php
/**
 * Fix spaziatura punteggiatura. Conservative: solo pattern ovvi.
 *   - "[a-z]\.[A-Z]" → "[a-z]. [A-Z]" (period + maiuscola senza spazio = sentence break)
 *   - "[a-z],[a-z]" → "[a-z], [a-z]" (lower+comma+lower = enum/lista)
 *   - "[a-z];[a-z]" → "[a-z]; [a-z]"
 *   - "[a-z]:[A-Z]" → "[a-z]: [A-Z]"
 *
 * Skip:
 *   - se text contiene LaTeX inline (\, \\, $)
 *   - numeri 3,14 / 1.234 (la regex già exclude perché richiede letter, non digit)
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalChanges = 0;
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
                foreach (['question','justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as $bi => &$b) {
                        if (($b['type'] ?? '') !== 'text') continue;
                        $c = (string)($b['content'] ?? '');
                        if ($c === '') continue;
                        // Skip se contiene latex inline
                        if (preg_match('/\\\\(?:documentclass|begin|end|usepackage)|[$]/i', $c)) continue;
                        $orig = $c;

                        // Period + uppercase (sentence break) — solo se prima
                        // del period c'è ≥4 char (esclude abbreviazioni "a.C.",
                        // "d.C.", "es.", "fig.", "n.").
                        // Skip anche se il pattern e' "X.Y." (abbreviazioni multi-letter).
                        $c = preg_replace_callback(
                            '/(\b\p{L}{4,})\.(\p{Lu})(?!\.)/u',
                            function ($m) { return $m[1] . '. ' . $m[2]; },
                            $c
                        ) ?? $c;
                        // Period + lowercase letter (Italian "ostacolo.la" → "ostacolo. la")
                        // Skip abbreviazioni: prima del period almeno 4 char.
                        $c = preg_replace_callback(
                            '/(\b\p{L}{4,})\.(\p{Ll})/u',
                            function ($m) { return $m[1] . '. ' . $m[2]; },
                            $c
                        ) ?? $c;
                        // Comma + letter SOLO se entrambi i lati hanno ≥2 lettere
                        // (skip "x,y" "a,b" notazione coordinate/algebra).
                        $c = preg_replace('/(\p{Ll}{2,}),(\p{L}{2,})/u', '$1, $2', $c) ?? $c;
                        // Semicolon + letter — stessa restrizione
                        $c = preg_replace('/(\p{Ll}{2,});(\p{L}{2,})/u', '$1; $2', $c) ?? $c;
                        // Colon + uppercase (label/list) — restrizione 3+ char
                        $c = preg_replace('/(\p{Ll}{3,}):(\p{Lu})/u', '$1: $2', $c) ?? $c;

                        if ($c !== $orig) {
                            $b['content'] = $c;
                            $totalChanges++;
                            $itemChanged = true;
                            if (count($samples) < 10) {
                                $samples[] = "$name g$gi i$ii $k.b$bi: '" . substr($orig, 0, 70) . "' → '" . substr($c, 0, 70) . "'";
                            }
                        }
                    }
                    unset($b);
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
echo "changes: $totalChanges\n";
echo "items: $itemsTouched\n";
echo "files: " . count($filesTouched) . "\n";
echo "\nSamples:\n";
foreach ($samples as $s) echo "  $s\n";
