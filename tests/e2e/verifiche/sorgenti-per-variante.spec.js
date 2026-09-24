// @ts-check
/**
 * Che cosa finisce nel sorgente di ciascuna variante, e il preambolo comune.
 *
 * Riscrittura di g19_49_tex_variants (la variante con le soluzioni porta il
 * riquadro della soluzione e il riferimento al libro, quella per gli studenti
 * no) e di g19_49l_preamble_editor (il preambolo servito dall'app).
 *
 * Il testo dei quesiti arriva in notazione MathJax e va tradotto in LaTeX
 * vero: i cerchietti `\enclose` diventano `\Circled`, i riquadri colorati
 * `\bbox` diventano `\colorbox`, e i delimitatori `\(…\)` spariscono a favore
 * di `$…$`. Se qualcosa di tutto ciò resta com'era, LaTeX non compila.
 */
const { test, expect } = require("../support/test");

/** Riferimento al libro di testo, come lo inserisce l'editor del docente. */
const RIFERIMENTO_AL_LIBRO =
    "\\(\\begin{array}{|c|}\\hline\\small{\\text{Matematica multimediale.blu}}\\\\[-5pt]"
    + "\\tiny{\\text{Vol.2 Ed.3 - ZANICHELLI}}\\\\[-5pt]"
    + "\\tiny{\\text{Massimo Bergamini - Graziella Barozzi}}\\\\[-5pt]\\hline\\end{array}"
    + "\\quad\\overset{\\color{red}\\huge \\bullet\\bullet\\circ\\circ}{\\underset{\\text{P-}1171}"
    + "{\\bbox[border: 1px solid white; background: green,3pt]"
    + "{{\\mathmakebox[cm][c]{\\textcolor{white}{\\large 181}}}}}}\\quad\\)";

const TESTO_QUESITO = `${RIFERIMENTO_AL_LIBRO} Sia \\(ABCD\\) un trapezio. Determina \\(\\enclose{circle}[mathcolor=red]{x}\\).`;
const SOLUZIONE = "\\(\\enclose{circle}[mathcolor=red]{x} = \\dfrac{32 - 4\\sqrt{19}}{10}\\)";

test.describe("Verifiche — sorgente delle varianti", () => {
    test("la variante con le soluzioni porta soluzione e riferimento al libro, quella per gli studenti no", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({
            title: naming.unique("varianti"),
            versionLabel: "g19",
            overrides: {
                problems: [{
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "type_Collect_test",
                    position: 1,
                    type: "Collect",
                    text: "Risolvi:",
                    items: [{ html: TESTO_QUESITO, solution: SOLUZIONE, points: 1, includeSolution: true }],
                }],
            },
        });
        const conSoluzioni = batch.docs.find((d) => d.variant === "A_SOL");
        const perStudenti = batch.docs.find((d) => d.variant === "A_NOR");
        expect(conSoluzioni && perStudenti, "le due varianti della versione A").toBeTruthy();
        if (!conSoluzioni || !perStudenti) return;

        const sol = await teacherApi.verifica.texText(conSoluzioni.tex_url);
        expect(sol, "riferimento al libro").toContain("Matematica multimediale.blu");
        expect(sol, "editore").toContain("ZANICHELLI");
        expect(sol, "riquadro della soluzione").toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Soluzione}}");
        expect(sol, "elenco con lettere maiuscole").toContain("label=\\textbf{\\Alph*)}");
        expect(sol, "la formula della soluzione").toContain("4\\sqrt{19}");
        // Notazione MathJax tradotta in LaTeX vero.
        expect(sol, "cerchietto tradotto").toContain("\\Circled[inner color=");
        expect(sol, "niente notazione MathJax").not.toContain("\\enclose");
        expect(sol, "niente riquadro MathJax").not.toMatch(/\\bbox\[/);
        expect(sol, "niente scatola MathJax").not.toContain("\\mathmakebox");
        expect(sol, "sfondo colorato tradotto").toContain("\\colorbox{green}");
        expect(sol, "niente delimitatori MathJax aperti").not.toMatch(/\\\(/);
        expect(sol, "niente delimitatori MathJax chiusi").not.toMatch(/\\\)/);

        const nor = await teacherApi.verifica.texText(perStudenti.tex_url);
        expect(nor, "nessun riferimento al libro").not.toContain("Matematica multimediale.blu");
        expect(nor, "nessun editore").not.toContain("ZANICHELLI");
        expect(nor, "nessuna soluzione").not.toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Soluzione}}");
    });

    test("il preambolo comune è servito con i pacchetti necessari", async ({ adminApi }) => {
        /** @type {{ ok: boolean, current?: string, default?: string, is_custom?: boolean }} */
        const preambolo = await adminApi.http.getJson("/api/admin/verifica/preamble");
        expect(preambolo.ok).toBe(true);
        expect(preambolo.current, "preambolo in uso").toContain("\\documentclass");
        expect(preambolo.default, "preambolo predefinito").toContain("\\documentclass");
        // Il pacchetto che disegna i cerchietti attorno alle incognite.
        expect(preambolo.default, "pacchetto dei cerchietti").toContain("circledsteps");
    });
});
