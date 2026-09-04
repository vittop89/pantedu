<?php
declare(strict_types=1);
/**
 * Test idempotenza normalizer: applica normalizeTikz 2 volte e confronta.
 */

// Import del file principale: per evitare di eseguire il main, copiamo le
// funzioni qui o usiamo eval. Soluzione: leggiamo lo script principale e
// estraiamo solo le definizioni di funzione.

$src = file_get_contents(__DIR__ . '/tikz_normalize.php');
$src = preg_replace('/^<\?php\s*/u', '', $src);
$marker = '// ────────────────────────── implementazione ──────────────────────────────────';
$pos = strpos($src, $marker);
$lib = substr($src, $pos);
eval($lib);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$tested = 0;
$nonIdempotent = 0;
$compileSafe = 0;
$missingTikzPicture = 0;
foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        foreach ($j['groups'] as $g) {
            foreach ($g['items'] ?? [] as $it) {
                foreach (['question','options','justification','solution'] as $k) {
                    foreach ($it[$k] ?? [] as $b) {
                        if (($b['type']??'') !== 'tikz') continue;
                        $tested++;
                        $orig = $b['script'] ?? '';
                        $first = normalizeTikz($orig);
                        $second = normalizeTikz($first);
                        if ($first !== $second) {
                            $nonIdempotent++;
                            if ($nonIdempotent <= 1) {
                                // Trova primo punto di divergenza
                                $minLen = min(strlen($first), strlen($second));
                                $diffAt = $minLen;
                                for ($i = 0; $i < $minLen; $i++) {
                                    if ($first[$i] !== $second[$i]) { $diffAt = $i; break; }
                                }
                                $start = max(0, $diffAt - 80);
                                echo "--- NON-IDEMPOTENT in " . basename($path) . " (diff at byte $diffAt) ---\n";
                                echo "FIRST  ctx: " . substr($first,  $start, 200) . "\n";
                                echo "SECOND ctx: " . substr($second, $start, 200) . "\n";
                                echo "FIRST len:  " . strlen($first) . " SECOND len: " . strlen($second) . "\n\n";
                            }
                        }
                        // Sanity: deve contenere \begin{tikzpicture} e \begin{document}
                        if (!str_contains($first, '\\begin{tikzpicture}')) {
                            $missingTikzPicture++;
                            if ($missingTikzPicture <= 2) {
                                echo "MISSING \\begin{tikzpicture} in " . basename($path) . ":\n";
                                echo substr($first, 0, 400) . "\n\n";
                            }
                        }
                        if (str_contains($first, '\\begin{document}') && str_contains($first, '\\end{document}') && str_contains($first, '\\usepackage{tikz}')) {
                            $compileSafe++;
                        }
                    }
                }
            }
        }
    }
}
echo "Tested: $tested\n";
echo "Non-idempotent: $nonIdempotent\n";
echo "Missing tikzpicture: $missingTikzPicture\n";
echo "Compile-safe (has tikz pkg + begin/end document): $compileSafe\n";
