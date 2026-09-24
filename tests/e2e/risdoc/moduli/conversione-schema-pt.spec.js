// @ts-check
/**
 * Conversione schema → corpo strutturato → campi: non si perde niente.
 * Riscrittura di risdoc_pt_roundtrip.spec.js.
 *
 * È il cancello di sicurezza di ADR-026. I documenti dei modelli sono descritti
 * da uno schema di campi (un voto, un nome, una casella, una tabella); il
 * salvataggio unificato li converte in blocchi del corpo strutturato e li
 * rilegge da lì. Se la conversione perde un valore per strada, il docente
 * riapre il documento e trova un campo vuoto — e non si accorge di quando è
 * successo. Finché questo test è verde il percorso unico si può tenere.
 *
 * È una prova di modulo: i due convertitori si importano nel browser e si
 * chiamano direttamente, senza passare da nessuna pagina.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente stampa
 * dell'esito su console, e i cinque tipi di campo sono cinque test invece che
 * un ciclo dentro a uno, così un fallimento dice subito quale campo.
 */
const { test, expect } = require("../../support/test");

/**
 * I cinque tipi di campo, con un valore che deve sopravvivere al giro.
 * @type {ReadonlyArray<{nome: string, descrizione: string, campo: Record<string, unknown>, valore: unknown}>}
 */
const CASI = [
    {
        nome: "voto",
        descrizione: "il selettore del voto",
        campo: { type: "grade-selector", name: "voto", options: [{ value: "6", label: "Sei" }, { value: "7", label: "Sette" }] },
        valore: "7",
    },
    {
        nome: "prof",
        descrizione: "il campo di testo libero",
        campo: { type: "info-field", name: "prof", title: "Professore" },
        valore: "Mario Rossi",
    },
    {
        nome: "flag",
        descrizione: "la casella singola",
        campo: { type: "form-checkbox", name: "flag", title: "Attivo" },
        valore: true,
    },
    {
        nome: "liv",
        descrizione: "il gruppo di caselle, con due voci spuntate su tre",
        campo: {
            type: "checkbox-group",
            name: "liv",
            options: [{ value: "alto", label: "Alto" }, { value: "medio", label: "Medio" }, { value: "basso", label: "Basso" }],
        },
        valore: ["alto", "basso"],
    },
    {
        nome: "tab",
        descrizione: "la tabella a righe variabili",
        campo: { type: "dynamic-table", name: "tab", columns: ["A", "B"] },
        valore: [["1", "2"], ["3", "4"]],
    },
];

test.describe("Risdoc — conversione fra schema e corpo strutturato", () => {
    test.beforeEach(async ({ teacherPage }) => {
        // Una pagina autenticata qualsiasi: serve solo per importare il modulo
        // dalla stessa origine.
        await teacherPage.goto("/area-docente");
    });

    for (const caso of CASI) {
        test(`${caso.descrizione} torna indietro identico`, async ({ teacherPage }) => {
            const esito = await teacherPage.evaluate(async ({ campo, valore, nome, percorso }) => {
                const modulo = await import(percorso);
                const { sectionSchemaToPt, ptToFields } = modulo;
                const blocchi = sectionSchemaToPt(campo, { [nome]: valore }, {});
                return { tornato: ptToFields(blocchi)[nome] };
            }, { ...caso, percorso: "/js/modules/risdoc/pt/section-to-pt.js" });
            expect(esito.tornato, `il valore di «${caso.nome}»`).toEqual(caso.valore);
        });
    }
});
