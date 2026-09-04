<?php
declare(strict_types=1);
$bak = $argv[1] ?? '';
$cur = $argv[2] ?? '';
if (!$bak || !$cur) { echo "usage: php tikz_diff_body.php <bak> <cur>\n"; exit(1); }

$jBak = json_decode(file_get_contents($bak), true);
$jCur = json_decode(file_get_contents($cur), true);

function collectTikz(array $j): array {
    $out = [];
    foreach ($j['groups'] ?? [] as $g) {
        foreach ($g['items'] ?? [] as $it) {
            foreach (['question','options','solution','justification'] as $k) {
                foreach ($it[$k] ?? [] as $b) {
                    if (($b['type']??'') === 'tikz') $out[] = $b['script'] ?? '';
                }
            }
        }
    }
    return $out;
}

function getBody(string $s): string {
    if (preg_match('/\\\\begin\{document\}(.*?)\\\\end\{document\}/s', $s, $m)) return $m[1];
    return $s;
}

$bakBlocks = collectTikz($jBak);
$curBlocks = collectTikz($jCur);
$diffs = 0;
$total = min(count($bakBlocks), count($curBlocks));
for ($i=0; $i<$total; $i++) {
    $bakBody = trim(preg_replace('/\s+/', ' ', getBody($bakBlocks[$i])));
    $curBody = trim(preg_replace('/\s+/', ' ', getBody($curBlocks[$i])));
    if ($bakBody !== $curBody) {
        $diffs++;
        echo "BLOCK $i differs (bakLen=" . strlen($bakBody) . " curLen=" . strlen($curBody) . "):\n";
        $diffPos = -1;
        for ($j=0; $j<min(strlen($bakBody),strlen($curBody)); $j++) {
            if ($bakBody[$j] !== $curBody[$j]) { $diffPos = $j; break; }
        }
        if ($diffPos < 0) {
            // Una è prefisso dell'altra → confronta tail
            $shorter = strlen($bakBody) < strlen($curBody) ? $bakBody : $curBody;
            $longer  = strlen($bakBody) >= strlen($curBody) ? $bakBody : $curBody;
            $extra = substr($longer, strlen($shorter));
            echo "  prefix-equal — extra in longer: '" . substr($extra, 0, 120) . "'\n";
        } else {
            echo "  pos=$diffPos\n  bak: " . substr($bakBody, max(0,$diffPos-40), 100) . "\n  cur: " . substr($curBody, max(0,$diffPos-40), 100) . "\n";
        }
    }
}
echo "Body diffs: $diffs / $total in " . basename($bak) . "\n";
