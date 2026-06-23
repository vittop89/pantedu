<?php
declare(strict_types=1);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

foreach ($dirs as $d) {
    foreach (glob($d . '/*.contract.json') as $f) {
        $j = json_decode(file_get_contents($f), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        foreach ($j['groups'] as $gi => $g) {
            if (!isset($g['items'])) continue;
            foreach ($g['items'] as $ii => $it) {
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k])) continue;
                    foreach ($it[$k] as $bi => $b) {
                        if (($b['type'] ?? '') !== 'tikz') continue;
                        $s = $b['script'] ?? '';
                        if (str_contains($s, 'pics/perpquote') || !str_contains($s, 'perpquote=')) continue;
                        // Cerca singole occorrenze di perpquote=...; (terminate da ; o newline o })
                        $cntA = 0; $cntB = 0; $cntC = 0;
                        // Non-regex: split per "perpquote=" e analizza i segmenti
                        $parts = explode('perpquote=', $s);
                        array_shift($parts); // pre-perpquote text
                        foreach ($parts as $p) {
                            // tronca al primo ; o end-of-pic (}; chiusura)
                            $cut = $p;
                            if (($pos = strpos($cut, ';')) !== false) $cut = substr($cut, 0, $pos);
                            // Detect signature
                            $hasArrow    = str_contains($cut, ' arrow ') && str_contains($cut, ' mainstyle ');
                            $hasSlope    = str_contains($cut, ' slope ');
                            if ($hasArrow) $cntC++;
                            elseif ($hasSlope) $cntB++;
                            else $cntA++;
                        }
                        $variants = ($cntA > 0 ? 1 : 0) + ($cntB > 0 ? 1 : 0) + ($cntC > 0 ? 1 : 0);
                        if ($variants > 1) {
                            echo basename($f) . " G$gi I$ii $k[$bi]: A=$cntA B=$cntB C=$cntC\n";
                        }
                    }
                }
            }
        }
    }
}
