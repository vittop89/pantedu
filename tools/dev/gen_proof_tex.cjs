/* DEV: genera un documento LaTeX completo che rispecchia il contenuto dei 3
 * gruppi del PROOF (1293): testo formattato (B/I/U + combinazioni), elenchi
 * nested, una figura TikZ + un grafico ("GeoGebra") per ogni riga, e un TikZ
 * complesso per gruppo. Compilabile via /api/tex/compile-adhoc-pdf (pdflatex VPS).
 * I grafici "GeoGebra" sono resi in TikZ nativo (pdflatex non rende SVG inline;
 * la pipeline app userebbe svg->pdf via bundle verifica).
 * Output: tools/dev/_proof.tex
 */
const fs = require("fs");

// grafico tipo-geogebra (assi + curva) reso in TikZ
function ggbTikz(kind, n) {
    const curve = {
        parabola: "\\draw[domain=-1.6:1.6,smooth,very thick,blue] plot (\\x,{\\x*\\x});",
        retta: "\\draw[very thick,green!60!black] (-1.8,-1)--(1.8,2.5);",
        seno: "\\draw[domain=-3.1:3.1,smooth,very thick,violet] plot (\\x,{sin(\\x r)});",
    }[kind] || "\\draw[domain=-1.6:1.6,smooth,very thick,blue] plot (\\x,{\\x*\\x});";
    return `\\begin{tikzpicture}[scale=0.7,baseline]
\\draw[->,gray] (-2,0)--(2.2,0) node[right,scale=.6]{$x$};
\\draw[->,gray] (0,-1.4)--(0,3) node[above,scale=.6]{$y$};
${curve}
\\node[scale=.55,fill=white,inner sep=1pt] at (1.3,2.6) {GeoGebra ${n}};
\\end{tikzpicture}`;
}
function figTikz(n) {
    return `\\begin{tikzpicture}[scale=0.55,baseline]\\draw[thick,fill=blue!10] (0,0)--(2,0)--(1,1.7)--cycle;\\node[scale=.55] at (1,-0.4) {fig.${n}};\\end{tikzpicture}`;
}
function complexTikz(v) {
    const f = { 1: ["{\\x*\\x}", "{0.5*\\x*\\x+0.5}", "$y=x^2$"], 2: ["{0.5*\\x*\\x*\\x}", "{2*\\x}", "$y=\\frac12 x^3$"], 3: ["{abs(\\x)}", "{-abs(\\x)+3}", "$y=|x|$"] }[v] || ["{\\x*\\x}", "{\\x}", "$y=x^2$"];
    return `\\begin{tikzpicture}[scale=1.0]
\\draw[->,gray] (-3,0)--(3.2,0) node[right]{$x$};
\\draw[->,gray] (0,-1.2)--(0,4) node[above]{$y$};
\\foreach \\x in {-2,-1,1,2} \\draw (\\x,0.06)--(\\x,-0.06) node[below,scale=.7]{$\\x$};
\\foreach \\y in {1,2,3} \\draw (0.06,\\y)--(-0.06,\\y) node[left,scale=.7]{$\\y$};
\\draw[domain=-1.9:1.9,smooth,very thick,blue] plot (\\x,${f[0]});
\\draw[domain=-1.9:1.9,smooth,thick,red,dashed] plot (\\x,${f[1]});
\\fill (0,0) circle (2pt) node[below right,scale=.8]{$O$};
\\node[blue] at (2.2,3.5) {${f[2]}};
\\end{tikzpicture}`;
}

const fmt = "Risolvi \\textbf{in grassetto}, \\textit{in corsivo}, \\underline{sottolineato}, " +
    "\\textbf{\\textit{grassetto+corsivo}}, \\textbf{\\underline{grassetto+sottolineato}}, " +
    "\\textit{\\underline{corsivo+sottolineato}} e \\textbf{\\textit{\\underline{tutti e tre}}}.";

function row(label, kind, n, sub) {
    let s = `\\item \\textbf{${label}} --- ${fmt}\\\\[2pt]\n${figTikz(n)}\\quad ${ggbTikz(kind, n)}\n`;
    if (sub) {
        s += `\\begin{enumerate}[label=\\alph*)]\n`;
        s += `\\item Sotto-punto \\textit{${n}.a}: ${fmt}\\\\[2pt]${figTikz(n + "a")}\\quad ${ggbTikz("retta", n + "a")}\n`;
        s += `\\item Sotto-punto \\textit{${n}.b}: con \\underline{radice} $\\sqrt{x^2+1}$\\\\[2pt]${figTikz(n + "b")}\\quad ${ggbTikz("seno", n + "b")}\n`;
        s += `\\end{enumerate}\n`;
    }
    return s;
}

function quesito(qLabel, kind, cv) {
    return `\\subsection*{${qLabel}}
\\textbf{${qLabel}}: studia le seguenti funzioni con \\textit{elenco annidato}.
\\begin{enumerate}[label=\\arabic*.]
${row("Funzione 1", kind, "1", true)}
${row("Funzione 2", "retta", "2", true)}
${row("Funzione 3", "seno", "3", false)}
\\end{enumerate}
\\underline{TikZ complesso del gruppo} (grafico comparato):\\\\[4pt]
${complexTikz(cv)}

\\textbf{Soluzione}: $x = \\dfrac{-b\\pm\\sqrt{b^2-4ac}}{2a}$.
`;
}

const body = `
\\section*{Gruppo 1 --- Parabole}
Risolvi i seguenti problemi sulle parabole.
${quesito("Quesito 1.1", "parabola", 1)}

\\section*{Gruppo 2 --- Rette e sistemi}
Risolvi i seguenti problemi su rette e sistemi.
${quesito("Quesito 2.1", "retta", 2)}
${quesito("Quesito 2.2", "parabola", 2)}

\\section*{Gruppo 3 --- Funzioni varie}
Risolvi i seguenti problemi sulle funzioni.
${quesito("Quesito 3.1", "seno", 3)}
${quesito("Quesito 3.2", "parabola", 3)}
`;

const tex = `\\documentclass[11pt,a4paper]{article}
\\usepackage[utf8]{inputenc}
\\usepackage[T1]{fontenc}
\\usepackage[italian]{babel}
\\usepackage{amsmath,amssymb}
\\usepackage[normalem]{ulem}
\\usepackage{enumitem}
\\usepackage{tikz}
\\usepackage[margin=2cm]{geometry}
\\title{PROOF-COPY di 1291 --- Esercizi (test toolbar)}
\\author{superadmin}
\\date{}
\\begin{document}
\\maketitle
${body}
\\end{document}
`;

fs.writeFileSync("tools/dev/_proof.tex", tex);
console.log("WROTE tools/dev/_proof.tex bytes=" + tex.length);
