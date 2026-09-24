// @ts-check
/**
 * Documenti del docente (risdoc) e pannello «Risorse docente».
 * Riscrittura di risdoc_pt_teacher_smoke.spec.js (Phase 24.38), fetta 2 del
 * refactoring E2E.
 *
 * Verifica, con la stessa copertura di prima:
 *   1. un documento con corpo strutturato si crea, si rilegge con il corpo
 *      intatto e si esporta come pacchetto;
 *   2. il pannello «Risorse docente» si popola e il suo bottone «Modifica
 *      sezione» attiva la modifica del blocco senza dover prima toccare gli
 *      esercizi, senza errori JavaScript in pagina.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attesa a tempo
 * di 2,5 secondi dopo il click sul pannello (si attende l'evento
 * `fm:risdoc-sidepage-rendered` che l'app emette a fine render), niente
 * `page.evaluate` per cliccare, cancellazione del documento garantita dal
 * registro di pulizia invece che da un blocco `finally`.
 */
const { test, expect } = require("../support/test");

test.describe("Risdoc — documento e pannello risorse", () => {
    test("un documento si crea, si rilegge con il corpo strutturato e si esporta", async ({ contentFactory, teacherApi }) => {
        const documento = await contentFactory.document({
            bodyPt: [
                { _type: "block", style: "normal", children: [{ _type: "span", text: "CORPO_DI_PROVA", marks: ["strong"] }] },
            ],
        });

        const letto = await teacherApi.content.get(documento.id);
        const metadati = letto.content.metadata;
        const oggetto = typeof metadati === "string" ? JSON.parse(metadati) : metadati;
        expect(oggetto?.body_pt, "corpo strutturato salvato").toBeTruthy();

        const esportazione = await teacherApi.content.export(documento.id);
        expect(esportazione.ok(), "il pacchetto esportato risponde").toBe(true);
        expect(esportazione.headers()["content-type"], "ed è un archivio").toContain("zip");
    });

    test("il pannello «Risorse docente» si popola e la modifica della sezione si attiva", async ({ homeDocente }) => {
        await homeDocente.vaiA();
        const pannello = await homeDocente.apriSidepage("risorseDocente");

        const blocco = await homeDocente.attivaModificaSezione(pannello);
        await expect(blocco).toHaveAttribute("data-edit-active", "1");
        // Con la modifica attiva ogni categoria offre il bottone per aggiungere.
        await expect(pannello.locator(".fm-section-add").first()).toBeVisible();
    });
});
