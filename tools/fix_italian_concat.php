<?php
/**
 * Fix concatenazione parola_italiana + numero (+ parola) senza spazio.
 *
 * Pattern noti dagli screenshot utente:
 *   - "ogni3giorni"   → "ogni 3 giorni"
 *   - "il30aprile"    → "il 30 aprile"
 *   - "le3signore"    → "le 3 signore"
 *   - "ma0"           → "ma 0"
 *   - "a3,4e6"        → "a 3, 4 e 6" (parziale; il "e6" diventa "e 6")
 *   - "150,110,200;"  → ok (gia' con virgole)
 *
 * Whitelist Italian articles/prepositions/conjunctions per minimizzare falsi
 * positivi su codici tipo "mmb_v1_ed3" o "P-72".
 *
 * Pattern applicati SOLO al type=text (non latex/tikz).
 * Idempotente.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

// Whitelist parole italiane multi-char SOLO lowercase (evita match su "A2"/"E1"
// che possono essere risposte chiave o variabili LaTeX). Single-letter "a"/"e"
// rimossi perche' troppo ambigui.
$ITWORDS = '(?:ogni|il|la|le|gli|lo|del|dei|della|delle|degli|dal|dalla|dai|al|alla|ai|agli|nel|nella|nei|con|sul|sulla|sui|per|tra|fra|però|pero|che|non|sui|ad|ed)';

$totalChanges = 0;
$samples = [];
$itemsTouched = 0;
$filesTouched = [];

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $name = basename($path);
        $fileChanged = false;

        foreach ($j['groups'] as $gi => &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as $ii => &$it) {
                $itemChanged = false;
                foreach (['question','justification','solution'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as $bi => &$b) {
                        if (($b['type'] ?? '') !== 'text') continue;
                        $c = (string)($b['content'] ?? '');
                        $orig = $c;
                        // Skip se il text block contiene LaTeX inline (es.
                        // \documentclass, \begin, \(, \[, $...$). Evita
                        // falsi positivi tipo "border=5mm" → "border=5 mm".
                        if (preg_match('/\\\\(?:documentclass|begin|end|usepackage|[a-z]+|\(|\[)|\$/i', $c)) continue;

                        // Rule 1: word_italian + digit (no space) → add space
                        // Es: "ogni3", "il30", "le3", "ma0", "ed6"
                        $c = preg_replace_callback(
                            '/\b(' . $ITWORDS . ')(\d)/iu',
                            function ($m) { return $m[1] . ' ' . $m[2]; },
                            $c
                        ) ?? $c;

                        // Rule 2: digit + word_italian (no space) → add space
                        // Es: "3giorni" → "3 giorni", "10aprile" → "10 aprile"
                        // Limito alla parola italiana whitelist subito DOPO digit.
                        $c = preg_replace_callback(
                            '/(\d)(' . $ITWORDS . ')\b/iu',
                            function ($m) { return $m[1] . ' ' . $m[2]; },
                            $c
                        ) ?? $c;

                        // Rule 3: digit + lowercase noun italian (frequenti in math contexts)
                        // Es: "3giorni", "30aprile", "11cassette", "20cassette",
                        //     "150kg" → spazio prima dell'unità di misura.
                        // Whitelist sostantivi/unità comuni nelle verifiche.
                        $NOUNS = '(giorni|signore|signori|aprile|maggio|giugno|luglio|agosto|settembre|ottobre|novembre|dicembre|gennaio|febbraio|marzo|cassette|kg|grammi|metri|cm|mm|km|litri|secondi|minuti|ore|anni|mesi|volte|persone|euro|piedi|cubo|quadrato|ragazzi|ragazze|alunni|studenti|risposte)';
                        $c = preg_replace_callback(
                            '/(\d)' . $NOUNS . '\b/iu',
                            function ($m) { return $m[1] . ' ' . $m[2]; },
                            $c
                        ) ?? $c;

                        if ($c !== $orig) {
                            $b['content'] = $c;
                            $totalChanges++;
                            $itemChanged = true;
                            if (count($samples) < 12) {
                                $diff = "$name g$gi i$ii $k.b$bi: '" . substr($orig, 0, 60) . "' → '" . substr($c, 0, 60) . "'";
                                $samples[] = $diff;
                            }
                        }
                    }
                    unset($b);
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $itemsTouched++;
                    $fileChanged = true;
                }
            }
        }

        if ($fileChanged) {
            $filesTouched[] = $name;
            if ($apply) {
                file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "changes: $totalChanges\n";
echo "items: $itemsTouched\n";
echo "files: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nSamples:\n";
foreach ($samples as $s) echo "  $s\n";
