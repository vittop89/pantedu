// @ts-check
/**
 * La scelta casuale dei quesiti.
 * Riscrittura di g20_07_random_selection.spec.js.
 *
 * Invece di spuntare i quesiti a mano, il docente può dire quanti ne vuole da
 * ogni gruppo e con quanti punti in tutto: la pagina li sceglie a caso e
 * distribuisce il punteggio. Serve a fare più versioni della stessa prova senza
 * rifare il lavoro.
 *
 * Cosa cambia rispetto a prima: l'esercizio nasce dal test invece di essere il
 * primo che si trova nella terna, i comandi si premono invece di essere
 * chiamati da codice, e delle quattro attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — scelta casuale", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 4, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
        await studioEsercizio.gruppo(0).apri();
    });

    test("accendendo la scelta casuale ogni gruppo riceve i suoi campi", async ({ teacherPage }) => {
        await teacherPage.locator("#fm-random-toggle").click();
        await expect(teacherPage.locator("body"), "la pagina entra in modalità casuale")
            .toHaveClass(/fm-rand-mode/, { timeout: 15_000 });
        expect(
            await teacherPage.locator(".fm-groupcollex .fm-rand-inputs").count(),
            "e ogni gruppo chiede quanti quesiti e quanti punti",
        ).toBeGreaterThan(0);

        await teacherPage.locator("#fm-random-toggle").click();
        await expect(teacherPage.locator("body"), "spegnendola si torna com'era").not.toHaveClass(/fm-rand-mode/);
        await expect(teacherPage.locator(".fm-rand-inputs"), "e i campi spariscono").toHaveCount(0);
    });

    test("chiedendo un quesito ne viene spuntato uno solo, con il punteggio chiesto", async ({
        teacherPage,
    }) => {
        await teacherPage.locator("#fm-random-toggle").click();
        await expect(teacherPage.locator("body")).toHaveClass(/fm-rand-mode/, { timeout: 15_000 });

        const gruppo = teacherPage.locator(".fm-groupcollex").first();
        await gruppo.locator("label.labcheck").first().click();
        await gruppo.locator(".fm-check .fm-rand-n").fill("1");
        await gruppo.locator(".fm-check .fm-rand-pt").fill("4");

        await teacherPage.locator("#fm-random-pick").click();
        await expect
            .poll(() => gruppo.locator(".fm-checkbox-ain:checked").count(), {
                message: "viene spuntato un quesito solo, come chiesto",
                timeout: 15_000,
            })
            .toBe(1);

        const punteggio = await gruppo
            .locator(".fm-collection__item:has(.fm-checkbox-ain:checked) .fm-input-pt")
            .first()
            .inputValue();
        expect(parseFloat(punteggio), "e prende il punteggio").toBeGreaterThan(0);
    });
});
