// @ts-check
/**
 * Le formule scritte dal docente arrivano intatte nel sorgente TeX. @tex
 *
 * Riunisce g27_math_br_isolation e g27_math_newline_protect. Il testo dei
 * quesiti è HTML: quando lo si traduce in LaTeX, gli a capo diventano `\\`.
 * Dentro una formula quella sostituzione è un disastro: spezza l'argomento di
 * `\dfrac` e la compilazione fallisce con «Missing }». Qui si verifica che
 * dentro le formule non finiscano né `\\` né i marcatori HTML, e che il
 * risultato compili davvero.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, la compilazione
 * aspetta l'esito invece di riprovare a intervalli fissi, le verifiche si
 * cancellano da sole.
 */
const { test, expect, compilaVerifica } = require("../support/test");

/** Quesito con un a capo HTML dentro l'argomento di una frazione. */
const FRAZIONE_CON_A_CAPO = {
    html: "Equazione: $\\dfrac{2y-2\\cdot<br>2(x+1)}{2y(x+1)}=\\dfrac{7x+1}{2y(x+1)}$",
    solution: "$\\dfrac{a+b}{c+d}<br>=\\dfrac{a-b}{c-d}$",
    points: 1,
    includeSolution: true,
};

/** Soluzione con un sistema su più righe: gli a capo veri sono dentro la formula. */
const SISTEMA_SU_PIU_RIGHE = [
    "Risolvo: $\\begin{cases}",
    "\\dfrac{2y-2\\cdot",
    "2(x+1)}{2y(x+1)}=\\dfrac{7x+1}{2y(x+1)}\\\\",
    "y-3x=0",
    "\\end{cases}$ Quindi sostituisco.",
].join("\n");

test.describe("Verifiche — formule nel sorgente TeX", () => {
    test("un a capo dentro una frazione non spezza la formula @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({
            title: naming.unique("formula-a-capo"),
            versionLabel: "g27",
            overrides: {
                problems: [{
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "formula-a-capo",
                    position: 1,
                    type: "Collect",
                    text: "Risolvi:",
                    items: [FRAZIONE_CON_A_CAPO],
                }],
            },
        });
        const conSoluzioni = batch.docs.find((d) => d.variant === "A_SOL");
        expect(conSoluzioni).toBeDefined();
        if (!conSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(conSoluzioni.id, "versioni/esercizi_SOL.tex");
        const formule = esercizi.content.match(/\$[^$]+\$/g) ?? [];
        expect(formule.length, "il sorgente contiene formule").toBeGreaterThan(0);
        for (const formula of formule) {
            expect(formula, `a capo LaTeX dentro «${formula}»`).not.toMatch(/\\\\/);
            expect(formula, `marcatore HTML dentro «${formula}»`).not.toMatch(/<br/);
        }

        await compilaVerifica(teacherApi.verifica, conSoluzioni.id, { engine: "pdflatex" });
    });

    test("un sistema su più righe resta un sistema @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({
            title: naming.unique("sistema"),
            versionLabel: "g27",
            overrides: {
                selectedIIS: "sc",
                selectedCLS: "1s",
                problems: [{
                    filePath: "/eser/sc/sc1s/MAT/1",
                    problemId: "sistema",
                    position: 1,
                    type: "Collect",
                    text: "Risolvi:",
                    items: [{ html: "Equazione frazione.", solution: SISTEMA_SU_PIU_RIGHE, points: 1, includeSolution: true }],
                }],
            },
        });
        const conSoluzioni = batch.docs.find((d) => d.variant === "A_SOL");
        expect(conSoluzioni).toBeDefined();
        if (!conSoluzioni) return;

        const esercizi = await teacherApi.verifica.texFile(conSoluzioni.id, "versioni/esercizi_SOL.tex");
        // Il difetto storico: un a capo LaTeX inserito dentro l'argomento della frazione.
        expect(esercizi.content, "frazione spezzata da un a capo").not.toMatch(/\\dfrac\{[^}]*\\\\(?:\s*\n\s*)\d/);
        expect(esercizi.content, "la frazione è nel sorgente").toContain("\\dfrac{");

        await compilaVerifica(teacherApi.verifica, conSoluzioni.id, { engine: "pdflatex" });
    });
});
