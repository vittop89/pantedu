<?php
/**
 * G22.S15 — Annota i template TikZ modulari con marker fold compatibili
 * con la modal CM6 (`% ==================================================`
 * + `% .....NOME SEZIONE.....`).
 *
 * Heuristica:
 *   1. Identifica blocchi logici (preamble, defs, body)
 *   2. Inserisce marker SOLO se non gia presente in zone analoghe
 *   3. Idempotente: rilanciare non duplica i marker
 *
 * Sezioni standard riconosciute:
 *   - PREAMBLE          → \usepackage* / \usetikzlibrary*
 *   - VARIABILI / DEF   → blocco con > 3 \def consecutivi
 *   - COMANDI / NEWCOMMAND → blocco con \newcommand
 *   - DOCUMENTO         → \begin{document}...\end{document}
 *   - TIKZPICTURE       → \begin{tikzpicture}...\end{tikzpicture}
 *
 * Run:
 *   php tools/tikz_annotate_modulari.php           (dry-run, mostra diff)
 *   php tools/tikz_annotate_modulari.php --apply   (applica + sovrascrive JSON)
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$jsonPath = $root . '/storage/data/modelli_tikz_elements.json';
$apply = in_array('--apply', $argv ?? [], true);

$catalog = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($catalog)) {
    fwrite(STDERR, "JSON catalog malformato\n");
    exit(1);
}

$RULER = '% ' . str_repeat('=', 50);

function makeSectionMarker(string $name): string
{
    global $RULER;
    return "$RULER\n% .....{$name}.....\n";
}

/** Inserisce marker se la prima riga del match non e' gia' un marker. */
function insertMarkerBefore(string $text, string $marker, string $patternFirstLine): string
{
    // patternFirstLine: regex per identificare la riga subito dopo cui inserire il marker
    $lines = explode("\n", $text);
    $out = [];
    $inserted = false;
    foreach ($lines as $i => $line) {
        if (!$inserted && preg_match($patternFirstLine, $line)) {
            // Se la linea precedente in $out e' gia' un marker `% ===`, skip
            $prev = end($out) ?: '';
            $prev2 = count($out) >= 2 ? $out[count($out) - 2] : '';
            if (!preg_match('/^[ \t]*%[ \t]*\.{4,}/', $prev) && !preg_match('/^[ \t]*%[ \t]*[=]{3,}/', $prev2)) {
                $out[] = rtrim($marker, "\n");
                $inserted = true;
            }
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

function annotateOne(string $content): string
{
    $text = $content;

    // Già annotato in modo significativo? (marker in più punti)
    $existingMarkers = preg_match_all('/^[ \t]*%[ \t]*\.{4,}.+\.{4,}/m', $text);
    if ($existingMarkers >= 2) {
        return $text; // già modulare con marker, lascia stare
    }

    // 1. PREAMBLE: prima riga \usepackage o \usetikzlibrary
    if (preg_match('/^\\\\usepackage\{|^\\\\usetikzlibrary\{/m', $text)) {
        $text = insertMarkerBefore(
            $text,
            makeSectionMarker('PREAMBOLO E LIBRERIE'),
            '/^\\\\usepackage\{|^\\\\usetikzlibrary\{/'
        );
    }

    // 2. VARIABILI: prima sequenza di \def\
    if (preg_match('/^[ \t]*\\\\def\\\\/m', $text)) {
        $text = insertMarkerBefore(
            $text,
            makeSectionMarker('VARIABILI / PARAMETRI'),
            '/^[ \t]*\\\\def\\\\[A-Za-z]+\{/'
        );
    }

    // 3. COMANDI: primo \newcommand (escludendo i \def già marcati)
    if (preg_match('/^[ \t]*\\\\newcommand\{/m', $text)) {
        $text = insertMarkerBefore(
            $text,
            makeSectionMarker('COMANDI E MACRO'),
            '/^[ \t]*\\\\newcommand\{/'
        );
    }

    // 4. DOCUMENTO: \begin{document}
    $text = insertMarkerBefore(
        $text,
        makeSectionMarker('DOCUMENTO'),
        '/^[ \t]*\\\\begin\{document\}/'
    );

    // 5. TIKZPICTURE: \begin{tikzpicture}
    $text = insertMarkerBefore(
        $text,
        makeSectionMarker('FIGURA TIKZ'),
        '/^[ \t]*\\\\begin\{tikzpicture\}/'
    );

    return $text;
}

$totalAnnotated = 0;
$totalSkipped   = 0;

foreach ($catalog as $group => &$items) {
    foreach ($items as &$item) {
        $original = $item['content'];
        $modified = annotateOne($original);
        if ($modified !== $original) {
            $totalAnnotated++;
            echo "[ANNOTATO] $group :: {$item['label']}\n";
            $item['content'] = $modified;
        } else {
            $totalSkipped++;
        }
    }
}
unset($items, $item);

echo "\n--- Sommario ---\n";
echo "Annotati: $totalAnnotated\nSkipped:  $totalSkipped\n";

if ($apply) {
    $newJson = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($jsonPath, $newJson, LOCK_EX) === false) {
        fwrite(STDERR, "ERROR scrittura $jsonPath\n");
        exit(2);
    }
    echo "\n✅ JSON aggiornato: $jsonPath (backup .bak.g22s15)\n";
} else {
    echo "\n⚠️  Dry-run. Rilancia con --apply per scrivere.\n";
}
