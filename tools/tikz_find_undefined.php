<?php
declare(strict_types=1);
/**
 * Trova comandi/pic custom usati ma NON definiti nello stesso blocco.
 * Output: lista comandi → file/blocco/snippet.
 */
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

// Cmd suspect: nomi typicamente custom (schemaRow, disegnaScena, perpquote, ecc).
$suspects = [
    'perpquote'         => 'pic',
    'schemaRow'         => 'newcommand',
    'schemaTextAbove'   => 'newcommand',
    'schemaTextBelow'   => 'newcommand',
    'schemaSegni'       => 'newcommand',
    'disegnaScena'      => 'newcommand',
];
$missing = []; // cmd → [files]
foreach ($dirs as $d) {
    foreach (glob($d . '/*.contract.json') as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        foreach ($j['groups'] as $g) {
            if (!isset($g['items'])) continue;
            foreach ($g['items'] as $it) {
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k])) continue;
                    foreach ($it[$k] as $b) {
                        if (($b['type'] ?? '') !== 'tikz') continue;
                        $s = $b['script'] ?? '';
                        foreach ($suspects as $cmd => $kind) {
                            $usePat = $kind === 'pic'
                                ? '/' . preg_quote($cmd, '/') . '\s*=/u'
                                : '/\\\\' . preg_quote($cmd, '/') . '\s*[\{\[]/u';
                            $defPat = $kind === 'pic'
                                ? '/pics\\/' . preg_quote($cmd, '/') . '\\b/u'
                                : '/\\\\newcommand\\s*\\{?\\s*\\\\' . preg_quote($cmd, '/') . '\\b/u';
                            $uses = preg_match($usePat, $s);
                            $defs = preg_match($defPat, $s);
                            if ($uses && !$defs) {
                                $missing[$cmd][basename($f)] = ($missing[$cmd][basename($f)] ?? 0) + 1;
                            }
                        }
                    }
                }
            }
        }
    }
}
echo "Custom cmds USED WITHOUT being DEFINED in the same block:\n";
foreach ($missing as $cmd => $files) {
    $tot = array_sum($files);
    echo "  \\$cmd: $tot blocks in " . count($files) . " files\n";
    foreach ($files as $f => $n) echo "    - $f: $n\n";
}
