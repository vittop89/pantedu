<?php
/**
 * Estrae badge LaTeX residui dal content question → metadata fields.
 *
 * Pattern atteso (real, da Limiti-ART5):
 *   \(\overset{\color{COLOR}\huge BULLETS}{\underset{\text{P-}NUM_PAGINA}
 *     {\bbox[border: 1px solid white; background: BG_COLOR,3pt]
 *       {{\mathmakebox[cm][c]{\textcolor{white}{\large NUM_ESERCIZIO}}}}}}
 *     \quad <REST_OF_CONTENT>
 *
 * Estrae:
 *   - difficulty   = count(\bullet) (filled bullets)
 *   - bg_color     = BG_COLOR (background del bbox)
 *   - page         = NUM_PAGINA  (P-101 → 101)
 *   - ex_num       = NUM_ESERCIZIO (literal number/code)
 *
 * Rimuove dal content il badge LaTeX (tutto fino a \quad incluso),
 * lasciando il REST_OF_CONTENT racchiuso ancora in \(...\).
 *
 * Idempotente: se badge gia' estratto (no overset), no-op.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];
$files = [];
foreach ($dirs as $d) $files = array_merge($files, glob($d . '/*.contract.json'));

// Approccio in 2 step:
// 1) match il PREFIX badge fino a \quad (greedy non-greedy con look-ahead)
// 2) estrai i campi via regex piu' specifiche dentro il prefix matched.
$RE_PREFIX = '#\\\\\(\\\\overset\{.*?\}\\\\quad\s*#us';

$changes = 0;
$samples = [];

foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    $name = basename($path);
    $fileChanged = false;

    foreach ($j['groups'] as $gi => &$g) {
        if (!isset($g['items']) || !is_array($g['items'])) continue;
        foreach ($g['items'] as $ii => &$it) {
            if (!isset($it['question']) || !is_array($it['question'])) continue;
            foreach ($it['question'] as $bi => &$blk) {
                $type = $blk['type'] ?? '';
                $content = (string)($blk['content'] ?? '');
                if ($type !== 'latex' || !str_contains($content, 'overset') || !str_contains($content, 'mathmakebox')) continue;
                if (!preg_match($RE_PREFIX, $content, $m)) {
                    $samples[] = "$name g$gi i$ii b$bi: prefix MISS — content head: " . substr($content, 0, 120);
                    continue;
                }
                $prefix = $m[0]; // tutto fino a \quad incluso

                // Estrai bullets
                if (!preg_match('/\\\\huge\s*((?:\\\\bullet|\\\\circ)+)/', $prefix, $mb)) continue;
                $bullets = $mb[1];

                // Estrai bg color (background del bbox)
                if (!preg_match('/background:\s*(\w+)/', $prefix, $mc)) continue;
                $bgColor = $mc[1];

                // Estrai numero pagina (\underset{\text{...}NUM} oppure {\text{P-}101})
                $page = '';
                if (preg_match('/\\\\underset\{\\\\text\{([^}]*)\}(\d*)/', $prefix, $mp)) {
                    $textPart = $mp[1];
                    $numPart  = $mp[2];
                    $page = trim($textPart . $numPart);
                    if (preg_match('/^P-(\d+)$/', $page, $pm)) $page = $pm[1];
                }

                // Estrai numero esercizio (\large 99 oppure \large \text{P1})
                $exNumLiteral = '';
                if (preg_match('/\\\\large\s*(?:\\\\text\{)?([\w\d-]+)\}?/', $prefix, $me)) {
                    $exNumLiteral = trim($me[1]);
                }

                $diff = substr_count($bullets, '\\bullet');

                // Costruisci nuovo content: rimuovi il badge LaTeX prefix
                // Sostituisce $prefix con `\(` (per riaprire delimiter math).
                $newContent = preg_replace('/' . preg_quote($prefix, '/') . '/u', '\\(', $content, 1);
                // Se ora inizia con \(\ \) (badge era unico contenuto inline),
                // potrebbe restare \(  \) — collassa.
                $newContent = preg_replace('/^\\\\\(\s*\\\\\)\s*/u', '', $newContent);

                $blk['content'] = $newContent;

                // Set metadata sull'item (non sul block)
                $it['difficulty']     = $diff;
                $it['bg_color']       = $bgColor;
                $it['page']           = $page;
                $it['ex_num']         = $exNumLiteral;
                $it['origin']         = $it['origin'] ?? 'unknown';

                $changes++;
                $samples[] = "$name g$gi i$ii: badge extracted → diff=$diff bg=$bgColor page=$page ex_num='$exNumLiteral'";
                $fileChanged = true;
            }
        }
    }

    if ($fileChanged && $apply) {
        file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "badges extracted: $changes\n";
foreach ($samples as $s) echo "  $s\n";
