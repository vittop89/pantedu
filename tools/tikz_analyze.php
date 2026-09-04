<?php
declare(strict_types=1);
$files = array_merge(
    glob(__DIR__ . '/../storage/objects/institutes/106/private/77/eser/*.contract.json'),
    glob(__DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/*.contract.json')
);
$issues = [
    'no_documentclass' => 0,
    'no_begindoc'      => 0,
    'no_enddoc'        => 0,
    'no_tikzpicture'   => 0,
    'old_pgf_only'     => 0,
    'uses_pgfplots'    => 0,
    'uses_pgf'         => 0,
    'uses_tikz'        => 0,
    'multi_useTikz'    => 0,
    'has_html_residue' => 0,
    'long_block'       => 0,
];
$lib_uses = [];
$pkg_uses = [];
$short_samples = [];
foreach ($files as $f) {
    $j = json_decode(file_get_contents($f), true);
    if (!is_array($j) || empty($j['groups'])) continue;
    foreach ($j['groups'] as $g) {
        if (!isset($g['items'])) continue;
        foreach ($g['items'] as $it) {
            foreach (['question','options','justification','solution'] as $k) {
                if (!isset($it[$k]) || !is_array($it[$k])) continue;
                foreach ($it[$k] as $b) {
                    if (($b['type']??'') !== 'tikz') continue;
                    $s = $b['script'] ?? '';
                    if (!str_contains($s, '\\documentclass')) $issues['no_documentclass']++;
                    if (!str_contains($s, '\\begin{document}')) $issues['no_begindoc']++;
                    if (!str_contains($s, '\\end{document}')) $issues['no_enddoc']++;
                    if (!str_contains($s, '\\begin{tikzpicture}')) $issues['no_tikzpicture']++;
                    if (str_contains($s, '\\usepackage{pgf}') && !str_contains($s, '\\usepackage{tikz}')) $issues['old_pgf_only']++;
                    if (str_contains($s, '\\usepackage{tikz}')) $issues['uses_tikz']++;
                    if (str_contains($s, '\\usepackage{pgf}')) $issues['uses_pgf']++;
                    if (str_contains($s, 'pgfplots')) $issues['uses_pgfplots']++;
                    if (preg_match('#</?(?:p|span|div|b|i|u|br)\b#i', $s)) $issues['has_html_residue']++;
                    if (strlen($s) > 5000) $issues['long_block']++;
                    if (preg_match_all('/\\\\usetikzlibrary\{([^}]+)\}/', $s, $m)) {
                        foreach ($m[1] as $lib) {
                            foreach (preg_split('/\s*,\s*/', $lib) as $l) $lib_uses[$l] = ($lib_uses[$l]??0)+1;
                        }
                        if (count($m[1]) > 1) $issues['multi_useTikz']++;
                    }
                    if (preg_match_all('/\\\\usepackage(?:\[[^\]]*\])?\{([^}]+)\}/', $s, $m)) {
                        foreach ($m[1] as $pkg) {
                            foreach (preg_split('/\s*,\s*/', $pkg) as $p) $pkg_uses[$p] = ($pkg_uses[$p]??0)+1;
                        }
                    }
                }
            }
        }
    }
}
echo "ISSUES:\n";
foreach ($issues as $k=>$v) echo "  $k: $v\n";
echo "\nPACKAGES used (raw \\usepackage):\n";
arsort($pkg_uses);
foreach ($pkg_uses as $k=>$v) echo "  $k: $v\n";
echo "\nTIKZ LIBRARIES (raw \\usetikzlibrary):\n";
arsort($lib_uses);
foreach ($lib_uses as $k=>$v) echo "  $k: $v\n";
