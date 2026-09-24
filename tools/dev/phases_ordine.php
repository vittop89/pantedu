<?php

declare(strict_types=1);

// Ordine naturale delle chiavi phase, per segmenti: in ogni segmento prima la
// parte numerica iniziale (come numero), poi il resto (come testo); un segmento
// senza numero iniziale viene dopo quelli che ce l'hanno.
//
// 24/9/2026 — il confronto di prima non era un ordine: «10» e «9» si
// confrontavano come numeri, «11b» e «9» come testo, e ne usciva il ciclo
// 24.9 < 24.10 < 24.11b < 24.9. Con un ciclo il risultato dipende
// dall'algoritmo: PHP 8.3 (in locale) e 8.4 (in CI) mettevano 24.10 e 24.11b
// in ordine diverso, e il controllo dei documenti generati andava in rosso a
// ogni modifica dei marker.
//
// Sta in un file a sé perché tools/dev/gen_phases_md.php è uno script: la
// prova (tests/Unit/Dev/OrdineDellePhaseTest.php) chiama la funzione da qui.
function confrontaPhase(string|int $a, string|int $b): int
{
    $pa = explode('.', (string)$a);
    $pb = explode('.', (string)$b);
    for ($i = 0; $i < max(count($pa), count($pb)); $i++) {
        $x = $pa[$i] ?? '';
        $y = $pb[$i] ?? '';
        preg_match('/^(\d*)(.*)$/s', $x, $mx);
        preg_match('/^(\d*)(.*)$/s', $y, $my);
        $nx = $mx[1] ?? '';
        $ny = $my[1] ?? '';
        if ($nx !== '' && $ny === '') return -1;
        if ($nx === '' && $ny !== '') return 1;
        if ($nx !== '' && (int)$nx !== (int)$ny) return (int)$nx <=> (int)$ny;
        $c = strcmp($mx[2] ?? '', $my[2] ?? '');
        if ($c !== 0) return $c;
    }
    return 0;
}
