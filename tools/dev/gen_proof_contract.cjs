/* DEV: genera il contract JSON ricco per la copia 1293 (PROOF).
 * Struttura: 3 gruppi (problem-group / type_Collect), ogni quesito con:
 *  - testo formattato (grassetto/corsivo/sottolineato + combinazioni)
 *  - elenco NESTED; ogni riga (anche annidata) con un TikZ + un GeoGebra
 *  - un TikZ COMPLESSO per gruppo
 * Output: storage/objects/institutes/106/private/77/esercizi/MAT/7-proofcopy.contract.json
 */
const fs = require("fs");
// Override opzionali via env (default = copia di lavoro 1293 usata dai test):
//   CONTRACT_OUT   path file di output
//   CONTRACT_ID    id interno del contract
//   CONTRACT_TOPIC topic dello scope
//   CONTRACT_TITLE titolo
const PATH = process.env.CONTRACT_OUT || "storage/objects/institutes/106/private/77/esercizi/MAT/7-proofcopy.contract.json";

let uid = 1000;
const id = () => "proof-" + (uid++).toString(36) + "-" + (uid * 7 % 9973);

// ── blocchi ──
const T = (content) => ({ type: "text", content });
const L = (content) => ({ type: "latex", content });
const TIKZ = (script) => ({ type: "tikz", script });
const GGB = (svg, label) => ({ type: "geogebra", ggb_b64: "UEsDBBQACAgIAAAAIQ==", label, width: "6cm", svg });
const LIST = (preset, items) => ({ type: "list", ordered: preset.startsWith("ol"), list_preset: preset, start: 1, items });

// SVG GeoGebra preset (piano cartesiano + curva); variabile per seme
const ggbSvg = (kind) => {
    const curves = {
        parabola: '<path d="M50,150 Q110,-10 200,150" fill="none" stroke="#1565c0" stroke-width="2"/>',
        retta: '<line x1="40" y1="160" x2="200" y2="30" stroke="#2e7d32" stroke-width="2"/>',
        seno: '<path d="M20,90 C50,20 80,160 110,90 C140,20 170,160 200,90" fill="none" stroke="#6a1b9a" stroke-width="2"/>',
    };
    return `<svg xmlns="http://www.w3.org/2000/svg" width="220" height="180" viewBox="0 0 220 180"><rect width="220" height="180" fill="#fff"/><line x1="10" y1="90" x2="210" y2="90" stroke="#888"/><line x1="40" y1="10" x2="40" y2="170" stroke="#888"/>${curves[kind] || curves.parabola}<text x="200" y="86" font-size="11">x</text><text x="46" y="20" font-size="11">y</text></svg>`;
};

const tikzSimple = (n) => `\\begin{tikzpicture}[scale=0.7]\\draw[thick,fill=blue!10] (0,0)--(2,0)--(1,1.6)--cycle;\\node[scale=.7] at (1,-0.35) {fig.${n}};\\end{tikzpicture}`;

const tikzComplex = (variant) => {
    const fns = {
        1: { plot: "{\\x*\\x}", dash: "{0.5*\\x*\\x+0.5}", lbl: "$y=x^2$" },
        2: { plot: "{0.5*\\x*\\x*\\x}", dash: "{2*\\x}", lbl: "$y=\\tfrac12 x^3$" },
        3: { plot: "{abs(\\x)}", dash: "{-abs(\\x)+3}", lbl: "$y=|x|$" },
    }[variant] || { plot: "{\\x*\\x}", dash: "{\\x}", lbl: "$y=x^2$" };
    return `\\begin{tikzpicture}[scale=1.0]
\\draw[->,gray] (-3,0)--(3.2,0) node[right]{$x$};
\\draw[->,gray] (0,-1.2)--(0,4) node[above]{$y$};
\\foreach \\x in {-2,-1,1,2} \\draw (\\x,0.06)--(\\x,-0.06) node[below,scale=.7]{$\\x$};
\\foreach \\y in {1,2,3} \\draw (0.06,\\y)--(-0.06,\\y) node[left,scale=.7]{$\\y$};
\\draw[domain=-1.9:1.9,smooth,very thick,blue] plot (\\x,${fns.plot});
\\draw[domain=-1.9:1.9,smooth,thick,red,dashed] plot (\\x,${fns.dash});
\\fill (0,0) circle (2pt) node[below right,scale=.8]{$O$};
\\node[blue] at (2.2,3.5) {${fns.lbl}};
\\end{tikzpicture}`;
};

// testo con TUTTE le combinazioni di formattazione
const fmt = "Risolvi <strong>in grassetto</strong>, <em>in corsivo</em>, <u>sottolineato</u>, " +
    "<strong><em>grassetto+corsivo</em></strong>, <strong><u>grassetto+sottolineato</u></strong>, " +
    "<em><u>corsivo+sottolineato</u></em> e <strong><em><u>tutti e tre insieme</u></em></strong>.";

// Lista con preset interno renderer (computeListMarker) + ordered esplicito.
const LISTP = (preset, ordered, items) => ({ type: "list", ordered, list_preset: preset, start: 1, items });

// contenuto di UNA riga di elenco: testo formattato + TikZ + GeoGebra (+ extra).
function leaf(label, kind, n, extra) {
    // NB: math INLINE nel testo (\( … \)) — non un blocco latex separato — per
    // testare il restore del sorgente nei .fm-text annidati (vedi
    // restoreLatexSourceInClone). MathJax lo tipesetta a glifi; l'estrazione
    // deve riemettere il sorgente, non i glifi.
    const b = [T(`<strong>${label}</strong> — ${fmt} Verifica \\(\\sqrt{x^2+1}\\).`), TIKZ(tikzSimple(n)), GGB(ggbSvg(kind), "Grafico " + n)];
    if (extra) b.push(...extra);
    return b;
}

// Elenco a TRE livelli annidati (preset L1/L2/L3 dalla toolbar): ogni riga di
// OGNI livello ha un TikZ + un GeoGebra. preset = [p1, p2, p3].
function nested3(preset, n, kinds) {
    const [p1, p2, p3] = preset;
    const lvl3 = (m) => LISTP(p3, true, [
        leaf(`Sotto-sotto-punto ${m}.i`, kinds[2], m + "i"),
        leaf(`Sotto-sotto-punto ${m}.ii`, kinds[0], m + "ii", [L("\\(\\sqrt{x^2+1}\\)")]),
    ]);
    const lvl2 = (m) => LISTP(p2, true, [
        [...leaf(`Sotto-punto ${m}.a`, kinds[1], m + "a"), lvl3(m + "a")],
        [...leaf(`Sotto-punto ${m}.b`, kinds[2], m + "b"), lvl3(m + "b")],
    ]);
    return LISTP(p1, true, [
        [...leaf("Funzione 1", kinds[0], n + "1"), lvl2(n + "1")],
        [...leaf("Funzione 2", kinds[1], n + "2"), lvl2(n + "2")],
    ]);
}

function quesito(qLabel, preset, kinds, complexVariant, n) {
    return {
        id: id(), difficulty: 0, tags: [], category_label: "", category_color: null,
        source: "", origin: "personal", color: "white",
        question: [
            T(`<strong>${qLabel}</strong>: studia le seguenti funzioni con <em>elenco annidato a tre livelli</em>.`),
            nested3(preset, n, kinds),
            T("<u>TikZ complesso del gruppo</u> (grafico comparato):"),
            TIKZ(tikzComplex(complexVariant)),
        ],
        justification: [T("Giustifica i passaggi con <em>riferimenti</em> ai grafici.")],
        body_html: "",
        solution: [T(`Soluzione del <strong>${qLabel}</strong>: vedi grafici.`), L("\\(x = \\dfrac{-b\\pm\\sqrt{b^2-4ac}}{2a}\\)")],
    };
}

const group = (title, intro, quesiti) => ({
    kind: "problem-group", type: "type_Collect", title, intro, id: id(),
    items: quesiti,
});

const contract = {
    $schema: "pantedu.content.v1",
    id: process.env.CONTRACT_ID ? Number(process.env.CONTRACT_ID) : 1293,
    kind: "esercizio",
    title: process.env.CONTRACT_TITLE || "PROOF-COPY di 1291 (test toolbar)",
    scope: { indirizzo: "SCI", classe: "3", subject: "MAT", topic: process.env.CONTRACT_TOPIC || "7" },
    source: "personal",
    // Preset elenco a 3 livelli (nomi interni renderer = marker per livello):
    //   Gruppo 1 → 1. a. i.   (decimale → alfa-minuscolo → romano-minuscolo)
    //   Gruppo 2 → A. 1. a.   (alfa-maiuscolo → decimale → alfa-minuscolo)
    //   Gruppo 3 → I. A. 1.   (romano-maiuscolo → alfa-maiuscolo → decimale)
    groups: [
        group("Gruppo 1 — Parabole", "Risolvi i seguenti problemi sulle parabole.",
            [quesito("Quesito 1.1", ["decimal", "lower-alpha-roman", "lower-roman"], ["parabola", "retta", "seno"], 1, "p1")]),
        group("Gruppo 2 — Rette e sistemi", "Risolvi i seguenti problemi su rette e sistemi.",
            [quesito("Quesito 2.1", ["alpha-decimal", "decimal", "lower-alpha-roman"], ["retta", "seno", "parabola"], 2, "q2"),
             quesito("Quesito 2.2", ["alpha-decimal", "decimal", "lower-alpha-roman"], ["parabola", "retta", "seno"], 2, "r2")]),
        group("Gruppo 3 — Funzioni varie", "Risolvi i seguenti problemi sulle funzioni.",
            [quesito("Quesito 3.1", ["roman-alpha", "alpha-decimal", "decimal"], ["seno", "parabola", "retta"], 3, "s3"),
             quesito("Quesito 3.2", ["roman-alpha", "alpha-decimal", "decimal"], ["parabola", "seno", "retta"], 3, "t3")]),
    ],
    meta: [],
    version: 1,
    generated_at: "2026-06-03T00:00:00Z",
};

fs.writeFileSync(PATH, JSON.stringify(contract, null, 1));
const n = contract.groups.reduce((a, g) => a + g.items.length, 0);
console.log("WROTE", PATH, "| groups:", contract.groups.length, "| quesiti:", n, "| bytes:", fs.statSync(PATH).size);
