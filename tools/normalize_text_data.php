<?php
/**
 * Normalizza text content nei contracts:
 *   - `\n+\s*\n+` (3+ newline orfani con spazi residual) → `\n\n` (paragraph break uniforme)
 *   - `  +` (2+ spazi) → ` ` (singolo spazio)
 *   - Trim trailing whitespace orfano (' \n' → '\n')
 *
 * Lavora su question[]/justification[]/solution[] in tutti i contract.
 * Lascia intatti latex/tikz (codice sintattico).
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$totalChanges = 0;
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
                        $orig = $c;
                        // Normalize trailing space before newline
                        $c = preg_replace('/[ \t]+(\n)/u', '$1', $c) ?? $c;
                        // Collapse multi-spaces (no newlines)
                        $c = preg_replace('/[ \t]{2,}/u', ' ', $c) ?? $c;
                        // Collapse multi-newlines (with optional whitespace) to \n\n
                        $c = preg_replace('/\n+[ \t]*\n+/u', "\n\n", $c) ?? $c;
                        if ($c !== $orig) {
                            $b['content'] = $c;
                            $totalChanges++;
                            $itemChanged = true;
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
foreach ($filesTouched as $f) echo "  - $f\n";
