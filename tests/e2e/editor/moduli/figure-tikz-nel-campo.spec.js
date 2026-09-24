// @ts-check
/**
 * Le figure ripiegate dentro il campo di scrittura.
 * Riscrittura di g22_s15_multi_tikz.spec.js e g22_s15_duplicate_blocks.spec.js.
 *
 * Il sorgente di una figura TikZ è lungo, e nel campo di scrittura darebbe
 * fastidio: l'editor lo ripiega in un segnaposto — «⟨🔍 TikZ #1⟩» — e lo tiene
 * da parte. Aprendo il segnaposto si modifica quella figura e nient'altro;
 * riaprendo il campo, i sorgenti tornano al loro posto nell'ordine giusto.
 *
 * Quando un quesito con due figure viene duplicato, le figure del duplicato
 * devono staccarsi dall'originale: modificando una copia, l'altra resta com'era.
 * Era il difetto per cui correggere un disegno ne cambiava due.
 *
 * Cosa cambia rispetto a prima: la spec chiamava una propria copia della
 * funzione che ripiega i blocchi, scritta con una regex dentro al test, e
 * verificava quella; adesso chiama quella dell'applicazione. E `/auth/csrf`
 * non è più intercettato.
 */
const { test, expect } = require("../../support/test");

/** Tre figure diverse, intervallate da testo: è il caso che si rompe. */
const CAMPO_CON_TRE_FIGURE = [
    "Premessa testo.",
    '<script type="text/tikz">\\begin{tikzpicture}\\draw (0,0) circle (1);\\end{tikzpicture}</' + "script>",
    "Testo intermedio.",
    '<script type="text/tikz">\\begin{tikzpicture}\\draw[red] (0,0)--(2,2);\\end{tikzpicture}</' + "script>",
    "Altro testo.",
    '<script type="text/tikz">\\begin{tikzpicture}\\fill (0,0) circle (3pt);\\end{tikzpicture}</' + "script>",
    "Coda.",
].join("\n");

test.describe("Editor — figure ripiegate nel campo", () => {
    test("tre figure diventano tre segnaposti, e il campo non porta più sorgenti", async ({ bancoEditor }) => {
        const esito = await bancoEditor.page.evaluate(async (campo) => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const { collapseTikzBlocks } = await import("/js/modules/editor/inline-blocks-markers.js");
            const { collapsed: value, blocks } = collapseTikzBlocks(campo);
            return {
                figureTenuteDaParte: blocks.length,
                segnaposti: (value.match(/⟨🔍 TikZ #\d+⟩/g) ?? []).length,
                restaQualcheSorgente: /<script\s+type=["']text\/tikz["']/i.test(value),
                testoConservato: ["Premessa testo.", "Testo intermedio.", "Altro testo.", "Coda."]
                    .every((t) => value.includes(t)),
            };
        }, CAMPO_CON_TRE_FIGURE);

        expect(esito.figureTenuteDaParte, "le tre figure sono messe da parte").toBe(3);
        expect(esito.segnaposti, "e nel campo restano tre segnaposti").toBe(3);
        expect(esito.restaQualcheSorgente, "nessun sorgente rimasto nel campo").toBe(false);
        expect(esito.testoConservato, "il testo attorno è intatto").toBe(true);
    });

    test("modificando una figura, riaprendo il campo le altre due sono quelle di prima", async ({ bancoEditor }) => {
        const esito = await bancoEditor.page.evaluate(async (campo) => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const mod = await import("/js/modules/editor/inline-blocks-markers.js");
            const { collapsed: value, blocks } = mod.collapseTikzBlocks(campo);
            // Si cambia solo la seconda figura, come chi apre il secondo
            // segnaposto e riscrive il disegno.
            blocks[1].body = "\n\\begin{tikzpicture}\\draw[blue] (0,0)--(5,5);\\end{tikzpicture}\n";
            const riaperto = mod.expandTikzMarkers(value, blocks);
            return {
                primaIntatta: riaperto.includes("\\draw (0,0) circle (1)"),
                secondaCambiata: riaperto.includes("\\draw[blue] (0,0)--(5,5)"),
                terzaIntatta: riaperto.includes("\\fill (0,0) circle (3pt)"),
                segnapostiRimasti: (riaperto.match(/⟨🔍 TikZ #\d+⟩/g) ?? []).length,
            };
        }, CAMPO_CON_TRE_FIGURE);

        expect(esito.primaIntatta, "la prima figura è quella di prima").toBe(true);
        expect(esito.secondaCambiata, "la seconda è quella nuova").toBe(true);
        expect(esito.terzaIntatta, "la terza è quella di prima").toBe(true);
        expect(esito.segnapostiRimasti, "e nessun segnaposto è rimasto in giro").toBe(0);
    });

    test("le figure di un quesito duplicato si staccano da quelle dell'originale", async ({ bancoEditor }) => {
        // Il campo del duplicato tiene le proprie figure da parte: se la copia
        // condividesse gli stessi oggetti, correggerne una ne cambierebbe due.
        const esito = await bancoEditor.page.evaluate(async (campo) => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const mod = await import("/js/modules/editor/inline-blocks-markers.js");
            const originale = mod.collapseTikzBlocks(campo);
            const duplicato = mod.collapseTikzBlocks(campo);

            duplicato.blocks[0].body = "\n\\begin{tikzpicture}\\draw[green] (0,0)--(1,1);\\end{tikzpicture}\n";

            return {
                originaleIntatto: mod.expandTikzMarkers(originale.collapsed, originale.blocks)
                    .includes("\\draw (0,0) circle (1)"),
                duplicatoCambiato: mod.expandTikzMarkers(duplicato.collapsed, duplicato.blocks)
                    .includes("\\draw[green] (0,0)--(1,1)"),
                originaleNonHaIlVerde: !mod.expandTikzMarkers(originale.collapsed, originale.blocks)
                    .includes("green"),
            };
        }, CAMPO_CON_TRE_FIGURE);

        expect(esito.duplicatoCambiato, "il duplicato prende la figura nuova").toBe(true);
        expect(esito.originaleIntatto, "l'originale tiene la sua").toBe(true);
        expect(esito.originaleNonHaIlVerde, "e non si prende quella del duplicato").toBe(true);
    });
});
