// @ts-check
/**
 * Comando «⚙ Editor» della barra: apre i modelli in una finestra.
 * Riscrittura di g20_07_editor_modal_iframe.spec.js.
 *
 * Il comando apre l'area dei modelli del docente dentro una finestra con una
 * cornice (`iframe`), così che si possa scegliere un modello senza lasciare la
 * pagina di studio; nell'intestazione resta il collegamento per aprirla intera
 * in una scheda nuova.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente attese a tempo
 * (erano tre, per un totale di due secondi e trecento millisecondi) e la
 * pagina su cui si lavora nasce dal test invece di essere quella del setup.
 */
const { test, expect } = require("../support/test");

test("il comando «Editor» apre i modelli in una finestra, e la finestra si chiude", async ({ contentFactory, studioEsercizio, teacherPage }) => {
    const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
    await studioEsercizio.vaiA(esercizio.studioUrl);
    await studioEsercizio.topbar.attendiPronta();

    await studioEsercizio.topbar.premi("editor");
    const finestra = teacherPage.locator("#fm-vd-templates-modal");
    await expect(finestra, "la finestra dei modelli si apre").toBeVisible({ timeout: 30_000 });

    const cornice = finestra.locator("iframe.fm-vd-templates-iframe");
    await expect(cornice, "una sola cornice").toHaveCount(1);
    await expect(cornice, "punta all'area dei modelli, in versione incorporata")
        .toHaveAttribute("src", /\/area-docente\/templates.*embed=1/);

    // Dentro la cornice l'area si carica davvero: c'è il comando che apre
    // l'albero dei file e la riga di stato che ne racconta l'esito.
    const dentro = teacherPage.frameLocator("#fm-vd-templates-modal iframe");
    await expect(dentro.locator("#fm-tvf-open"), "comando per aprire l'albero").toBeVisible({ timeout: 30_000 });
    await expect(dentro.locator("#fm-tvf-status"), "riga di stato").toBeAttached();

    // Chi vuole l'area intera ha il collegamento nell'intestazione.
    const collegamento = finestra.locator(".fm-vd-templates-open-tab");
    await expect(collegamento).toHaveAttribute("href", "/area-docente/templates");
    await expect(collegamento, "si apre in una scheda nuova").toHaveAttribute("target", "_blank");

    await finestra.locator('[data-action="close"]').click();
    await expect(teacherPage.locator("#fm-vd-templates-modal"), "chiudendola sparisce dal documento").toHaveCount(0, { timeout: 15_000 });
});
