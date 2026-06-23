/**
 * G22.S15 / Phase 1 — Template "schema modulare" (studio del segno).
 *
 * Trasforma un data-model JSON in TikZ string. La definizione del
 * template (preamble + macro) e' fissa; l'utente edita SOLO i dati
 * via form.
 *
 * Data model:
 * {
 *   id: "schema-modulare",
 *   version: 1,
 *   globalParams: {
 *     spacing: 5,
 *     topTextY: 1,
 *     bottomTextPadding: 1,
 *     highlightFill: "red!70",
 *     highlightBorder: "red!40!black",
 *     highlightText: "white",
 *     highlightRadius: "0.2cm",
 *     highlightBorderWidth: "0.4pt"
 *   },
 *   schemas: [
 *     {
 *       xShift: "0",                       // espressione TeX, default = "0" o "\\schemaSpacing*N"
 *       xValues: [                         // colonne (punti notevoli sulla retta)
 *         { pos: 1, value: "$2a$" },
 *         { pos: 2, value: "$0$" },
 *         { pos: 3, value: "$-\\frac{a}{4}$" }
 *       ],
 *       rows: [                            // righe del segno (numeratore, denom, ecc.)
 *         {
 *           y: 0.5,
 *           equation: "$N(x)>0$",
 *           signs: ["$+$", "$+$", "$-$", "$+$"],   // NB: count = xValues + 1
 *           circles: [{ idx: 2, type: "draw" }, { idx: 3, type: "draw" }],
 *           highlights: []                          // indici di colonne da evidenziare con cerchio rosso
 *         },
 *         { y: 1.5, equation: "$D(x)>0$", signs: ["$+$","$+$","$+$","$+$"], circles: [{idx:1,type:"draw"}], highlights: [] },
 *         { y: 2.5, equation: "$\\frac{N(x)}{D(x)}$", signs: ["$+$","$+$","$-$","$+$"], circles: [{idx:1,type:"draw"},{idx:2,type:"draw"},{idx:3,type:"draw"}], highlights: [3] }
 *       ],
 *       solution: {
 *         signs: [],            // ["$+$","$-$",...]: se vuoto, niente riga soluzione
 *         circles: [],          // [{idx, type}]
 *         highlightIdx: [],     // colonne da evidenziare nella riga soluzione
 *         text: ""              // testo opzionale al centro
 *       },
 *       labelAbove: "$\\text{se }a<0$",
 *       labelBelow: "$0<x<-\\dfrac{a}{4}$"
 *     }
 *   ]
 * }
 */

export const TEMPLATE_ID = "schema-modulare";
export const TEMPLATE_VERSION = 1;
export const TEMPLATE_LABEL = "Schema modulare (studio del segno)";

/** Ritorna un nuovo data-model di default (1 schema vuoto + global). */
export function defaultData() {
    return {
        id: TEMPLATE_ID,
        version: TEMPLATE_VERSION,
        globalParams: {
            spacing: 5,
            topTextY: 1,
            bottomTextPadding: 1,
            highlightFill: "red!70",
            highlightBorder: "red!40!black",
            highlightText: "white",
            highlightRadius: "0.2cm",
            highlightBorderWidth: "0.4pt",
        },
        schemas: [defaultSchema(0)],
    };
}

/** Schema con esempio realistico: studio del segno N(x)/D(x).
 *  Pre-popolato cosi' l'utente capisce subito come si compongono i campi:
 *  - 3 colonne (punti notevoli)
 *  - 3 righe (numeratore, denominatore, frazione)
 *  - circles per i punti di discontinuita'/zeri
 *  - highlight su colonna 3 della riga finale
 *  - label sopra/sotto descrittive
 *
 *  Questo default produce un diagramma "leggibile" anche con la dotted-line
 *  posizionata al posto giusto (separatore tra rows[1] e rows[2]). Con UN
 *  SOLO row la dotted-line cadrebbe in alto perche' la sua Y e' calcolata
 *  come midpoint(lastRow, prevRow) e prevRow=0pt → dotted=0.25 → vicino
 *  alla linea orizzontale principale. */
export function defaultSchema(index) {
    let xShift = "0";
    if (index === 1) xShift = "\\schemaSpacing";
    else if (index >= 2) xShift = `${(index * 0.8).toFixed(2)}*\\schemaSpacing`;
    return {
        xShift,
        xValues: [
            { pos: 1, value: "$2a$" },
            { pos: 2, value: "$0$" },
            { pos: 3, value: "$-\\frac{a}{4}$" },
        ],
        rows: [
            { y: 0.5, equation: "$N(x)>0$",                signs: ["$+$", "$+$", "$-$", "$+$"], circles: [{idx:2,type:"draw"}, {idx:3,type:"draw"}], highlights: [] },
            { y: 1.5, equation: "$D(x)>0$",                signs: ["$+$", "$+$", "$+$", "$+$"], circles: [{idx:1,type:"draw"}], highlights: [] },
            { y: 2.5, equation: "$\\frac{N(x)}{D(x)}$",    signs: ["$+$", "$+$", "$-$", "$+$"], circles: [{idx:1,type:"draw"}, {idx:2,type:"draw"}, {idx:3,type:"draw"}], highlights: [3] },
        ],
        solution: { signs: [], circles: [], highlightIdx: [], text: "" },
        labelAbove: "$\\text{se }a<0$",
        labelBelow: "$0<x<-\\dfrac{a}{4}$",
    };
}

// ─────────────────────────────────────────────────────────────────────
// Preamble fisso (i comandi \schemaModulareCore, ecc.). Estratto dal
// codice utente originale. NON viene mai editato.
// ─────────────────────────────────────────────────────────────────────
export const PREAMBLE = String.raw`% ==================================================
% .....PREAMBOLO E LIBRERIE.....
\usepackage{amssymb}
\usepackage{amsmath}
\usepackage{tikz}
\usetikzlibrary{calc}

\makeatletter

\newcount\schemaCountA
\newcount\schemaCountB
\newcount\schemaIndex
\newdimen\schemaDimA
\newdimen\schemaDimB
\newdimen\schemaDimPrev
\newdimen\schemaLastShift
\newdimen\schemaLastWidth
\newdimen\schemaLastRowDim
\newdimen\schemaPrevRowDim
\def\schemaHighlightTarget{}
\def\schemaTempHighlight{}
\def\schemaHighlightListEnd{\relax}

\gdef\schemaLastCenterVal{0}

\newcommand{\schemaTrimSpaces}[2]{%
    \edef#2{\zap@space#1 \@empty}%
}

% G22.S15 — Macro originale schemaIterateHighlights con pattern delimitato
% (#1;#2 schemaHighlightListEnd) ha un bug: gestisce solo SINGOLO valore
% (con multi-valori 1;3 la ricorsione non termina correttamente e il
% match silently fallisce). Lo lasciamo definito per compat ma usiamo
% una versione PGF-foreach (comma separated, stesso pattern della
% sezione highlight colonne soluzione del macro originale, robusta).
\def\schemaIterateHighlights#1;#2\schemaHighlightListEnd{%
    \schemaTrimSpaces{#1}{\schemaTempHighlight}%
    \ifx\schemaTempHighlight\empty\else
        \ifnum\schemaTempHighlight=\schemaHighlightTarget\relax
            \global\def\schemaDoHighlight{1}%
        \fi
    \fi
    \ifx#2\schemaHighlightListEnd\else
        \schemaIterateHighlights#2\schemaHighlightListEnd
    \fi
}

% G22.S15 — \schemaDrawRow disegna UNA singola riga del segno.
% Args:
%   #1 = y (es. 0.5)
%   #2 = equazione (LaTeX, es. "$N(x)>0$")
%   #3 = rowSigns: lista pgf-foreach di segni (es. "{$+$},{$+$},{$-$},{$+$}")
%   #4 = rowCircles: lista comma-sep idx/type (es. "1/draw,3/fill") o vuota
%   #5 = rowHighlights: lista comma-sep di indici da evidenziare (es. "1,3") o vuota
% Usa contatori globali \schemaLastRowDim e \schemaPrevRowDim per dotted-line.
\newcommand{\schemaDrawRow}[5]{%
    \global\schemaPrevRowDim=\schemaLastRowDim
    \schemaDimA=#1 pt
    \global\schemaLastRowDim=\schemaDimA
    \node [left] at (0,-#1) {#2};
    \begingroup
        \edef\schemaTmpCircles{\detokenize{#4}}%
        \if\relax\schemaTmpCircles\relax
        \else
            \foreach \idx/\filltype in {#4} {%
                \schemaTrimSpaces{\idx}{\schemaCircleIdxDet}%
                \schemaTrimSpaces{\filltype}{\schemaCircleCmdDet}%
                \if\relax\schemaCircleIdxDet\relax\else
                    \edef\xVal{\csname tempXPos\schemaCircleIdxDet\endcsname}%
                    \csname\schemaCircleCmdDet\endcsname[line width=0.4mm] (\xVal,-#1) circle (5pt);
                \fi
            }%
        \fi
    \endgroup
    \foreach \sign [count=\schemaTmpIdx] in {#3} {%
        \edef\schemaTempMid{\csname tempMidPos\schemaTmpIdx\endcsname}%
        \gdef\schemaDoHighlight{0}%
        \edef\schemaTmpHL{\detokenize{#5}}%
        \if\relax\schemaTmpHL\relax\else
            % #5 e' un literal token list (passato come argument macro,
            % NO indirezione pgf-foreach), quindi pgf-\foreach lo gestisce
            % correttamente come comma-separated list. Funziona sia "3"
            % single sia "1,3" multi.
            \foreach \hCol in {#5} {%
                \ifnum\hCol=\schemaTmpIdx\relax
                    \global\def\schemaDoHighlight{1}%
                \fi
            }%
        \fi
        \ifnum\schemaDoHighlight=1\relax
            \filldraw[fill=\schemaHighlightFill,draw=\schemaHighlightBorder,line width=\schemaHighlightBorderWidth] (\schemaTempMid,-#1) circle (\schemaHighlightRadius);
            \node[text=\schemaHighlightText] at (\schemaTempMid,-#1) {\Large \sign};
        \else
            \node at (\schemaTempMid,-#1) {\Large \sign};
        \fi
    }%
}

\newcommand{\schemaModulareCore}[7]{%
    \begin{scope}[shift={(#1,0)}]
        \schemaLastRowDim=0pt
        \schemaPrevRowDim=0pt
        \schemaCountA=0
        \foreach \pos/\val in {#2} {
            \global\advance\schemaCountA by 1
            \expandafter\xdef\csname tempXPos\the\schemaCountA\endcsname{\pos}
        }
        \edef\tempNumCols{\the\schemaCountA}
        \edef\tempLastPos{\csname tempXPos\tempNumCols\endcsname}
        \schemaDimA=\tempLastPos pt
        \advance\schemaDimA by 1pt
        \edef\tempLastCol{\strip@pt\schemaDimA}
        \pgfmathparse{#1 + \tempLastCol/2}
        \xdef\schemaLastCenterVal{\pgfmathresult}
        \schemaDimPrev=0pt
        \schemaIndex=1
        \foreach \pos/\val in {#2} {
            \schemaDimA=\pos pt
            \advance\schemaDimA by \schemaDimPrev
            \divide\schemaDimA by 2
            \expandafter\xdef\csname tempMidPos\the\schemaIndex\endcsname{\strip@pt\schemaDimA}
            \global\schemaDimPrev=\pos pt
            \global\advance\schemaIndex by 1
        }
        \schemaDimA=\tempLastCol pt
        \advance\schemaDimA by \schemaDimPrev
        \divide\schemaDimA by 2
        \expandafter\xdef\csname tempMidPos\the\schemaIndex\endcsname{\strip@pt\schemaDimA}
        \draw (0,0) -- (\tempLastCol,0);
        % G22.S15 — il blocco righe arriva come #3 = sequenza di chiamate
        % \schemaDrawRow{...} esplicite (generate dall'assembler).
        % Niente piu' pgf-foreach esterno per evitare bug brace-handling.
        #3
        \edef\tempLastRow{\strip@pt\schemaLastRowDim}
        \pgfmathparse{\tempLastRow + \bottomTextPadding}
        \xdef\bottomTextY{\pgfmathresult}
        \schemaDimA=\schemaLastRowDim
        \advance\schemaDimA by 0.5pt
        \edef\tempVerticalDepth{\strip@pt\schemaDimA}
        \schemaDimA=\schemaLastRowDim
        \advance\schemaDimA by \schemaPrevRowDim
        \divide\schemaDimA by 2
        \edef\tempDottedY{\strip@pt\schemaDimA}
        \foreach \pos/\val in {#2} {
            \draw (\pos,0.2) -- (\pos,-\tempVerticalDepth);
            \node [above] at (\pos,0.2) {\val};
        }
        \draw [dotted] (0,-\tempDottedY) -- (\tempLastCol,-\tempDottedY);
        \foreach \idx/\sign in {#4} {
            \begingroup
                \edef\schemaTempX{\csname tempMidPos\idx\endcsname}
                \gdef\schemaColMatch{0}
                \edef\schemaCheckColHL{\detokenize{#6}}
                \if\relax\schemaCheckColHL\relax\else
                    \foreach \hCol in {#6} {
                        \ifnum\hCol=\idx
                            \global\def\schemaColMatch{1}
                        \fi
                    }
                \fi
                \ifnum\schemaColMatch=1
                     \filldraw[fill=\schemaHighlightFill,draw=\schemaHighlightBorder,line width=\schemaHighlightBorderWidth] (\schemaTempX,-\tempLastRow) circle (\schemaHighlightRadius);
                     \node[text=\schemaHighlightText] at (\schemaTempX,-\tempLastRow) {\Large \sign};
                \else
                     \node at (\schemaTempX,-\tempLastRow) {\Large \sign};
                \fi
            \endgroup
        }
        \foreach \idx/\filltype in {#5} {
            \ifx\filltype\empty\else
                \edef\xVal{\csname tempXPos\idx\endcsname}
                \csname\filltype\endcsname[line width=0.4mm] (\xVal,-\tempLastRow) circle (5pt);
            \fi
        }
        \edef\schemaSolutionTextCheck{\detokenize{#7}}
        \if\relax\schemaSolutionTextCheck\relax
        \else
            \dimen255=\tempLastCol pt
            \divide\dimen255 by 2
            \edef\tempTextX{\strip@pt\dimen255}
            \dimen255=\tempLastRow pt
            \advance\dimen255 by 1pt
            \edef\tempTextY{\strip@pt\dimen255}
            \node [align=center] at (\tempTextX,-\tempTextY) {#7};
        \fi
    \end{scope}
}

\def\schemaModulare#1#2#3{%
    \ignorespaces
    \@ifnextchar\bgroup
        {\schemaModulareWithExtras{#1}{#2}{#3}}%
        {\schemaModulareCore{#1}{#2}{#3}{}{}{}{}}%
}

\def\schemaModulareWithExtras#1#2#3#4#5#6#7{%
    \schemaModulareCore{#1}{#2}{#3}{#4}{#5}{#6}{#7}%
}

\newcommand{\schemaTextAbove}[2]{%
    \node at (\schemaLastCenterVal,#1) {#2};
}
\newcommand{\schemaTextBelow}[2]{%
    \node at (\schemaLastCenterVal,-#1) {#2};
}

\makeatother
`;

// ─────────────────────────────────────────────────────────────────────
// Assembler — pure function: data → TikZ string
// ─────────────────────────────────────────────────────────────────────

/** Genera l'intero TikZ source per i dati forniti.
 *  Output = preamble + \\begin{document}\\begin{tikzpicture} + N schemi + close.
 *  G22.S15.bis — il marker `% __FM_TPL_DATA__:base64(json)` viene appeso DOPO
 *  il preamble (in coda al documento, dopo `\end{document}`) per non
 *  interferire con il wrapper-detector di tikz_render.py che legge le prime
 *  N righe per decidere il caso (documentclass / begin{document} / fragment). */
export function renderTikz(data) {
    const g = data.globalParams;
    const out = [];
    out.push(PREAMBLE);
    out.push("");
    out.push("% ==================================================");
    out.push("% .....DOCUMENTO.....");
    out.push("\\begin{document}");
    out.push("% ==================================================");
    out.push("% .....FIGURA TIKZ.....");
    out.push("\\begin{tikzpicture}");
    out.push("    % === PARAMETRI GLOBALI ===");
    out.push(`    \\def\\schemaSpacing{${g.spacing}}`);
    out.push(`    \\def\\topTextY{${g.topTextY}}`);
    out.push(`    \\def\\bottomTextPadding{${g.bottomTextPadding}}`);
    out.push(`    \\def\\bottomTextY{0}`);
    out.push(`    \\def\\schemaHighlightRadius{${g.highlightRadius}}`);
    out.push(`    \\def\\schemaHighlightFill{${g.highlightFill}}`);
    out.push(`    \\def\\schemaHighlightBorder{${g.highlightBorder}}`);
    out.push(`    \\def\\schemaHighlightText{${g.highlightText}}`);
    out.push(`    \\def\\schemaHighlightBorderWidth{${g.highlightBorderWidth}}`);
    out.push("");

    data.schemas.forEach((schema, idx) => {
        out.push(`    % ============================================================`);
        out.push(`    % SCHEMA ${idx + 1}`);
        out.push(`    % ============================================================`);
        const ordinals = ["first","second","third","fourth","fifth","sixth","seventh","eighth","ninth","tenth"];
        const macroName = `\\${ordinals[idx] || `idx${idx}`}Schema`;
        out.push(`    \\pgfmathsetmacro{${macroName}}{${schema.xShift}}`);
        out.push(`    \\schemaModulare{${macroName}}`);
        out.push(`        {${formatXValues(schema.xValues)}}`);
        out.push(`        {`);
        out.push(formatRows(schema.rows));
        out.push(`        }`);

        const sol = schema.solution || {};
        const hasSolutionExtras = (sol.signs && sol.signs.length) || (sol.circles && sol.circles.length) || (sol.highlightIdx && sol.highlightIdx.length) || (sol.text && sol.text.trim());
        if (hasSolutionExtras) {
            out.push(`        {${formatSolSigns(sol.signs)}}`);
            out.push(`        {${formatCircles(sol.circles)}}`);
            out.push(`        {${(sol.highlightIdx || []).join(",")}}`);
            out.push(`        {${sol.text || ""}}`);
        }
        out.push("");

        if (schema.labelAbove && schema.labelAbove.trim()) {
            out.push(`    \\schemaTextAbove{\\topTextY}{${schema.labelAbove}}`);
        }
        if (schema.labelBelow && schema.labelBelow.trim()) {
            out.push(`    \\schemaTextBelow{\\bottomTextY}{${schema.labelBelow}}`);
        }
        out.push("");
    });

    out.push("\\end{tikzpicture}");
    out.push("\\end{document}");
    // G22.S15.bis — marker round-trip in coda (DOPO \end{document}: pdflatex
    // ignora completamente, ma _extractTemplateData lo trova via regex).
    try {
        const json = JSON.stringify(data);
        const b64 = btoa(unescape(encodeURIComponent(json)));
        out.push(`% __FM_TPL_DATA__:${b64}`);
    } catch (_) { /* best-effort, no marker se data non serializzabile */ }
    return out.join("\n");
}

function formatXValues(xValues) {
    return (xValues || [])
        .map((x) => `${x.pos}/{${x.value}}`)
        .join(", ");
}

function formatRows(rows) {
    // G22.S15 — emette chiamate \schemaDrawRow esplicite (no pgf-foreach
    // outer). Ogni riga e' un'invocazione separata, evita brace-handling
    // bug di pgf-foreach quando highlights contiene la lista comma-separata.
    return (rows || [])
        .map((row) => {
            const signs = (row.signs || []).map((s) => `{${s}}`).join(",");
            const circles = formatCircles(row.circles);
            const highlights = (row.highlights || []).join(",");
            const eq = row.equation || "";
            return `        \\schemaDrawRow{${row.y}}{${eq}}{${signs}}{${circles}}{${highlights}}`;
        })
        .join("\n");
}

function formatCircles(circles) {
    return (circles || [])
        .map((c) => `${c.idx}/${c.type || "draw"}`)
        .join(",");
}

function formatSolSigns(signs) {
    return (signs || [])
        .map((s, i) => `${i + 1}/${s}`)
        .join(",");
}

// ─────────────────────────────────────────────────────────────────────
// Validation — sanity checks prima del render
// ─────────────────────────────────────────────────────────────────────
export function validate(data) {
    const errors = [];
    if (!data || data.id !== TEMPLATE_ID) errors.push("data.id deve essere 'schema-modulare'");
    if (!Array.isArray(data?.schemas) || data.schemas.length === 0) errors.push("almeno uno schema richiesto");
    (data.schemas || []).forEach((s, i) => {
        if (!Array.isArray(s.xValues) || s.xValues.length === 0) errors.push(`schema ${i + 1}: xValues vuoto`);
        if (!Array.isArray(s.rows) || s.rows.length === 0) errors.push(`schema ${i + 1}: rows vuoto`);
        s.rows?.forEach((r, j) => {
            const expected = (s.xValues?.length || 0) + 1;
            if ((r.signs?.length || 0) !== expected) {
                errors.push(`schema ${i + 1} riga ${j + 1}: ${r.signs?.length || 0} segni, attesi ${expected} (xValues+1)`);
            }
        });
    });
    return errors;
}
