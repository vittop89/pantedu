// @ts-check
/**
 * Figure TikZ dentro una verifica: preambolo sollevato una volta sola. @tex
 *
 * Riscrittura di g27_tikz_hoist.spec.js. Un disegno TikZ può portare con sé
 * un preambolo con delle macro. Se il docente lo inserisce in più punti dello
 * stesso quesito — nel testo, dentro un elenco, in una cella della tabella,
 * nella giustificazione — il preambolo deve finire una volta sola in testa al
 * documento, e le macro devono diventare `\providecommand`, che non protesta
 * se qualcuno le ha già definite. Senza questo, la seconda definizione fa
 * fallire la compilazione e le figure non si disegnano.
 *
 * Cosa cambia rispetto a prima: niente login nella spec e niente chiamate
 * fatte con `fetch` dentro la pagina (il client usa la stessa sessione);
 * la verifica creata viene cancellata alla fine.
 */
const { test, expect, compilaVerifica } = require("../support/test");

/** Preambolo TikZ con macro proprie, come quello dei modelli del docente. */
const PREAMBOLO_TIKZ = [
    "\\usepackage{amsmath}",
    "\\usepackage{tikz}",
    "\\usetikzlibrary{calc}",
    "",
    "\\newcommand{\\SetPoints}[1]{\\def\\PointsData{#1}}",
    "\\newcommand{\\drawSquare}[2]{%",
    "  \\draw[thick, fill=cyan!20] (#1) rectangle (#2);",
    "}",
    "\\newcommand{\\markCorner}[2]{%",
    "  \\fill[red] (#1) circle (2pt);",
    "  \\node[above right] at (#1) {#2};",
    "}",
    "",
    "\\begin{tikzpicture}[scale=0.8]",
    "  \\drawSquare{0,0}{4,3}",
    "  \\markCorner{0,0}{A}",
    "\\end{tikzpicture}",
].join("\n");

/** @param {string} etichetta */
const figura = (etichetta) =>
    `<script type="text/tikz" data-tex-packages="amsmath,tikz" data-tikz-libraries="calc">`
    + `% figura ${etichetta}\n${PREAMBOLO_TIKZ}</script>`;

/** Lo stesso disegno in sei posizioni diverse dello stesso quesito. */
const QUESITO_CON_SEI_FIGURE =
    "Testo del quesito:" + figura("testo")
    + '<ol class="fm-dsa-li-list" data-dsa-section="question">'
    + "<li>primo punto:" + figura("elenco-del-testo") + "</li>"
    + "<li>secondo punto, solo testo</li>"
    + "</ol>"
    + '<table class="fm-rm-table" data-rows="1" data-cols="1" data-typecell="|X|">'
    + '<tr><td class="rm-option" data-row="0" data-col="0"><div class="fm-cell-content">'
    + "Cella:" + figura("cella")
    + '<ul class="fm-dsa-li-list" data-dsa-section="options">'
    + "<li>prima opzione:" + figura("elenco-della-cella") + "</li>"
    + "<li>seconda opzione, solo testo</li>"
    + "</ul></div></td></tr></table>"
    + '<div class="fm-giustsol">Giustificazione:' + figura("giustificazione")
    + '<ul class="fm-dsa-li-list" data-dsa-section="justification">'
    + "<li>primo passaggio:" + figura("elenco-della-giustificazione") + "</li>"
    + "<li>secondo passaggio, solo testo</li>"
    + "</ul></div>";

test.describe("Verifiche — figure TikZ nel pacchetto", () => {
    test("lo stesso preambolo in sei posizioni finisce una volta sola nel documento @tex", async ({ teacherApi, verificaFactory, naming }) => {
        test.setTimeout(300_000);
        const titolo = naming.unique("tikz-preambolo");
        const salvata = await teacherApi.verifica.saveTex({
            selectedIIS: "SCI", selectedCLS: "3", selectedMATER: "MAT",
            verTitle: titolo,
            title: titolo,
            materia: "MAT",
            anno: "2026", verTime: "60min", sezione: "T",
            istituto: "Istituto di prova", addressSchool: "Scientifico",
            nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
            options: { includeSolutions: false },
            problems: [{
                position: 1,
                type: "RMulti",
                text: "",
                filePath: "/eser/sc/eser_sc3s/MAT/2_MAT-prova_2-sc3s.php",
                problemId: "g0",
                items: [{ position: 1, points: 1, html: QUESITO_CON_SEI_FIGURE, includeSolution: false }],
            }],
        });
        verificaFactory.registerDeletion([salvata.id], "verifica con figure TikZ");

        const sorgente = await teacherApi.verifica.texText(`/api/verifica/${salvata.id}/tex`);
        expect(sorgente.length, "il sorgente non è vuoto").toBeGreaterThan(1000);
        expect(sorgente, "il preambolo è stato sollevato in testa").toContain("G27.tikz.hoist");

        // Una sola definizione, e nella forma che tollera i doppioni.
        expect((sorgente.match(/\\providecommand\{\\drawSquare\}/g) ?? []).length, "una sola definizione di \\drawSquare").toBe(1);
        expect((sorgente.match(/\\newcommand\{\\drawSquare\}/g) ?? []).length, "nessuna definizione rigida").toBe(0);
        // Il disegno resta nel corpo, una volta per posizione.
        expect((sorgente.match(/\\drawSquare\{0,0\}\{4,3\}/g) ?? []).length, "il disegno compare in tutte le posizioni").toBe(6);

        await compilaVerifica(teacherApi.verifica, salvata.id);
        const esito = await teacherApi.verifica.compile(salvata.id);
        const documento = /** @type {{ doc?: { has_pdf?: boolean, pdf_size?: number } }} */ (esito.body)?.doc;
        expect(documento?.has_pdf, "il PDF è stato prodotto").toBe(true);
        expect(documento?.pdf_size ?? 0, "il PDF contiene le figure, non solo testo").toBeGreaterThan(30_000);
    });
});
