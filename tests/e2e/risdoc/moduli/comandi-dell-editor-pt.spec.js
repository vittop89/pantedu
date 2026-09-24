// @ts-check
/**
 * Comandi dell'editor del corpo strutturato: inseriscono il blocco giusto.
 * Riscrittura dell'ultimo caso di page_doc_blocks_all.spec.js e di
 * page_doc_glossary_table.spec.js.
 *
 * L'editor del corpo strutturato è costruito su Tiptap: ogni tipo di blocco ha
 * un comando che lo inserisce nel punto dov'è il cursore. Qui si verifica che i
 * comandi ci siano tutti e che, chiamandoli, il documento se ne accorga.
 *
 * È una prova di modulo, e gira sulla pagina dimostrativa dell'editor
 * (`/risdoc-pt-demo.html`), che lo monta senza database intorno.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attesa di 1200
 * millisecondi per il montaggio (si aspetta che il componente ci sia), e i
 * cinque comandi si verificano davvero tutti — prima quattro erano solo
 * cercati per nome e uno solo veniva eseguito.
 */
const { test, expect } = require("../../support/test");

/** Comando dell'editor → nodo che deve comparire nel documento. */
const COMANDI = [
    { comando: "insertPtGlossaryTable", nodo: "ptGlossaryTable", argomenti: [["N.", "Lemma"], [{ n: 1, lemma: "Prova" }]] },
    { comando: "insertPtStaticContent", nodo: "ptStaticContent", argomenti: ["Titolo", "<p>corpo</p>", 2] },
    { comando: "insertPtAccordion", nodo: "ptAccordion", argomenti: [] },
    { comando: "insertPtLinkListPdf", nodo: "ptLinkListPdf", argomenti: [] },
    { comando: "insertPtCitationNorma", nodo: "ptCitationNorma", argomenti: [] },
];

test.describe("Risdoc — comandi dell'editor del corpo strutturato", () => {
    test.beforeEach(async ({ teacherPage }) => {
        await teacherPage.goto("/risdoc-pt-demo.html");
        await expect(
            teacherPage.locator("fm-risdoc-pt-editor"),
            "la pagina dimostrativa monta l'editor",
        ).toBeAttached({ timeout: 30_000 });
        await expect
            .poll(
                async () => teacherPage.evaluate(() => {
                    const el = /** @type {any} */ (document.querySelector("fm-risdoc-pt-editor"));
                    return !!el?._editor;
                }),
                { message: "l'editor Tiptap non si è avviato", timeout: 30_000 },
            )
            .toBe(true);
    });

    for (const { comando, nodo, argomenti } of COMANDI) {
        test(`«${comando}» inserisce un blocco ${nodo}`, async ({ teacherPage }) => {
            const esito = await teacherPage.evaluate(({ nomeComando, args }) => {
                const el = /** @type {any} */ (document.querySelector("fm-risdoc-pt-editor"));
                const editor = el?._editor;
                if (typeof editor?.commands?.[nomeComando] !== "function") {
                    return { registrato: false, documento: "" };
                }
                editor.chain().focus()[nomeComando](...args).run();
                return { registrato: true, documento: JSON.stringify(editor.getJSON()) };
            }, { nomeComando: comando, args: argomenti });

            expect(esito.registrato, `il comando «${comando}» è registrato`).toBe(true);
            expect(esito.documento, `dopo il comando il documento contiene un ${nodo}`).toContain(`"type":"${nodo}"`);
        });
    }
});
