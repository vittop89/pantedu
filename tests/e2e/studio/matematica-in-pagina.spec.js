// @ts-check
/**
 * La matematica dei quesiti: resa in pagina, resa nell'editor, e intatta dopo
 * un salvataggio.
 * Riscrittura di studio_eser_mathjax.spec.js.
 *
 * I quesiti contengono formule scritte in LaTeX, e la pagina le disegna. Le tre
 * cose che possono andare storte sono: la formula resta scritta com'è (si legge
 * `\\(x^2\\)` invece di vedere la formula), l'editor non la disegna mentre si
 * scrive, oppure il giro di salvataggio la rompe — la perde, o la raddoppia
 * perché la disegna due volte.
 *
 * Il disegno è differito: parte quando il gruppo si apre. Per questo i gruppi
 * si aprono prima di guardare.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, il quesito con la
 * formula nasce dal test invece di venire da un contenuto del dump locale, e
 * delle cinque attese a tempo non resta nulla: si aspetta che la formula sia
 * disegnata, non un tempo.
 */
const { test, expect } = require("../support/test");

/** Un quesito con dentro due formule, una in linea e una a blocco. */
const CON_FORMULE = "<p>Calcola \\(\\sqrt{x^2+1}\\) e poi \\[\\dfrac{a}{b}\\]</p>";

/**
 * Quante formule sono disegnate, e quanta sorgente è rimasta a vista.
 * @param {import("@playwright/test").Page} pagina
 * @param {string} dentro
 */
async function matematica(pagina, dentro = ".fm-groupcollex") {
    return pagina.evaluate((/** @type {string} */ selettore) => {
        const radice = document.querySelector(selettore);
        if (!radice) return { disegnate: 0, sorgenteAVista: 0 };
        const testo = /** @type {HTMLElement} */ (radice).innerText || "";
        return {
            disegnate: radice.querySelectorAll("mjx-container, .MathJax").length,
            // Delimitatori o macro rimasti leggibili: vuol dire che il disegno
            // non è avvenuto.
            sorgenteAVista: (testo.match(/\\\(|\\\[|\\sqrt|\\dfrac/g) ?? []).length,
        };
    }, dentro);
}

test.describe("Studio esercizio — matematica dei quesiti", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({
            groups: 1,
            itemsPerGroup: 2,
            publish: true,
            itemHtml: CON_FORMULE,
        });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.gruppo(0).apri();
    });

    test("in lettura le formule sono disegnate, non lasciate scritte", async ({ teacherPage }) => {
        await expect
            .poll(async () => (await matematica(teacherPage)).disegnate, {
                message: "le formule vengono disegnate",
                timeout: 30_000,
            })
            .toBeGreaterThan(0);

        const stato = await matematica(teacherPage);
        expect(stato.sorgenteAVista, "e non resta sorgente a vista").toBe(0);
    });

    test("nell'editor la formula si vede disegnata e la sorgente resta modificabile", async ({
        studioEsercizio, teacherPage,
    }) => {
        const pannello = await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        await expect
            .poll(async () => (await matematica(teacherPage, ".fm-editor-panel")).disegnate, {
                message: "l'anteprima nell'editor disegna la formula",
                timeout: 30_000,
            })
            .toBeGreaterThan(0);

        const sorgente = await pannello.evaluate((el) => {
            const conservata = el.querySelectorAll("[data-raw]").length > 0;
            return conservata || /sqrt|dfrac/.test(el.textContent ?? "");
        });
        expect(sorgente, "e la sorgente LaTeX resta a disposizione di chi scrive").toBe(true);
    });

    test("dopo un salvataggio la formula non si perde e non si raddoppia", async ({
        studioEsercizio,
        teacherPage,
    }) => {
        await expect
            .poll(async () => (await matematica(teacherPage)).disegnate, { timeout: 30_000 })
            .toBeGreaterThan(0);
        const prima = (await matematica(teacherPage)).disegnate;

        const pannello = await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);
        const campo = pannello.locator(".fm-editor-field").first();
        await campo.click();
        await teacherPage.keyboard.type(" testo aggiunto dopo la formula");
        await studioEsercizio.chiudiEditorQuesito();

        await expect
            .poll(async () => (await matematica(teacherPage)).disegnate, {
                message: "dopo il salvataggio le formule sono ancora disegnate",
                timeout: 30_000,
            })
            .toBeGreaterThan(0);

        const dopo = await matematica(teacherPage);
        expect(dopo.sorgenteAVista, "niente sorgente rimasta a vista").toBe(0);
        expect(dopo.disegnate, `le formule non si sono raddoppiate (prima ${prima}, dopo ${dopo.disegnate})`)
            .toBeLessThanOrEqual(prima * 1.5 + 1);
    });
});
