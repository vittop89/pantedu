// @ts-check
/**
 * I campi che stanno attorno al quesito: origine, posizione, caselle A/R,
 * riordino del gruppo.
 * Riscrittura di exercise-fixes.spec.js.
 *
 * Sono i comandi piccoli della pagina di studio, quelli che si usano mentre si
 * monta una verifica: da dove viene il quesito, in che ordine sta, se va nella
 * versione A o nel recupero, dove si sposta il gruppo. Nessuno di questi ha una
 * pagina propria, e quando si rompono non se ne accorge nessuno finché non
 * serve montare una prova.
 *
 * Cosa cambia rispetto a prima: l'esercizio nasce dal test invece di essere la
 * copia 58 del dump locale, i comandi si premono invece di essere chiamati da
 * codice, e delle undici attese a tempo non resta nulla. Le asserzioni sui
 * colori esatti e sui pixel di margine sono diventate confronti prima/dopo:
 * quel che conta è che la casella scelta si distingua e che aprire una sezione
 * non le tolga il margine, non che il verde sia esattamente #16a34a.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — campi del quesito", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 3, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.gruppo(0).apri();
    });

    test("l'origine del quesito arriva già scelta, con le sue opzioni", async ({ teacherPage }) => {
        const origine = teacherPage.locator(".fm-collection__item select.origin").first();
        await expect(origine, "il campo dell'origine c'è").toBeAttached({ timeout: 30_000 });
        await expect(origine, "e arriva con un valore, non vuoto").not.toHaveValue("");
        expect(await origine.locator("option").count(), "le origini fra cui scegliere sono più d'una")
            .toBeGreaterThan(1);
    });

    test("i passi della posizione la fanno salire e scendere di uno", async ({ teacherPage }) => {
        const posizione = teacherPage.locator(".fm-position .fm-def-position-imp").first();
        const passi = teacherPage.locator(".fm-position .fm-stepper").first();
        await expect(posizione).toBeVisible({ timeout: 30_000 });
        await expect(posizione, "la posizione si scrive come un numero").toHaveAttribute("type", "number");

        await posizione.fill("1");
        await passi.locator(".fm-stepper__btn--up").click();
        await expect(posizione, "il passo in su porta a due").toHaveValue("2", { timeout: 15_000 });
        await passi.locator(".fm-stepper__btn--down").click();
        await expect(posizione, "e quello in giù riporta a uno").toHaveValue("1", { timeout: 15_000 });
    });

    test("le caselle A e R si accendono quando si scelgono, e non allo stesso modo", async ({ teacherPage }) => {
        // A è la versione della verifica, R il recupero: si distinguono a
        // colpo d'occhio, e i due colori non devono essere lo stesso.
        const caselle = teacherPage.locator(".fm-groupcollex .fm-check .labcheck");
        await expect(caselle, "le due caselle ci sono").toHaveCount(2, { timeout: 30_000 });

        /** @param {number} indice */
        const sfondo = (indice) => caselle.nth(indice).evaluate((el) => getComputedStyle(el).backgroundColor);
        const spenteA = await sfondo(0);
        const spenteR = await sfondo(1);

        await caselle.nth(0).click();
        await expect.poll(() => sfondo(0), { message: "la casella A si accende", timeout: 15_000 }).not.toBe(spenteA);
        await caselle.nth(1).click();
        await expect.poll(() => sfondo(1), { message: "e anche quella R", timeout: 15_000 }).not.toBe(spenteR);

        expect(await sfondo(0), "ma con due colori diversi").not.toBe(await sfondo(1));
    });

    test("i passi del riordino cambiano la posizione del gruppo", async ({ teacherPage }) => {
        const posizione = teacherPage.locator(".fm-move-position-problem").first();
        await expect(posizione).toBeAttached({ timeout: 30_000 });
        const passi = posizione.locator("xpath=following-sibling::*[1]");

        await posizione.fill("1");
        await passi.locator(".fm-stepper__btn--up").click();
        await expect(posizione, "il gruppo si sposta di una posizione").toHaveValue("2", { timeout: 15_000 });
    });

    test("aprendo una sezione il suo margine interno resta quello di prima", async ({ studioEsercizio, teacherPage }) => {
        // Era una regressione vera: da chiusa la sezione aveva il suo margine,
        // da aperta il testo finiva appiccicato al bordo.
        const intestazione = teacherPage.locator(".fm-contract-wrap .fm-collapsible").first();
        const margini = () =>
            intestazione.evaluate((el) => {
                const stile = getComputedStyle(el);
                return `${stile.paddingLeft}|${stile.paddingRight}`;
            });

        await studioEsercizio.gruppo(0).chiudi();
        const daChiusa = await margini();
        expect(parseFloat(daChiusa), "da chiusa il margine c'è").toBeGreaterThan(0);

        await studioEsercizio.gruppo(0).apri();
        expect(await margini(), "e aprendola non cambia").toBe(daChiusa);
    });
});
