<?php
declare(strict_types=1);
/**
 * tikz_normalize.php — riscrive i blocchi TikZ legacy nei contract JSON
 * portandoli al formato standard atteso dal VPS tex-compile (Case 2:
 * preamble dell'autore autoritativo).
 *
 * Trasformazioni (idempotenti):
 *   1. Strip HTML residue (<br>, <p>, <span>, <div>, <b>, <i>, <u>).
 *   2. \usepackage{pgf} → \usepackage{tikz} (pgf NON definisce tikzpicture).
 *   3. Drop \usepackage{amsfonts} (deprecato; amssymb copre).
 *   4. Detect amsmath / amssymb se usati nel body, else drop.
 *   5. Collect tutti gli \usetikzlibrary{...} (preamble + body), dedupe,
 *      normalize whitespace, auto-add libs in base ai pattern usati.
 *   6. Move \usetikzlibrary fuori dal body (devono stare in preamble).
 *   7. Output canonico:
 *        \usepackage{tikz}
 *        \usepackage{pgfplots} \pgfplotsset{compat=1.18}    [opzionale]
 *        \usepackage{amsmath,amssymb}                      [se usato]
 *        \usepackage{tikzpeople}                           [se usato]
 *        \usetikzlibrary{...}                              [una sola riga]
 *        \begin{document}
 *        ...body...
 *        \end{document}
 *
 * Usage: php tools/tikz_normalize.php [--apply] [--limit=N]
 */

$apply = in_array('--apply', $argv, true);
$limit = null;
foreach ($argv as $a) if (preg_match('/^--limit=(\d+)$/', $a, $m)) $limit = (int)$m[1];

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$total_blocks = 0;
$changed_blocks = 0;
$changed_items = 0;
$changed_files = 0;
$processed = 0;

$diffs = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['question','options','justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as &$b) {
                        if (($b['type']??'') !== 'tikz') continue;
                        $total_blocks++;
                        if ($limit !== null && $processed >= $limit) continue;
                        $processed++;
                        $orig = $b['script'] ?? '';
                        $new = normalizeTikz($orig);
                        if ($new !== $orig) {
                            $changed_blocks++;
                            if (count($diffs) < 5) {
                                $diffs[] = [
                                    'file' => basename($path),
                                    'orig' => substr($orig, 0, 600),
                                    'new'  => substr($new, 0, 600),
                                ];
                            }
                            $b['script'] = $new;
                            $itemChanged = true;
                        }
                    }
                    unset($b);
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $changed_items++;
                    $fileChanged = true;
                }
            }
            unset($it);
        }
        unset($g);
        if ($fileChanged) {
            $changed_files++;
            if ($apply) file_put_contents(
                $path,
                json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
            );
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Blocks total:   $total_blocks\n";
echo "Blocks changed: $changed_blocks\n";
echo "Items changed:  $changed_items\n";
echo "Files changed:  $changed_files\n";
if (!empty($diffs)) {
    echo "\n--- Diff samples ---\n";
    foreach ($diffs as $i => $d) {
        echo "\n[$i] {$d['file']}\nORIG:\n{$d['orig']}\n\nNEW:\n{$d['new']}\n";
        echo str_repeat('-', 60) . "\n";
    }
}

// ────────────────────────── implementazione ──────────────────────────────────

function normalizeTikz(string $src): string {
    $s = $src;

    // 1. Strip HTML residue. Usiamo `[^<>]*` (non `[^>]*`) per impedire al
    //    regex di "saltare" un `<` interno e mangiare codice TikZ valido tipo
    //    `$0<b<1$` (che il regex scambierebbe per un tag <b ... >).
    $s = preg_replace('#<br\s*/?>#i', "\n", $s) ?? $s;
    $s = preg_replace('#</?(?:p|span|div|b|i|u)\b[^<>]*>#i', '', $s) ?? $s;

    // 2. CRLF/CR → LF
    $s = str_replace(["\r\n", "\r"], "\n", $s);

    // 3. Decode HTML entities (& numeric e named)
    $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 3b. NBSP (U+00A0, da editor HTML) → spazio normale. pdflatex non
    //     interpreta nbsp come whitespace nei keyword args ("slope", "arrow"
    //     ecc), causando errori di template-match in pics/.style args.
    $s = str_replace("\xC2\xA0", ' ', $s);

    // Skip se contiene \documentclass (caso 1 VPS, già autonomo)
    if (preg_match('/\\\\documentclass\b/', $s)) {
        return collapseBlankLines(rtrim($s)) . "\n";
    }

    // 4. Estrai \usepackage{...} e \usetikzlibrary{...} (anche dentro body)
    $packages = [];      // map name => true (mantiene primo per dedupe)
    $libraries = [];     // map name => true
    $pgfplotsCompat = null;

    // \usepackage{a,b,c} con eventuale [opt]
    $s = preg_replace_callback(
        '/\\\\usepackage(?:\[[^\]]*\])?\{([^}]+)\}\s*\n?/u',
        function ($m) use (&$packages) {
            foreach (preg_split('/\s*,\s*/', trim($m[1])) as $p) {
                $p = trim($p);
                if ($p !== '') $packages[$p] = true;
            }
            return '';
        },
        $s
    );

    // \usetikzlibrary{a,b,c}
    $s = preg_replace_callback(
        '/\\\\usetikzlibrary\{([^}]+)\}\s*\n?/u',
        function ($m) use (&$libraries) {
            foreach (preg_split('/\s*,\s*/', trim($m[1])) as $l) {
                $l = trim($l);
                if ($l !== '') $libraries[$l] = true;
            }
            return '';
        },
        $s
    );

    // \pgfplotsset{compat=...}
    if (preg_match('/\\\\pgfplotsset\{compat=([^}]+)\}/u', $s, $m)) {
        $pgfplotsCompat = trim($m[1]);
        $s = preg_replace('/\\\\pgfplotsset\{compat=[^}]+\}\s*\n?/u', '', $s) ?? $s;
    }

    // 5. Filtra preamble: pgf→tikz, drop amsfonts
    unset($packages['pgf']);
    unset($packages['amsfonts']);

    // 6. Detect packages effettivi dal body
    $usedAmsmath = preg_match(
        '/\\\\(?:dfrac|tfrac|cfrac|frac|text|substack|underset|overset|binom|boxed|operatorname|begin\{align\}|begin\{cases\}|Longrightarrow|Longleftarrow|leftrightarrow|implies|iff)/u',
        $s
    ) === 1;
    $usedAmssymb = preg_match(
        '/\\\\(?:mathbb|mathfrak|mathcal|nexists|nleq|ngeq|ncong|nsubseteq|notin|because|therefore|blacksquare|leqslant|geqslant|preceq|succeq)/u',
        $s
    ) === 1;
    $usedPhysics = preg_match('/\\\\(?:vb|vu|va|grad|curl|div|qq|abs|order|eval)\b/u', $s) === 1;
    $usedTikzpeople = preg_match('/\\b(?:alice|bob|charlie|dave|eve|fred|maninblack|cowboy|nurse|grandparent|judge|graduate|chef|priest|sailor|police|fairy|surgeon|jester|mexican|king|queen|pharaoh|builder|punk|monk|critic|bear|moustache|conductor|crookedman|cyclist|guard|astro|smithy)\b/i', $s) === 1;

    if ($usedAmsmath)  $packages['amsmath']  = true;
    if ($usedAmssymb)  $packages['amssymb']  = true;
    if ($usedPhysics)  $packages['physics']  = true;
    if ($usedTikzpeople) $packages['tikzpeople'] = true;

    // pgfplots
    $usedPgfplots = (isset($packages['pgfplots'])
        || preg_match('/\\\\begin\{axis\}|\\\\addplot\b|\\\\pgfplots/u', $s) === 1);

    // tikz è sempre richiesto
    $packages['tikz'] = true;

    // 7. Auto-detect TikZ libraries
    $detect = [
        'arrows.meta'                  => '/\\[(?:[^\\]]*?)>=(?:Latex|Stealth|Triangle|Bar|To)|\\bto path|\\barrow head\\b/u',
        'calc'                         => '/\\\\path\\b.*\\blet\\b|let\\s+\\\\p\\d/u',
        'angles'                       => '/\\bpic\\s*\\[/u',
        'quotes'                       => '/edge\\b.*?node\\[(?:auto|right|left|above|below)/u',
        'positioning'                  => '/\\b(?:right|left|above|below)\\s*(?:=|of)\\s*(?:of|[0-9]+(?:\\.\\d+)?(?:cm|pt|em)?)\\s+of\\b|node\\s+distance/u',
        'decorations.pathmorphing'     => '/decoration\\s*=\\s*\\{(?:zigzag|coil|snake|bumps|bent)/u',
        'decorations.pathreplacing'    => '/decoration\\s*=\\s*\\{(?:brace|expanding|show path|amplitude)/u',
        'decorations.markings'         => '/decoration\\s*=\\s*\\{markings/u',
        'patterns'                     => '/pattern\\s*=\\s*[a-z]/u',
        'matrix'                       => '/\\\\matrix\\b|matrix\\s+of\\s+(?:nodes|math|symbols)/u',
        'shapes.geometric'             => '/\\b(?:regular polygon|trapezium|ellipse|cylinder|kite|diamond|star|cloud|signal|chamfered)\\b/u',
        'shapes.callouts'              => '/\\bcallout\\b/u',
        'backgrounds'                  => '/on\\s+background\\s+layer/u',
        'shadings'                     => '/\\\\shade\\b|shading\\s*=/u',
        '3d'                           => '/canvas\\s+is/u',
        'intersections'                => '/name\\s+path\\s*=|name\\s+intersections/u',
    ];
    foreach ($detect as $libName => $pattern) {
        if (preg_match($pattern, $s)) $libraries[$libName] = true;
    }
    // Pulisci il nome libraries da spazi residui
    $libsClean = [];
    foreach ($libraries as $name => $_) {
        $n = trim($name);
        if ($n !== '') $libsClean[$n] = true;
    }
    $libraries = $libsClean;

    // 8. Estrai preamble-extras + body.
    //    preamble-extras = tutto ciò che resta PRIMA di \begin{document}
    //                      (custom \def, \newcommand, \tikzset, ecc.)
    //    body = tutto ciò che è TRA \begin{document} e \end{document}
    //    Senza wrapper: tutto $s diventa body, niente extras.
    $extras = '';
    $body = '';
    if (preg_match('/^(.*?)\\\\begin\{document\}(.*?)\\\\end\{document\}.*$/s', $s, $m)) {
        $extras = $m[1];
        $body   = $m[2];
    } else {
        $body = $s;
    }
    $extras = collapseBlankLines($extras);
    $extras = trim($extras);
    $body = collapseBlankLines($body);
    $body = trim($body);

    // 9. Costruisci preamble canonico
    $pre = [];
    $pre[] = '\\usepackage{tikz}';
    if ($usedPgfplots) {
        $pre[] = '\\usepackage{pgfplots}';
        $pre[] = '\\pgfplotsset{compat=' . ($pgfplotsCompat ?: '1.18') . '}';
    }
    $mathPkgs = [];
    if (!empty($packages['amsmath'])) $mathPkgs[] = 'amsmath';
    if (!empty($packages['amssymb'])) $mathPkgs[] = 'amssymb';
    if ($mathPkgs) $pre[] = '\\usepackage{' . implode(',', $mathPkgs) . '}';
    if (!empty($packages['mathtools'])) $pre[] = '\\usepackage{mathtools}';
    if (!empty($packages['physics'])) $pre[] = '\\usepackage{physics}';
    if (!empty($packages['tikzpeople'])) $pre[] = '\\usepackage{tikzpeople}';
    if (!empty($packages['xcolor']) || !empty($packages['color'])) $pre[] = '\\usepackage{xcolor}';

    if (!empty($libraries)) {
        $libs = array_keys($libraries);
        sort($libs); // ordine stabile, dedup-friendly
        $pre[] = '\\usetikzlibrary{' . implode(',', $libs) . '}';
    }

    $preStr = implode("\n", $pre);
    if ($extras !== '') $preStr .= "\n" . $extras;
    $out = $preStr . "\n\\begin{document}\n" . $body . "\n\\end{document}";
    $out = collapseBlankLines($out);
    // Rimuove righe vuote immediatamente prima di \end{document} (idempotency).
    $out = preg_replace('/\n\s*\n+\\\\end\{document\}/u', "\n\\end{document}", $out) ?? $out;
    return rtrim($out, "\n") . "\n";
}

function collapseBlankLines(string $s): string {
    $s = preg_replace('/[ \t]+\n/u', "\n", $s) ?? $s;
    $s = preg_replace('/\n{3,}/u', "\n\n", $s) ?? $s;
    return $s;
}
