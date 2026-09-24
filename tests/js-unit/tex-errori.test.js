import { describe, it, expect } from "vitest";
import { rigaNelSorgente, testoErrori } from "../../js/modules/editor/tex-errori.js";

/**
 * Gli errori di pdflatex con la riga del sorgente del docente (24/9/2026).
 *
 * Il numero dopo `l.` è la riga del documento compilato, che ha la classe e i
 * font in testa: nell'editor è sbagliato di qualche riga. La riga vera si
 * ritrova dal testo che TeX cita. Nei due versi: trovata quando il testo c'è
 * una volta sola, null quando non c'è o c'è due volte.
 */
const SORGENTE = [
    "\\begin{document}",
    "\\begin{tikzpicture}",
    "\\draw[kin inesistente] (0,0) -- (1,0);",
    "\\draw[->] (0,0) -- (2,2);",
    "\\end{tikzpicture}",
    "\\end{document}",
].join("\n");

const ERRORE = {
    line: 7,
    message: "Package pgfkeys Error: I do not know the key '/tikz/kin inesistente' and I am",
    context: " going to ignore it. Perhaps you misspelled it.\nl.7 \\draw[kin inesistente]\n                           (0,0) -- (1,0);",
};

describe("tex-errori: la riga nel sorgente", () => {
    it("la ritrova dal testo che TeX cita, non dal numero del documento compilato", () => {
        expect(rigaNelSorgente(SORGENTE, ERRORE)).toBe(3);
    });

    it("toglie i puntini con cui TeX abbrevia una riga lunga", () => {
        const e = { message: "Paragraph ended", context: "<to be read again>\nl.136 ...sy/\\labely/\\posy/\\visproxy in \\pointslist\n  {" };
        const sorgente = "a\n    \\foreach \\name/\\posx/\\posy/\\labely/\\posy/\\visproxy in \\pointslist\nb";
        expect(rigaNelSorgente(sorgente, e)).toBe(2);
    });

    it("non indica una riga quando il testo non c'è o c'è due volte", () => {
        expect(rigaNelSorgente("\\begin{document}\n\\end{document}", ERRORE)).toBeNull();
        expect(rigaNelSorgente(`${SORGENTE}\n\\draw[kin inesistente] (0,0) -- (1,0);`, ERRORE)).toBeNull();
        expect(rigaNelSorgente(SORGENTE, { message: "x", context: "" })).toBeNull();
    });

    it("il testo per il docente dice quanti errori, dove e quale", () => {
        const testo = testoErrori([ERRORE], SORGENTE);
        expect(testo.startsWith("1 errore di pdflatex:")).toBe(true);
        expect(testo).toContain("riga 3 del sorgente — ! Package pgfkeys Error");
        expect(testoErrori([ERRORE, { message: "Undefined control sequence.", context: "" }], SORGENTE))
            .toContain("2 errori di pdflatex:");
    });
});
