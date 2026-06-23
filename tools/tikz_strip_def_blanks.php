<?php
declare(strict_types=1);
/**
 * tikz_strip_def_blanks.php — collassa righe vuote dentro `\def\<nome>{...}`
 * blocks. Causa frequente di compile error in TikZ:
 *
 *   "Paragraph ended before \pgffor@normal@list was complete."
 *
 * Quando un `\def\allScenes{...}` ha righe vuote nel body, TeX inserisce
 * `\par` durante l'espansione dentro `\foreach \... in \allScenes {...}`,
 * e il `\foreach` (che non e' `\long`) termina l'argomento prematuramente.
 *
 * Soluzione: collassare `\n\s*\n+` → `\n` SOLO dentro corpi di `\def`.
 * Il body code TikZ esterno NON viene toccato (preserva leggibilita).
 *
 * Idempotente: re-run non duplica nulla.
 *
 * Usage: php tools/tikz_strip_def_blanks.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

$total = 0; $changed = 0; $defsFixed = 0;
foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as &$b) {
                        if (($b['type'] ?? '') !== 'tikz') continue;
                        $total++;
                        $orig = $b['script'] ?? '';
                        $new  = stripBlanksInDefs($orig, $defsFixed);
                        if ($new !== $orig) {
                            $changed++;
                            $b['script'] = $new;
                            $itemChanged = true;
                        }
                    }
                    unset($b);
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $fileChanged = true;
                }
            }
            unset($it);
        }
        unset($g);
        if ($fileChanged && $apply) {
            file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Blocks scanned:  $total\n";
echo "Blocks changed:  $changed\n";
echo "\\def bodies fixed: $defsFixed\n";

/**
 * Trova ogni `\def\<name>{` o `\edef\<name>{` o `\xdef\<name>{` o `\gdef\<name>{`
 * e collassa righe vuote nel body fino alla `}` bilanciata.
 */
function stripBlanksInDefs(string $s, int &$defsFixed): string {
    $out = '';
    $i = 0;
    $n = strlen($s);
    while ($i < $n) {
        // Cerca pattern \def \edef \xdef \gdef seguito da \name{
        if ($s[$i] === '\\' && preg_match('/\G\\\\(?:long\s+)?(?:def|edef|xdef|gdef)\s*\\\\([a-zA-Z@]+)\s*\{/A', $s, $m, 0, $i)) {
            $defStart = $i;
            $bodyStart = $i + strlen($m[0]); // position after `{`
            // Trova `}` bilanciata
            $depth = 1;
            $j = $bodyStart;
            while ($j < $n && $depth > 0) {
                if ($s[$j] === '\\' && $j + 1 < $n) {
                    $j += 2; // skip \{ \} \\ ecc
                    continue;
                }
                if ($s[$j] === '{') $depth++;
                elseif ($s[$j] === '}') $depth--;
                $j++;
            }
            if ($depth !== 0) {
                // Brace non bilanciate → emetti il prefisso e continua char-by-char
                $out .= substr($s, $i, strlen($m[0]));
                $i = $bodyStart;
                continue;
            }
            $bodyEnd = $j - 1; // position of closing `}`
            $body = substr($s, $bodyStart, $bodyEnd - $bodyStart);
            $newBody = preg_replace('/\n\s*\n+/u', "\n", $body) ?? $body;
            if ($newBody !== $body) $defsFixed++;
            $out .= substr($s, $defStart, $bodyStart - $defStart) . $newBody . '}';
            $i = $j; // posizione dopo `}`
        } else {
            $out .= $s[$i];
            $i++;
        }
    }
    return $out;
}
