<?php
/**
 * Fixer spaziatura per body_html (verifiche legacy con span fm-text/fm-latex/fm-tikz).
 *
 * Logica:
 *   - Trova span.fm-text con data-raw NON terminato da spazio/punteggiatura,
 *     seguito da span.fm-latex/fm-tikz → append space al data-raw + textContent.
 *   - Trova span.fm-text con data-raw NON iniziante da spazio/punteggiatura,
 *     preceduto da span.fm-latex/fm-tikz → prepend space al data-raw + textContent.
 *
 * Lavora su body_html ovunque appaia (item.body_html, options[].body_html, ecc.).
 *
 * Idempotente: non aggiunge se gia' c'e' spazio.
 */
declare(strict_types=1);

$apply = in_array('--apply', $argv, true);
$dir = __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche';
$files = glob($dir . '/*.contract.json');

$totalChanges = 0;
$itemsTouched = 0;
$filesTouched = [];
$samples = [];

function fixBodyHtml(string $html, int &$changes, array &$samples, string $where): string
{
    // Pattern: span con class="fm-X" data-raw="..."
    // Itera con preg_replace_callback per ogni span fm-text successivo a fm-latex/fm-tikz
    // e per ogni fm-text seguito da fm-latex/fm-tikz.
    //
    // Approccio in 2 pass:
    //   Pass 1: text seguito da latex/tikz → append space al text
    //   Pass 2: text preceduto da latex/tikz → prepend space al text

    // Pass 1: <span class="fm-text" data-raw="X"...>X</span><span class="fm-latex"
    $h2 = preg_replace_callback(
        '#(<span class="fm-text" data-raw=")([^"]*)("[^>]*>)([^<]*)(</span>)(<span class="(?:fm-latex|fm-tikz)\b)#u',
        function ($m) use (&$changes, &$samples, $where) {
            $dataRaw = $m[2];
            $textContent = $m[4];
            // Se data-raw vuoto o gia' termina con space/punct → skip
            $dataRawDecoded = htmlspecialchars_decode($dataRaw, ENT_QUOTES);
            if ($dataRawDecoded === '' || preg_match('/[\s\n]$/u', $dataRawDecoded) || preg_match('/[\p{P}]$/u', $dataRawDecoded)) {
                return $m[0];
            }
            // Aggiungi spazio
            $newDataRaw = $dataRaw . ' ';
            $newText = $textContent . ' ';
            $changes++;
            if (count($samples) < 8) {
                $samples[] = "$where [R1 append]: '$dataRawDecoded' → '$dataRawDecoded '";
            }
            return $m[1] . $newDataRaw . $m[3] . $newText . $m[5] . $m[6];
        },
        $html
    ) ?? $html;

    // Pass 2: </span><span class="fm-text" data-raw="X"...>X</span> dopo latex/tikz
    $h3 = preg_replace_callback(
        '#(</span>)(<span class="fm-text" data-raw=")([^"]*)("[^>]*>)([^<]*)(</span>)#u',
        function ($m) use (&$changes, &$samples, $where, $h2) {
            // Bisogna sapere se IL PRECEDENTE span e' fm-latex/fm-tikz.
            // Lo ricostruiamo via lookbehind: un trick e' fare un secondo pass
            // con regex che include lo span precedente.
            // Ma preg_replace_callback non vede contesto fuori dal match.
            // Ritorno il match identico — il vero pass 2 e' fatto sotto.
            return $m[0];
        },
        $h2
    ) ?? $h2;

    // Pass 2 vero: lookbehind con \K? PHP supporta \K per resettare match start.
    // Pattern: span (latex|tikz) ... </span><span fm-text data-raw="X" ...>X</span>
    $h4 = preg_replace_callback(
        '#<span class="(?:fm-latex|fm-tikz)\b[^>]*>.*?</span>\K(<span class="fm-text" data-raw=")([^"]*)("[^>]*>)([^<]*)(</span>)#us',
        function ($m) use (&$changes, &$samples, $where) {
            $dataRaw = $m[2];
            $textContent = $m[4];
            $dataRawDecoded = htmlspecialchars_decode($dataRaw, ENT_QUOTES);
            if ($dataRawDecoded === '' || preg_match('/^[\s\n]/u', $dataRawDecoded) || preg_match('/^[\p{P}]/u', $dataRawDecoded)) {
                return $m[0];
            }
            $newDataRaw = ' ' . $dataRaw;
            $newText = ' ' . $textContent;
            $changes++;
            if (count($samples) < 16) {
                $samples[] = "$where [R2 prepend]: '$dataRawDecoded' → ' $dataRawDecoded'";
            }
            return $m[1] . $newDataRaw . $m[3] . $newText . $m[5];
        },
        $h3
    ) ?? $h3;

    return $h4;
}

function walkAndFix(&$node, string $path, int &$changes, array &$samples): bool {
    $touched = false;
    if (!is_array($node)) return false;
    foreach ($node as $k => &$v) {
        $childPath = $path . '/' . (is_int($k) ? "[$k]" : $k);
        if (is_array($v)) {
            $touched = walkAndFix($v, $childPath, $changes, $samples) || $touched;
        } elseif (is_string($v) && $k === 'body_html') {
            $before = $v;
            $after = fixBodyHtml($v, $changes, $samples, $childPath);
            if ($after !== $before) {
                $v = $after;
                $touched = true;
            }
        }
    }
    return $touched;
}

foreach ($files as $path) {
    $j = json_decode(file_get_contents($path), true);
    if (!is_array($j)) continue;
    $name = basename($path);
    $oldChanges = $totalChanges;

    $touched = walkAndFix($j, $name, $totalChanges, $samples);

    if ($touched) {
        $itemDelta = $totalChanges - $oldChanges;
        $itemsTouched += 1;
        $filesTouched[] = "$name (+$itemDelta)";
        if ($apply) {
            file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "totale changes: $totalChanges\n";
echo "files touched: " . count($filesTouched) . "\n";
foreach ($filesTouched as $f) echo "  - $f\n";
echo "\nsamples:\n";
foreach ($samples as $s) echo "  $s\n";
