<?php
/** Dump tutti i blocchi di un item per review semantica. */
declare(strict_types=1);
$path = __DIR__ . '/../storage/objects/' . ($argv[1] ?? '');
$gIdx = (int)($argv[2] ?? 0);
$iIdx = (int)($argv[3] ?? 0);
$j = json_decode(file_get_contents($path), true);
$g = $j['groups'][$gIdx];
$it = $g['items'][$iIdx];
echo "FILE: " . basename($path) . "\n";
echo "GROUP $gIdx: \"" . ($g['title'] ?? '?') . "\" type=" . ($g['type'] ?? '?') . "\n";
echo "ITEM $iIdx:\n";
echo "  badge=" . json_encode($it['badge'] ?? null) . "\n";
foreach (['question','options','justification','solution'] as $k) {
    if (empty($it[$k])) continue;
    echo "[$k]:\n";
    if ($k === 'options') {
        foreach ($it[$k] as $oi => $opt) {
            echo "  opt$oi letter=" . ($opt['letter'] ?? '?') . " correct=" . var_export($opt['correct'] ?? null, true) . "\n";
            foreach (($opt['content'] ?? []) as $cbi => $cb) {
                $type = is_array($cb) ? ($cb['type'] ?? '?') : 'string';
                $cont = is_array($cb) ? ($cb['content'] ?? '') : (string)$cb;
                echo "    .$cbi (" . $type . "): [" . str_replace("\n","↵",substr((string)$cont,0,140)) . "]\n";
            }
        }
    } else {
        foreach ($it[$k] as $bi => $b) {
            $cont = (string)($b['content'] ?? '');
            echo "  $bi (" . ($b['type'] ?? '?') . "): [" . str_replace("\n","↵",substr($cont,0,180)) . "]\n";
        }
    }
}
