<?php
declare(strict_types=1);

// Match il pattern badge: `\(\overset{\color{COLOR}\huge<dots>}{\underset{...}{\bbox[...]{...\text{CODE}}}}\quad\)`
// I bullet pattern: \bullet (filled), \circ (empty)
// Dots possono essere: \bullet\bullet\circ\circ (difficulty=2/4) ecc.
$REGEX = '#\\\\\(\\\\overset\{\\\\color\{(\w+)\}\\\\huge((?:\\\\bullet|\\\\circ)+)\}\{\\\\underset\{[^}]*\}\{\\\\bbox\[[^\]]*background:\s*(\w+)[^\]]*\]\{\{\\\\mathmakebox\[[^\]]*\]\{\\\\textcolor\{[^}]*\}\{\\\\large\s*\\\\text\{([^}]*)\}\}\}\}\}\\s*\}\\\\quad\\\\\)#u';

$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/eser';
$files = glob($dir . '/*.contract.json');
$total_items = 0;
$with_badge = 0;
$samples = [];
$exNums = [];

foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($path);
    foreach ($j['groups'] as $gi => $g) {
        foreach (($g['items'] ?? []) as $ii => $it) {
            $total_items++;
            foreach (($it['question'] ?? []) as $bi => $blk) {
                $c = (string)($blk['content'] ?? '');
                if (preg_match($REGEX, $c, $m)) {
                    $with_badge++;
                    $bullets = $m[2];
                    $filledCount = substr_count($bullets, '\\bullet');
                    $emptyCount = substr_count($bullets, '\\circ');
                    $bgColor = $m[3];
                    $code = $m[4];
                    $exNums[] = $code;
                    if (count($samples) < 3) {
                        $samples[] = "$name g$gi i$ii: code='$code' diff=$filledCount/" . ($filledCount + $emptyCount) . " bg=$bgColor";
                    }
                    break; // primo block question solo
                }
            }
        }
    }
}
echo "=== TOTALI ===\n";
echo "items totali: $total_items\n";
echo "items con badge LaTeX: $with_badge\n";
echo "\n=== SAMPLES ===\n";
foreach ($samples as $s) echo "  - $s\n";
echo "\n=== ex_num distribution ===\n";
$counts = array_count_values($exNums);
foreach ($counts as $code => $n) echo "  $code: $n\n";
