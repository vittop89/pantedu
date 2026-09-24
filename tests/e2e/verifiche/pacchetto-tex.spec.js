// @ts-check
/**
 * Forma del pacchetto TeX prodotto per una verifica.
 *
 * Riunisce tre spec della fase G27 che guardavano lo stesso pacchetto da
 * angoli diversi: g27_tikz_preamble_unified (il preambolo TikZ è uno solo,
 * senza suffisso per variante), g27_filetree_dedup_ui (l'elenco dei file non
 * mostra doppioni dei file comuni), g27_ggb_binary_preserve (rimandare
 * indietro i file così come arrivano non azzera i binari).
 *
 * L'ultima verifica una regressione precisa: i file binari (le figure di
 * GeoGebra convertite in PDF) arrivano con il contenuto vuoto e la dimensione
 * vera; salvandoli così, prima del correttivo, venivano riscritti a zero byte
 * e la compilazione falliva.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, le verifiche salvate
 * si cancellano da sole, e la compilazione aspetta l'esito invece di riprovare
 * a intervalli fissi.
 */
const { test, expect, compilaVerifica, pdfCompilato } = require("../support/test");

/** Figura GeoGebra minima: il salvataggio la converte in PDF dentro il pacchetto. */
const SVG_GEOGEBRA =
    '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="150" viewBox="0 0 200 150">'
    + '<rect width="200" height="150" fill="#fff"/><line x1="10" y1="75" x2="190" y2="75" stroke="#888"/>'
    + '<path d="M20,140 Q100,-10 180,140" fill="none" stroke="#1565c0" stroke-width="2"/></svg>';

test.describe("Verifiche — forma del pacchetto TeX", () => {
    test("il preambolo TikZ è uno solo per tutte le varianti @tex", async ({ verificaFactory, teacherApi, naming }) => {
        test.setTimeout(300_000);
        const batch = await verificaFactory.batch({ title: naming.unique("preambolo"), versionLabel: "g27" });
        expect(batch.docs.length).toBeGreaterThanOrEqual(2);

        for (const documento of batch.docs) {
            const files = await teacherApi.verifica.texFiles(documento.id);
            const percorsi = files.map((f) => f.path);
            expect(percorsi, `preambolo nella variante ${documento.variant}`).toContain("versioni/tikz_preamble.tex");
            // Prima del correttivo ogni variante aveva il suo file con il suffisso.
            expect(percorsi.filter((p) => p.startsWith("versioni/tikz_preamble")), "un solo preambolo").toHaveLength(1);
        }

        const solutione = batch.docs.find((d) => d.variant === "A_SOL");
        expect(solutione).toBeDefined();
        if (!solutione) return;
        await compilaVerifica(teacherApi.verifica, solutione.id);
        await pdfCompilato(teacherApi.verifica, solutione.id);
    });

    test("l'elenco dei file non mostra doppioni dei file comuni", async ({ verificaFactory, teacherApi, naming }) => {
        const batch = await verificaFactory.batch({ title: naming.unique("elenco"), versionLabel: "g27" });
        const primo = batch.docs[0];
        expect(primo).toBeDefined();
        if (!primo) return;

        const percorsi = (await teacherApi.verifica.texFiles(primo.id)).map((f) => f.path);
        /** @param {string} nome */
        const conta = (nome) => percorsi.filter((p) => p.endsWith(nome)).length;
        expect(conta("tikz_preamble.tex"), "preambolo TikZ una volta sola").toBe(1);
        expect(conta("fonti.tex"), "elenco delle fonti una volta sola").toBeLessThanOrEqual(1);
        expect(new Set(percorsi).size, "nessun percorso ripetuto").toBe(percorsi.length);
    });

    test("rimandare indietro i file non azzera le figure binarie @istanza", async ({ verificaFactory, teacherApi, naming }) => {
        const titolo = naming.unique("figure");
        const batch = await verificaFactory.batch({
            title: titolo,
            versionLabel: "g27",
            overrides: {
                problems: [{
                    filePath: "/eser/ar/ar2s/MAT/1",
                    problemId: "figura-geogebra",
                    position: 1,
                    type: "Collect",
                    text: "Osserva il grafico:",
                    items: [{
                        html: `<p>Figura: <span class="fm-geogebra-wrap" data-ggb-label="T" data-ggb-width="40%">${SVG_GEOGEBRA}</span></p>`,
                        points: 1,
                        includeSolution: false,
                    }],
                }],
            },
        });
        const soluzione = batch.docs.find((d) => d.variant === "A_SOL");
        expect(soluzione).toBeDefined();
        if (!soluzione) return;

        const prima = await teacherApi.verifica.texFiles(soluzione.id);
        const figura = prima.find((f) => f.path === "versioni/geogebra/1.pdf");
        expect(figura, "la figura è nel pacchetto come PDF").toBeDefined();
        expect(figura?.is_binary, "dichiarata binaria").toBe(true);
        expect(figura?.size ?? 0, "con una dimensione vera").toBeGreaterThan(0);
        expect(figura?.content, "e il contenuto vuoto, che è solo un segnaposto").toBe("");

        // L'editor rimanda tutti i file com'erano, segnaposto compresi.
        await teacherApi.verifica.saveTexFiles(soluzione.id, prima.map((f) => ({ path: f.path, content: f.content })));

        const dopo = await teacherApi.verifica.texFiles(soluzione.id);
        const figuraDopo = dopo.find((f) => f.path === "versioni/geogebra/1.pdf");
        expect(figuraDopo?.size, "la figura ha conservato la sua dimensione").toBe(figura?.size);
    });
});
