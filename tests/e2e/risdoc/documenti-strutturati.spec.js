// @ts-check
/**
 * Documenti con corpo strutturato: struttura di partenza ed esportazione in TeX.
 * Riscrittura di risdoc_pt_layout_choice.spec.js e risdoc_pt_content_export.spec.js.
 *
 * Il «corpo strutturato» è la rappresentazione a blocchi del documento: titoli,
 * paragrafi, elenchi, tabelle. Da lì nascono sia la pagina che il pacchetto TeX,
 * e la seconda parte di questo file guarda proprio la traduzione — che è dove
 * un blocco può perdersi senza che nessuno se ne accorga, perché il TeX
 * compila lo stesso.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, i documenti nascono
 * dalla factory e la loro cancellazione è registrata (prima era un `finally`),
 * i metadati si leggono con una chiamata sola, e il pacchetto si apre con la
 * libreria di archiviazione già fra le dipendenze invece che a mano.
 */
const { test, expect, estraiZip, cartellaDiLavoro, leggiFile } = require("../support/test");

test.describe("Risdoc — documenti con corpo strutturato", () => {
    test("la struttura scelta resta scritta nel documento, con le sue sezioni", async ({ contentFactory, teacherApi, naming }) => {
        const titolo = naming.unique("struttura");
        // Le sezioni della struttura «esercizi» le prepara la finestra di
        // creazione: qui si verifica che, salvate, tornino indietro intatte
        // insieme alla struttura scelta.
        const documento = await contentFactory.document({
            title: titolo,
            metadata: { category: "RISORSE", layout: "exercises" },
            bodyPt: [
                { _type: "sectionHeader", level: 1, text: "Esercizi per studenti" },
                { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
                { _type: "sectionHeader", level: 1, text: "Verifiche" },
                { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
            ],
        });

        const metadati = await teacherApi.content.metadata(documento.id);
        expect(metadati["layout"], "la struttura scelta è registrata").toBe("exercises");
        const corpo = /** @type {any[]} */ (metadati["body_pt"]);
        expect(Array.isArray(corpo), "il corpo è una lista di blocchi").toBe(true);
        const titoliSezione = corpo.filter((b) => b._type === "sectionHeader").map((b) => b.text);
        expect(titoliSezione, "le due sezioni della struttura «esercizi»")
            .toEqual(["Esercizi per studenti", "Verifiche"]);
    });

    test("il documento si esporta in un pacchetto TeX che ne contiene il corpo", async ({ contentFactory, teacherApi, naming }) => {
        const marcatore = naming.unique("esporta").toUpperCase().replace(/-/g, "_");
        const documento = await contentFactory.document({
            title: `Titolo ${marcatore} da esportare`,
            bodyPt: [
                {
                    _type: "block",
                    style: "normal",
                    children: [{ _type: "span", text: `Esercizio ${marcatore} in grassetto`, marks: ["strong"] }],
                },
                {
                    // «checked-only»: nel TeX finiscono solo le voci spuntate.
                    _type: "checkboxGroup",
                    renderMode: "checked-only",
                    items: [
                        { state: "x", label: "Risposta_A" },
                        { state: "_", label: "Risposta_B" },
                    ],
                },
            ],
        });

        // Dal 21/9/2026 il pacchetto arriva nel corpo della risposta.
        const risposta = await teacherApi.content.export(documento.id);
        expect(risposta.ok(), "il pacchetto risponde").toBe(true);
        expect(risposta.headers()["content-type"], "ed e un archivio").toContain("zip");

        const cartella = cartellaDiLavoro("risdoc-export");
        const file = estraiZip(await risposta.body(), cartella);
        expect(file, "il pacchetto ha il documento principale").toContain("main.tex");
        expect(file.some((n) => n.endsWith("risdoc.sty")), "e il foglio di stile del documento").toBe(true);

        const sorgente = file.find(
            (n) => n.endsWith(".tex") && n !== "main.tex" && !n.includes("texCommon/"),
        );
        expect(sorgente, "il pacchetto ha il sorgente del corpo").toBeTruthy();
        // Nel TeX il trattino basso è protetto: si toglie la protezione per
        // confrontare le etichette come sono state scritte.
        const tex = leggiFile(cartella, String(sorgente)).replace(/\\_/g, "_");

        expect(tex, "il titolo del documento").toContain(marcatore);
        expect(tex, "il grassetto del corpo").toContain(`\\textbf{Esercizio ${marcatore} in grassetto}`);
        expect(tex, "la voce spuntata c'è").toMatch(/\\item\s+Risposta_A/);
        expect(tex, "quella non spuntata no").not.toMatch(/\\item\s+Risposta_B/);
    });
});
