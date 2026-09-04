<?php
/**
 * Fixer euristico spaziatura blocchi text↔latex.
 *
 * Regole deterministiche (conservative — meglio false-negative che false-positive):
 *   1. Se text block ENDS con \w o ')' o ']' AND next block tipo latex/tikz
 *      AND text NON termina con whitespace → append ' ' al text.
 *   2. Se text block STARTS con \w AND previous block tipo latex/tikz
 *      AND text NON inizia con whitespace AND prev non finiva con whitespace
 *      → prepend ' ' al text.
 *   3. Trim eccessi: collassi >2 newline a 2.
 *   4. Latex/tikz blocks lasciati intatti (codice sintattico).
 *
 * NOT toccati:
 *   - Block content che inizia con punteggiatura (`,`, `.`, `;`, `:`, `?`, `!`, `)`)
 *   - Block content vuoto
 *   - Block content TUTTO whitespace
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/eser';
$files = glob($dir . '/*.contract.json');

$changes = 0;
$itemsTouched = 0;
$filesTouched = [];
$samples = [];

foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($path);
    $fileChanged = false;

    foreach ($j['groups'] as $gi => &$g) {
        if (!isset($g['items']) || !is_array($g['items'])) continue;
        foreach ($g['items'] as $ii => &$it) {
            $itemChanged = false;
            foreach (['question', 'justification', 'solution'] as $k) {
                if (!isset($it[$k]) || !is_array($it[$k])) continue;
                $n = count($it[$k]);
                for ($i = 0; $i < $n; $i++) {
                    $cur = &$it[$k][$i];
                    $type = $cur['type'] ?? '';
                    $content = (string)($cur['content'] ?? '');

                    // Rule 1: text → next latex/tikz, append space
                    if ($type === 'text' && $i + 1 < $n) {
                        $nextType = $it[$k][$i + 1]['type'] ?? '';
                        if (in_array($nextType, ['latex', 'tikz'], true)
                            && $content !== ''
                            && !preg_match('/[\s\n]$/u', $content)
                            && preg_match('/[\p{L}\p{N})\]]$/u', $content)
                        ) {
                            $oldContent = $content;
                            $content .= ' ';
                            $cur['content'] = $content;
                            $changes++;
                            $itemChanged = true;
                            if (count($samples) < 8) {
                                $samples[] = "$name g$gi i$ii $k b$i [R1 append-space]: ..." . substr($oldContent, -25) . "→...|" . substr($content, -25);
                            }
                        }
                    }

                    // Rule 2: text after latex/tikz, prepend space
                    if ($type === 'text' && $i > 0) {
                        $prevType = $it[$k][$i - 1]['type'] ?? '';
                        if (in_array($prevType, ['latex', 'tikz'], true)
                            && $content !== ''
                            && !preg_match('/^[\s\n]/u', $content)
                            && preg_match('/^[\p{L}\p{N}]/u', $content)
                        ) {
                            $oldContent = $content;
                            $content = ' ' . $content;
                            $cur['content'] = $content;
                            $changes++;
                            $itemChanged = true;
                            if (count($samples) < 16) {
                                $samples[] = "$name g$gi i$ii $k b$i [R2 prepend-space]: " . substr($oldContent, 0, 30) . "→ " . substr($content, 0, 30);
                            }
                        }
                    }

                    // Rule 3: collassi triple newline (text only)
                    if ($type === 'text' && preg_match('/\n\n\n+/', $content)) {
                        $cur['content'] = preg_replace('/\n\n\n+/', "\n\n", $content);
                        $changes++;
                        $itemChanged = true;
                    }
                }
            }
            if ($itemChanged) $itemsTouched++;
            $fileChanged = $fileChanged || $itemChanged;
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
echo "totale changes: $changes\n";
echo "items toccati: $itemsTouched\n";
echo "file toccati: " . count($filesTouched) . "\n";
echo "\nfile list:\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\n=== SAMPLES ===\n";
foreach ($samples as $s) echo "  $s\n";
