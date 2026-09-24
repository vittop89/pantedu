// @ts-check
/**
 * I punteggi di un gruppo vero/falso.
 * Copertura che il refactoring aveva dovuto lasciare indietro (voce 55 del
 * debito, chiusa il 7 settembre 2026): i due casi di exercise-fixes che la
 * verificavano giravano sull'unico contenuto importato dove funzionava.
 *
 * In un gruppo di vero/falso il punteggio non si dà quesito per quesito: si
 * dichiara quanto vale il gruppo intero, e la pagina lo divide fra i quesiti
 * che il docente ha scelto. Dieci punti su quattro affermazioni fanno 2,50
 * l'una, e il totale della barra segue.
 *
 * Il difetto che questa spec ha rimesso in piedi: la pagina riconosceva un
 * gruppo vero/falso guardando se l'identificativo dell'elemento conteneva
 * `type_VF`. Dalla Fase 15 quell'identificativo è un UUID, e il tipo sta in
 * `data-type`: per ogni contenuto creato da allora il campo del punteggio non
 * veniva nemmeno costruito.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — punteggi del vero/falso", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({
            groups: 1, itemsPerGroup: 4, publish: true, groupType: "type_VF",
        });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.gruppo(0).apri();
    });

    test("il gruppo riceve il campo del punteggio totale, con i suoi passi da mezzo punto", async ({
        teacherPage,
    }) => {
        const totale = teacherPage.locator(".vf-total-points-inputA").first();
        await expect(totale, "il campo del punteggio totale c'è").toBeVisible({ timeout: 30_000 });
        await expect(totale, "e si scrive come un numero").toHaveAttribute("type", "number");
        await expect(totale, "a mezzi punti").toHaveAttribute("step", "0.5");

        const passi = totale.locator("xpath=following-sibling::*[1]");
        await totale.fill("0");
        await passi.locator(".fm-stepper__btn--up").click();
        await passi.locator(".fm-stepper__btn--up").click();
        await expect(totale, "due passi in su fanno un punto").toHaveValue("1.0", { timeout: 15_000 });
        await passi.locator(".fm-stepper__btn--down").click();
        await expect(totale, "e uno in giù mezzo").toHaveValue("0.5", { timeout: 15_000 });
    });

    test("il punteggio del gruppo si divide fra i quesiti scelti", async ({ teacherPage }) => {
        const totale = teacherPage.locator(".vf-total-points-inputA").first();
        await expect(totale).toBeVisible({ timeout: 30_000 });
        await totale.fill("10");
        await totale.dispatchEvent("change");

        const gruppo = teacherPage.locator(".fm-groupcollex").first();
        const quesiti = gruppo.locator(".fm-collection__item");
        await expect(quesiti, "le quattro affermazioni").toHaveCount(4);
        // Senza `force`. La pagina ha `scroll-behavior: smooth`, quindi
        // portare in vista il quesito successivo è uno scorrimento animato:
        // `force` fa saltare a Playwright l'attesa che l'elemento sia fermo,
        // e il clic parte alle coordinate calcolate prima. Sul terzo o sul
        // quarto quesito atterrava una novantina di pixel più su, su un
        // altro elemento, e la casella restava vuota — un fallimento che si
        // vedeva in integrazione continua e quasi mai qui. La casella è
        // vera, visibile e scoperta: aspettarla ferma è ciò che fa un docente.
        for (let i = 0; i < 4; i++) {
            await quesiti.nth(i).locator(".fm-checkbox-ain").check();
        }

        for (let i = 0; i < 4; i++) {
            await expect
                .poll(() => quesiti.nth(i).locator(".fm-input-pt").inputValue(), {
                    message: `l'affermazione ${i + 1} vale un quarto dei dieci punti`,
                    timeout: 15_000,
                })
                .toBe("2.50");
        }
        await expect(teacherPage.locator("#SumPtotA"), "e il totale in barra è quello dichiarato")
            .toHaveValue("10");
    });
});
