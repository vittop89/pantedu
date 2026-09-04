<?php
/** Fix mirato per i 3 item delle screenshot utente. */
declare(strict_types=1);
$apply = in_array('--apply', $argv, true);
$path = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche/MAT-Numeri_naturali_e_interi-ver.contract.json';
$j = json_decode(file_get_contents($path), true);

$logs = [];

// ============================================
// ITEM 1: g=6 i=0 — P-69 "Calcola valore espressione per a=-2"
// ============================================
// QUESTION fix: aggiungere \n\n dopo "." (q.b2) per separare l'espressione math
//               aggiungere \n\n dopo l'espressione (q.b3) per separare la 2a domanda
$it = &$j['groups'][6]['items'][0];
// q.b2 = "." → ".\n\n"
$it['question'][2]['content'] = ".\n\n";
// q.b4 = " Se sostituissimo il valore opposto," → "\n\nSe sostituissimo..."
$it['question'][4]['content'] = "\n\nSe sostituissimo il valore opposto, ";
$logs[] = "ITEM 1 question: aggiunti \\n\\n attorno espressione math";

// SOLUTION fix:
// s0,s1,s2 sono 3 step latex consecutivi → inserisci text "\n\n" tra ognuno
// Tra s6 (-18) e s7 (" per a=2 non cambia") → cambia s7 in "\n\nper a=2..."
$origSol = $it['solution'];
$newSol = [];
foreach ($origSol as $idx => $b) {
    // Inserisci separator tra latex consecutivi (s0,s1,s2)
    if ($idx > 0 && $idx <= 3) {
        $prevType = $origSol[$idx - 1]['type'] ?? '';
        $curType = $b['type'] ?? '';
        if ($prevType === 'latex' && $curType === 'latex') {
            $newSol[] = ['type' => 'text', 'content' => "\n\n"];
        }
    }
    $newSol[] = $b;
}
// Ora trovo " per " → "\n\nper " nei text che seguono i latex di valutazione (s7 nel new)
foreach ($newSol as $idx => &$b) {
    if (($b['type'] ?? '') !== 'text') continue;
    $c = $b['content'] ?? '';
    if (str_starts_with($c, ' per ') && str_contains($c, 'non cambia')) {
        $b['content'] = "\n\nper " . substr($c, 5);
        $logs[] = "ITEM 1 solution: separatore \\n\\n prima di 'per a=2'";
    }
}
unset($b);
$it['solution'] = $newSol;
unset($it['body_html']);

// ============================================
// ITEM 2: g=7 i=3 — Cartoncini
// ============================================
$it2 = &$j['groups'][7]['items'][3];
// solution s1: ": numero di cartoncini con il numero 4." → aggiungi "\n" alla fine per separare da y
$it2['solution'][1]['content'] = ": numero di cartoncini con il numero 4.\n";
// s3: ": numero di cartoncini con il numero 5." → "\n\n" prima del sistema (s4)
$it2['solution'][3]['content'] = ": numero di cartoncini con il numero 5.\n\n";
// s5: " Ricavo y dalla " → "\n\nRicavo y dalla "
$it2['solution'][5]['content'] = "\n\nRicavo y dalla ";
// s9: ":" → ":\n\n" (prima della step 2 sistema)
$it2['solution'][9]['content'] = ":\n\n";
// s11: " Semplifico la " → "\n\nSemplifico la "
$it2['solution'][11]['content'] = "\n\nSemplifico la ";
// s13: ":" → ":\n\n"
$it2['solution'][13]['content'] = ":\n\n";
// s15: " Da quest'ultima relazione..." → "\n\nDa quest'ultima..."
$it2['solution'][15]['content'] = "\n\nDa quest'ultima relazione si può dedurre che il valore di ";
// s17: " non può essere più grande di 90." → " non può essere più grande di 90.\n\n"
$it2['solution'][17]['content'] = " non può essere più grande di 90.\n\n";
// s19: " Al posto della " → "\n\nAl posto della "
$it2['solution'][19]['content'] = "\n\nAl posto della ";
unset($it2['body_html']);
$logs[] = "ITEM 2 cartoncini: inseriti 8 separatori \\n\\n tra steps logici";

// ============================================
// ITEM 3: g=7 i=5 — Espressione con tabella (a, b → -12, 2)
// ============================================
$it3 = &$j['groups'][7]['items'][5];
// Question s10: " è il minore, calcola:" → " è il minore, calcola:\n\n"
$it3['question'][10]['content'] = " è il minore, calcola:\n\n";
// Question s12: "." → ".\n"
$it3['question'][12]['content'] = ".\n";
// Solution s4: " a partire dalle possibili combinazioni per il prodotto:"
//                → " a partire dalle possibili combinazioni per il prodotto:\n\n"
$it3['solution'][4]['content'] = " a partire dalle possibili combinazioni per il prodotto:\n\n";
// Solution s6: " Gli unici valori sono " → "\n\nGli unici valori sono "
$it3['solution'][6]['content'] = "\n\nGli unici valori sono ";
// Solution s10: ". Sostituendo:" → ".\n\nSostituendo:\n\n"
$it3['solution'][10]['content'] = ".\n\nSostituendo:\n\n";
unset($it3['body_html']);
$logs[] = "ITEM 3 tabella: separatori frase introduttiva ↔ tabella ↔ steps";

if ($apply) {
    file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "APPLIED\n";
} else {
    echo "DRY-RUN\n";
}
foreach ($logs as $l) echo "  - $l\n";
