// Genera il blocco \newunicodechar di tolleranza Unicode per verifica.sty.
const fs = require("fs");
const L = [];
L.push("% --- Tolleranza Unicode (G27): contenuti col math come glifi (output");
L.push("% MathJax) altrimenti rompono pdflatex (\"not set up for use\"). Mappa i");
L.push("% glifi piu comuni ai comandi math. xe/lua li rendono nativi via fontspec.");
L.push("\\RequirePackage{iftex}");
L.push("\\ifpdftex");
L.push("  \\RequirePackage{newunicodechar}");
L.push("  % Silenzia i warning \"Redefining Unicode character\" (ridefiniamo di");
L.push("  % proposito glifi gia mappati da pacchetti caricati prima).");
L.push("  \\IfFileExists{silence.sty}{%");
L.push("    \\RequirePackage{silence}%");
L.push("    \\WarningFilter{newunicodechar}{Redefining Unicode character}%");
L.push("  }{}");
const op = {
  "−": "-", "×": "\\times", "÷": "\\div", "±": "\\pm", "∓": "\\mp",
  "⋅": "\\cdot", "∙": "\\cdot", "·": "\\cdot", "√": "\\surd",
  "≤": "\\le", "≥": "\\ge", "≠": "\\ne", "≈": "\\approx",
  "≡": "\\equiv", "∞": "\\infty", "∑": "\\sum", "∏": "\\prod",
  "∫": "\\int", "∂": "\\partial", "∇": "\\nabla", "∈": "\\in",
  "∉": "\\notin", "⊂": "\\subset", "⊆": "\\subseteq", "∪": "\\cup",
  "∩": "\\cap", "→": "\\to", "⇒": "\\Rightarrow", "⇔": "\\Leftrightarrow",
  "∀": "\\forall", "∃": "\\exists", "°": "^\\circ", "′": "\\prime",
  "⋯": "\\cdots", "…": "\\ldots", "∅": "\\emptyset", "≃": "\\simeq", "∼": "\\sim",
};
const add = (g, body) => L.push("  \\newunicodechar{" + g + "}{\\ensuremath{" + body + "}}");
for (const [g, c] of Object.entries(op)) add(g, c);
// math-italic minuscole (h = U+210E), maiuscole
for (let i = 0; i < 26; i++) {
  const ch = String.fromCharCode(97 + i);
  const cp = ch === "h" ? 0x210e : 0x1d44e + i;
  add(String.fromCodePoint(cp), ch);
}
for (let i = 0; i < 26; i++) add(String.fromCodePoint(0x1d434 + i), String.fromCharCode(65 + i));
// math-bold-italic minuscole/maiuscole
for (let i = 0; i < 26; i++) add(String.fromCodePoint(0x1d482 + i), String.fromCharCode(97 + i));
for (let i = 0; i < 26; i++) add(String.fromCodePoint(0x1d468 + i), String.fromCharCode(65 + i));
const gr = {
  "α": "\\alpha", "β": "\\beta", "γ": "\\gamma", "δ": "\\delta",
  "ε": "\\varepsilon", "ζ": "\\zeta", "η": "\\eta", "θ": "\\theta",
  "λ": "\\lambda", "μ": "\\mu", "ν": "\\nu", "π": "\\pi",
  "ρ": "\\rho", "σ": "\\sigma", "τ": "\\tau", "φ": "\\varphi",
  "χ": "\\chi", "ψ": "\\psi", "ω": "\\omega", "Δ": "\\Delta",
  "Ω": "\\Omega", "Σ": "\\Sigma", "Π": "\\Pi", "Φ": "\\Phi",
};
for (const [g, c] of Object.entries(gr)) add(g, c);
const sup = { "²": "2", "³": "3", "¹": "1", "⁰": "0", "⁴": "4", "⁵": "5", "⁶": "6", "⁷": "7", "⁸": "8", "⁹": "9" };
for (const [g, c] of Object.entries(sup)) add(g, "^{" + c + "}");
const sub = { "₀": "0", "₁": "1", "₂": "2", "₃": "3", "₄": "4", "₅": "5", "₆": "6", "₇": "7", "₈": "8", "₉": "9" };
for (const [g, c] of Object.entries(sub)) add(g, "_{" + c + "}");
L.push("\\fi");
fs.writeFileSync("tools/dev/_unicode_block.tex", L.join("\n") + "\n");
console.log("righe:", L.length);
