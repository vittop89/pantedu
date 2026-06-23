<?php
declare(strict_types=1);
/**
 * tikz_inject_macros.php — inietta le definizioni di macro/pic custom
 * (perpquote, schemaModulare family) nei blocchi TikZ che le USANO ma non
 * le definiscono. Senza queste definizioni, pdflatex va in compile error.
 *
 * Macros gestite:
 *   1. perpquote (pic con .style args)
 *      - Sig A (5 args)
 *      - Sig B (6 args, slope)
 *      - Sig C (8 args, slope+arrow+mainstyle)
 *      Inietta la signature giusta in base al numero di "to/dist/label/pos/..."
 *      keywords presenti nelle chiamate.
 *
 *   2. schemaModulare / schemaTextAbove / schemaTextBelow
 *      - Inietta la sezione \makeatletter...\makeatother estratta dal preamble
 *        di js/modules/editor/tikz-templates/schema-modulare.js.
 *
 * Idempotente: re-run non duplica nulla (controlla pics/perpquote /
 * \schemaModulareCore prima di iniettare).
 *
 * Usage: php tools/tikz_inject_macros.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$dirs = [
    __DIR__ . '/../storage/objects/institutes/106/private/77/eser',
    __DIR__ . '/../storage/objects/institutes/106/private/77/verifiche',
];

// Estrai PREAMBLE schemaModulare da JS
$schemaPreamble = extractSchemaPreamble();
$sistemaPreamble = schemaSistemaPreamble();

$total = 0;
$injPerpA = 0; $injPerpB = 0; $injPerpC = 0;
$injSchema = 0;
$injSistema = 0;

foreach ($dirs as $dir) {
    foreach (glob($dir . '/*.contract.json') as $path) {
        $j = json_decode(file_get_contents($path), true);
        if (!is_array($j) || empty($j['groups'])) continue;
        $fileChanged = false;
        foreach ($j['groups'] as &$g) {
            if (!isset($g['items']) || !is_array($g['items'])) continue;
            foreach ($g['items'] as &$it) {
                $itemChanged = false;
                foreach (['question','options','solution','justification'] as $k) {
                    if (!isset($it[$k]) || !is_array($it[$k])) continue;
                    foreach ($it[$k] as &$b) {
                        if (($b['type']??'') !== 'tikz') continue;
                        $total++;
                        $orig = $b['script'] ?? '';
                        $new = $orig;

                        // perpquote injection
                        $new = injectPerpquote($new, $injPerpA, $injPerpB, $injPerpC);
                        // schemaModulare injection
                        $new = injectSchemaModulare($new, $schemaPreamble, $injSchema);
                        // schemaSistema injection (legacy family)
                        $new = injectSchemaSistema($new, $sistemaPreamble, $injSistema);

                        if ($new !== $orig) {
                            $b['script'] = $new;
                            $itemChanged = true;
                        }
                    }
                    unset($b);
                }
                if ($itemChanged) {
                    if (isset($it['body_html'])) unset($it['body_html']);
                    $fileChanged = true;
                }
            }
            unset($it);
        }
        unset($g);
        if ($fileChanged && $apply) {
            file_put_contents($path, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        }
    }
}

echo "=== " . ($apply ? "APPLY" : "DRY-RUN") . " ===\n";
echo "Total tikz blocks scanned: $total\n";
echo "perpquote (Sig A 5-args) injected: $injPerpA\n";
echo "perpquote (Sig B 6-args) injected: $injPerpB\n";
echo "perpquote (Sig C 8-args) injected: $injPerpC\n";
echo "schemaModulare preamble injected: $injSchema\n";
echo "schemaSistema  preamble injected: $injSistema\n";

// ─────────────────────── implementazione ──────────────────────────────────

function extractSchemaPreamble(): string {
    $js = file_get_contents(__DIR__ . '/../js/modules/editor/tikz-templates/schema-modulare.js');
    if (!preg_match('/PREAMBLE = String\.raw`(.+?)`;/s', $js, $m)) {
        throw new RuntimeException('schema-modulare.js PREAMBLE non trovato');
    }
    $full = $m[1];
    // Estrai solo la sezione \makeatletter ... \makeatother
    if (!preg_match('/(\\\\makeatletter[\\s\\S]+?\\\\makeatother)/u', $full, $mm)) {
        throw new RuntimeException('schema-modulare.js makeatletter section non trovata');
    }
    return $mm[1];
}

function injectPerpquote(string $s, int &$cntA, int &$cntB, int &$cntC): string {
    // Già definito? skip.
    if (str_contains($s, 'pics/perpquote')) return $s;
    // Usa perpquote? altrimenti skip.
    if (!str_contains($s, 'perpquote=')) return $s;

    // Detect signature usata. Le chiamate hanno keywords delimitatori:
    //   perpquote=A to B dist 0 label {$x$} pos above [slope sloped] [arrow <-> mainstyle solid]
    // Strategia: split per "perpquote=" e analizza ogni call (taglia a ;).
    // Tutte le call dentro lo stesso blocco usano la stessa signature
    // (verificato: 0 blocchi misti) → prendiamo la prima.
    $parts = explode('perpquote=', $s);
    array_shift($parts);
    $hasArrow = false; $hasSlope = false;
    foreach ($parts as $p) {
        $cut = $p;
        if (($pos = strpos($cut, ';')) !== false) $cut = substr($cut, 0, $pos);
        if (str_contains($cut, ' arrow ') && str_contains($cut, ' mainstyle ')) { $hasArrow = true; break; }
        if (str_contains($cut, ' slope ')) $hasSlope = true;
    }

    if ($hasArrow) {
        $def = perpquoteDefC();
        $cntC++;
    } elseif ($hasSlope) {
        $def = perpquoteDefB();
        $cntB++;
    } else {
        $def = perpquoteDefA();
        $cntA++;
    }
    // Injekt la def DOPO il preamble (\usetikzlibrary) e PRIMA di \begin{document}.
    return injectBeforeDocument($s, $def);
}

function injectSchemaModulare(string $s, string $preamble, int &$cnt): string {
    // Skip se il blocco usa schema-sistema family (gestito da injectSchemaSistema).
    $usesSistema = preg_match('/[^a-zA-Z]\\\\schema(?:Sistema|Row)\\b/u', $s) === 1;
    if ($usesSistema) return $s;
    $usesSchema = preg_match('/\\\\schema(?:Modulare|TextAbove|TextBelow|DrawRow|ModulareCore)\\b/u', $s) === 1;
    if (!$usesSchema) return $s;
    // Già contiene la def?
    if (str_contains($s, '\\schemaModulareCore')) return $s;
    $cnt++;
    // Assicura \usetikzlibrary{calc} (richiesta da schemaModulare)
    if (preg_match('/\\\\usetikzlibrary\{([^}]+)\}/u', $s, $m)) {
        $libs = array_map('trim', preg_split('/\s*,\s*/', $m[1]));
        if (!in_array('calc', $libs, true)) {
            $libs[] = 'calc';
            sort($libs);
            $s = preg_replace('/\\\\usetikzlibrary\{[^}]+\}/u', '\\usetikzlibrary{' . implode(',', $libs) . '}', $s, 1);
        }
    } else {
        // Add usetikzlibrary line right after \usepackage{tikz}
        $s = preg_replace('/(\\\\usepackage\{tikz\})/u', "$1\n\\usetikzlibrary{calc}", $s, 1);
    }
    return injectBeforeDocument($s, $preamble);
}

function injectSchemaSistema(string $s, string $preamble, int &$cnt): string {
    $usesSistema = preg_match('/[^a-zA-Z]\\\\schema(?:Sistema|Row)\\b/u', $s) === 1;
    if (!$usesSistema) return $s;
    if (str_contains($s, '\\schemaSistema#')) return $s; // già definito
    if (str_contains($s, '\\long\\def\\schemaSistema')) return $s;
    $cnt++;
    return injectBeforeDocument($s, $preamble);
}

function injectBeforeDocument(string $s, string $def): string {
    if (str_contains($s, '\\begin{document}')) {
        return preg_replace(
            '/(\\\\begin\{document\})/u',
            $def . "\n$1",
            $s,
            1
        );
    }
    return $def . "\n" . $s;
}

function schemaSistemaPreamble(): string {
    return <<<'TEX'
\makeatletter
\gdef\schemaLastCenterVal{0}
\newcount\schemaRowCount

\long\def\schemaDrawRow#1#2#3{%
    \node [left] at (0,-#1) {#2};
    \foreach \startIdx/\startFill/\endIdx/\endFill/\lineType/\startPt/\endPt/\mycolor in {#3} {
        \pgfmathtruncatemacro{\startIdxNum}{\startIdx}
        \ifnum\startIdxNum=0
            \def\startX{0}
        \else
            \ifnum\startIdxNum>\maxColIdx
                \edef\startX{\lastCol}
            \else
                \edef\startX{\csname xPos\startIdx\endcsname}
            \fi
        \fi
        \pgfmathtruncatemacro{\endIdxNum}{\endIdx}
        \ifnum\endIdxNum>0
            \ifnum\endIdxNum>\maxColIdx
                \edef\endX{\lastCol}
            \else
                \edef\endX{\csname xPos\endIdx\endcsname}
            \fi
        \else
            \edef\endX{\lastCol}
        \fi
        \ifnum\startIdxNum>0
            \ifx\startFill\empty\else
                \csname\startFill\endcsname [color=\mycolor] (\startX,-#1) [line width=0.4mm] circle (\startPt);
            \fi
        \fi
        \draw [\lineType, line width=0.8mm, color=\mycolor] (\startX,-#1) -- (\endX,-#1);
        \ifnum\endIdxNum>0
            \ifx\endFill\empty\else
                \csname\endFill\endcsname [color=\mycolor] (\endX,-#1) [line width=0.4mm] circle (\endPt);
            \fi
        \fi
        \edef\tempcolor{\mycolor}
        \def\redColor{red}
        \ifx\tempcolor\redColor
            \draw [\lineType, line width=1mm, red] (\startX,-#1) -- (\endX,-#1);
        \fi
    }
}

\long\def\schemaSistema#1#2#3#4{%
    \begin{scope}[shift={(#1,0)}]
        \def\rowBase{0.5}
        \def\rowStep{1}
        \foreach [count=\colIdx] \pos/\val in {#2} {
            \expandafter\xdef\csname xPos\colIdx\endcsname{\pos}
            \xdef\lastPos{\pos}
            \xdef\maxColIdx{\colIdx}
        }
        \schemaRowCount=0\relax
        \long\def\schemaRow##1##2{\advance\schemaRowCount by 1\relax}
        #3
        \pgfmathsetmacro{\rowsCount}{\the\schemaRowCount}
        \pgfmathsetmacro{\yLast}{\rowBase + (\rowsCount - 1)}
        \pgfmathsetmacro{\hl}{\yLast + 0.5}
        \pgfmathsetmacro{\dottedY}{\yLast - 0.5}
        \pgfmathsetmacro{\solY}{\yLast + 1}
        \pgfmathsetmacro{\lastCol}{\lastPos + 1}
        \pgfmathparse{#1 + \lastCol/2}
        \xdef\schemaLastCenterVal{\pgfmathresult}
        \draw (0,0) -- (\lastCol,0);
        \foreach \x/\valore in {#2} {
            \draw (\x,0.2) -- (\x,-\hl);
            \node [above] at (\x,0.2) {\valore};
        }
        \schemaRowCount=0\relax
        \long\def\schemaRow##1##2{%
            \advance\schemaRowCount by 1\relax
            \pgfmathsetmacro{\yRow}{\rowBase + (\the\schemaRowCount - 1)}
            \schemaDrawRow{\yRow}{##1}{##2}%
        }
        #3
        \draw [dotted] (0,-\dottedY) -- (\lastCol,-\dottedY);
        \node [align=center] at (\lastCol/2,-\solY) {#4};
    \end{scope}
}

\newcommand{\schemaTextAbove}[2]{%
    \node at (\schemaLastCenterVal,#1) {#2};
}
\newcommand{\schemaTextBelow}[2]{%
    \node at (\schemaLastCenterVal,-#1) {#2};
}
\makeatother
TEX;
}

function perpquoteDefA(): string {
    return <<<'TEX'
\tikzset{
  pics/perpquote/.style args={#1 to #2 dist #3 label #4 pos #5}{
    code={
      \path let \p1 = (#1), \p2 = (#2),
                \n1 = {veclen(\x2-\x1,\y2-\y1)},
                \n2 = {#3}
            in
        coordinate (qstart) at ($ (#1) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $)
        coordinate (qend)   at ($ (#2) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $);
      \draw[dashed, ultra thin] (#1) -- (qstart);
      \draw[dashed, ultra thin] (#2) -- (qend);
      \draw[<->, >=stealth] (qstart) -- (qend) node[midway, #5] {#4};
    }
  }
}
TEX;
}

function perpquoteDefB(): string {
    return <<<'TEX'
\tikzset{
  pics/perpquote/.style args={#1 to #2 dist #3 label #4 pos #5 slope #6}{
    code={
      \path let \p1 = (#1), \p2 = (#2),
                \n1 = {veclen(\x2-\x1,\y2-\y1)},
                \n2 = {#3}
            in
        coordinate (qstart) at ($ (#1) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $)
        coordinate (qend)   at ($ (#2) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $);
      \draw[dashed, ultra thin] (#1) -- (qstart);
      \draw[dashed, ultra thin] (#2) -- (qend);
      \draw[<->, >=stealth] (qstart) -- (qend) node[midway, #5, #6] {#4};
    }
  }
}
TEX;
}

function perpquoteDefC(): string {
    return <<<'TEX'
\tikzset{
  pics/perpquote/.style args={#1 to #2 dist #3 label #4 pos #5 slope #6 arrow #7 mainstyle #8}{
    code={
      \path let \p1 = (#1), \p2 = (#2),
                \n1 = {veclen(\x2-\x1,\y2-\y1)},
                \n2 = {#3}
            in
        coordinate (qstart) at ($ (#1) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $)
        coordinate (qend)   at ($ (#2) + ({\n2 * (\y1-\y2)/\n1}, {\n2 * (\x2-\x1)/\n1}) $);
      \draw[dashed] (#1) -- (qstart);
      \draw[dashed] (#2) -- (qend);
      \draw[#7, #8] (qstart) -- (qend) node[midway, #5, #6] {#4};
    }
  }
}
TEX;
}
