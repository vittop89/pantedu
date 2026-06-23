<?php
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
$path = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json';
$j = json_decode(file_get_contents($path), true);
$it = &$j['groups'][2]['items'][0];

foreach ($it['justification'] as $bi => &$b) {
    if ($b['type'] !== 'text') continue;
    $old = $b['content'];
    // Sostituisci sequenze \n+\s*\n+ (più newline + opzionali spazi/newline) con uno spazio.
    // Caso tipico: "(vero)\n\n \nb." → "(vero) b."
    $new = preg_replace('/\n+\s*\n+/u', ' ', $old) ?? $old;
    // Collassa multi-spazi (eventuale residuo)
    $new = preg_replace('/ {2,}/', ' ', $new) ?? $new;
    if ($new !== $old) {
        echo "j$bi: BEFORE [" . str_replace("\n", "\\n", $old) . "]\n";
        echo "j$bi: AFTER  [$new]\n---\n";
        $b['content'] = $new;
    }
}
unset($b);

if (isset($it['body_html'])) unset($it['body_html']);

if ($apply) {
    file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "APPLIED\n";
} else {
    echo "DRY-RUN (use --apply)\n";
}
