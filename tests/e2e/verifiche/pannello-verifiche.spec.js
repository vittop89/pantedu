// @ts-check
/**
 * Pannello «Verifiche» nella barra laterale: elenco e modifica delle voci.
 * Riscrittura di g20_07_sidepage_verifica_delete.spec.js.
 *
 * Il pannello elenca i contenuti di tipo verifica del docente per la terna
 * scelta in alto — non i documenti TeX prodotti dalla generazione, che vivono
 * in un'altra tabella — e in modalità modifica ogni voce porta i comandi per
 * rinominarla o eliminarla.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente click
 * sintetici per aggirare le sovrapposizioni, e il contenuto su cui si lavora
 * nasce dal test e viene cancellato alla fine invece di restare nel database.
 *
 * Non sostituisce g19_30_debug_modal_centering, eliminata: aveva duecento
 * righe, sei stampe, due schermate salvate su disco e una sola asserzione,
 * «il modale esiste». La centratura, che dà il nome alla spec, non era
 * verificata.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — pannello nella barra laterale", () => {
    test("una verifica del docente compare nel pannello della sua terna", async ({ contentFactory, homeDocente, naming, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        const titolo = naming.unique("in-pannello");
        const creata = await contentFactory.createVerifica({ titolo, terna: env.terna });
        expect(creata.id).toBeGreaterThan(0);

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("verifiche");

        // Il titolo sta nel collegamento della voce, non nel suo testo.
        const collegamento = pannello.locator(`li a[href*="${encodeURIComponent(titolo)}"]`).first();
        await expect(collegamento, "la verifica appena creata è elencata").toBeVisible({ timeout: 30_000 });
    });

    test("in modalità modifica la voce porta i comandi di rinomina ed eliminazione", async ({ contentFactory, homeDocente, naming, env }) => {
        const { indirizzo, classe, materia } = env.terna;
        const titolo = naming.unique("modificabile");
        await contentFactory.createVerifica({ titolo, terna: env.terna });

        await homeDocente.vaiA();
        await homeDocente.scegliTerna(indirizzo, classe, materia);
        const pannello = await homeDocente.apriSidepage("verifiche");
        const voce = pannello.locator("li").filter({ has: pannello.page().locator(`a[href*="${encodeURIComponent(titolo)}"]`) }).first();
        await expect(voce).toBeVisible({ timeout: 30_000 });

        await homeDocente.attivaModificaSezione(pannello);
        await expect(voce.locator(".fm-item-edit"), "comando di rinomina").toBeVisible({ timeout: 15_000 });
        await expect(voce.locator(".fm-item-del"), "comando di eliminazione").toBeVisible({ timeout: 15_000 });
    });
});
