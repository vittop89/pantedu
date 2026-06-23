<?php
/**
 * Fixer R1+R2 per VERIFICHE (cartella verifiche/).
 * Stessa logica di fix_spacing_dryrun.php ma esteso a:
 *   - question[], justification[], solution[]
 *   - body_html viene azzerato (force re-render client) se modifico question/justification.
 *
 * In verifiche RM/VF c'e' anche options[].content (text) ma e' singolo block,
 * non array di blocks misti — non necessita spacing fix.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche';
$files = glob($dir . '/*.contract.json');

$totalChanges = 0;
$itemsTouched = 0;
$filesTouched = [];
$samples = [];

function fixItemBlocks(array &$it, string $where, int &$changes, array &$samples): bool {
    $touched = false;
    foreach (['question', 'justification', 'solution'] as $k) {
        if (!isset($it[$k]) || !is_array($it[$k])) continue;
        $n = count($it[$k]);
        for ($i = 0; $i < $n; $i++) {
            $cur = &$it[$k][$i];
            $type = $cur['type'] ?? '';
            $content = (string)($cur['content'] ?? '');

            // R1: text → next latex/tikz, append space
            if ($type === 'text' && $i + 1 < $n) {
                $nextType = $it[$k][$i + 1]['type'] ?? '';
                if (in_array($nextType, ['latex', 'tikz'], true)
                    && $content !== ''
                    && !preg_match('/[\s\n]$/u', $content)
                    && preg_match('/[\p{L}\p{N})\]]$/u', $content)
                ) {
                    $cur['content'] = $content . ' ';
                    $content = $cur['content'];
                    $changes++; $touched = true;
                    if (count($samples) < 6) $samples[] = "$where $k[$i] R1: '...." . substr($content, -20) . "'";
                }
            }
            // R2: text after latex/tikz, prepend space
            if ($type === 'text' && $i > 0) {
                $prevType = $it[$k][$i - 1]['type'] ?? '';
                if (in_array($prevType, ['latex', 'tikz'], true)
                    && $content !== ''
                    && !preg_match('/^[\s\n]/u', $content)
                    && preg_match('/^[\p{L}\p{N}]/u', $content)
                ) {
                    $cur['content'] = ' ' . $content;
                    $changes++; $touched = true;
                    if (count($samples) < 12) $samples[] = "$where $k[$i] R2: '" . substr($cur['content'], 0, 24) . "...'";
                }
            }
            // R3: triple newlines collapse
            if ($type === 'text' && preg_match('/\n\n\n+/', $content)) {
                $cur['content'] = preg_replace('/\n\n\n+/', "\n\n", $content);
                $changes++; $touched = true;
            }
        }
    }
    return $touched;
}

foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($path);
    $fileChanged = false;

    foreach ($j['groups'] as $gi => &$g) {
        if (!isset($g['items']) || !is_array($g['items'])) continue;
        foreach ($g['items'] as $ii => &$it) {
            if (fixItemBlocks($it, "$name g$gi i$ii", $totalChanges, $samples)) {
                $itemsTouched++;
                $fileChanged = true;
                // Invalida body_html cache: forza re-render lato client.
                if (isset($it['body_html'])) {
                    unset($it['body_html']);
                }
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

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "totale changes: $totalChanges\n";
echo "items toccati: $itemsTouched\n";
echo "file toccati: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nsamples:\n";
foreach ($samples as $s) echo "  $s\n";
