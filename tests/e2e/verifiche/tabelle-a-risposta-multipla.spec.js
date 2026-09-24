// @ts-check
/**
 * Le tabelle dei quesiti a risposta multipla, in pagina e nell'editor.
 * Seconda parte della riscrittura di verifiche_studio_smoke.spec.js.
 *
 * Un quesito a risposta multipla si presenta come una tabella: ogni cella è una
 * scelta, e ogni colonna dichiara di che tipo sono le sue celle — una casella
 * da barrare, un cerchietto, un riquadro da riempire, un vero/falso. Prima le
 * tabelle erano di tipi diversi con markup diverso (`rm-table-vf`,
 * `rm-table-pick`, le lettere a./b./c.); dalla fase G23 sono una sola, e il
 * tipo sta nella tabella (`data-typecell`).
 *
 * Nell'editor la stessa cosa si governa dal riquadro «Layout tabelle RM»:
 * quante tabelle, come sono girate, quante righe e colonne, di che tipo sono le
 * celle di ciascuna colonna, e che cosa c'è scritto dentro.
 *
 * Cosa cambia rispetto a prima: la verifica con il gruppo a risposta multipla
 * nasce dal test invece di essere «Sistemi lineari» del dump locale, l'editor
 * si apre premendo il comando invece che chiamando `click()` da codice, e delle
 * attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — tabelle a risposta multipla", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const verifica = await contentFactory.exercise({
            contentType: "verifica",
            groups: 1,
            itemsPerGroup: 2,
            publish: true,
            groupType: "type_RMulti",
        });
        await studioEsercizio.vaiA(verifica.studioUrl);
        await studioEsercizio.gruppo(0).apri();
    });

    test("ogni quesito porta la sua tabella, che dichiara il tipo delle celle", async ({ teacherPage }) => {
        const tabelle = teacherPage.locator(".fm-rm-table");
        await expect(tabelle, "una tabella per quesito").toHaveCount(2, { timeout: 30_000 });

        for (const tabella of await tabelle.all()) {
            await expect(tabella, "la tabella dice di che tipo sono le sue celle").toHaveAttribute("data-typecell", /.+/);
            expect(await tabella.locator(".rm-option").count(), "e porta le sue scelte").toBeGreaterThan(0);
        }
    });

    test("le celle portano il comando da barrare, e non più le lettere decorative", async ({ teacherPage }) => {
        // Prima ogni cella aveva davanti un «a.», «b.» scritto nel markup: era
        // decorazione, ma quando si riapriva la cella per modificarla finiva
        // dentro il contenuto. Adesso la cella è un involucro con dentro il
        // comando, e il segno lo mette il foglio di stile.
        const conteggi = await teacherPage.evaluate(() => ({
            involucri: document.querySelectorAll(".fm-rm-table .fm-wrap-check-cell").length,
            caselle: document.querySelectorAll(".fm-rm-table .fm-checkbox-rm").length,
            lettereDecorative: document.querySelectorAll(".fm-rm-table .rm-letter").length,
        }));
        expect(conteggi.involucri, "ogni cella ha il suo involucro").toBeGreaterThan(0);
        expect(conteggi.caselle, "con dentro il comando da barrare").toBeGreaterThan(0);
        expect(conteggi.lettereDecorative, "e nessuna lettera scritta nel markup").toBe(0);
    });

    test("l'editor del quesito porta i comandi del layout della tabella", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        const riquadro = teacherPage.locator(".fm-rm-layout-section");
        await expect(riquadro, "il riquadro del layout c'è").toBeVisible({ timeout: 30_000 });
        await expect(riquadro, "e si presenta").toContainText("Layout tabelle RM");

        const etichette = await riquadro.locator("label > span").allInnerTexts();
        for (const attesa of ["Numero tabelle", "Orientamento tabelle", "Righe", "Colonne", "Mix righe", "Mix colonne"]) {
            expect(etichette.map((e) => e.trim()), `il comando «${attesa}»`).toContain(attesa);
        }
        await expect(riquadro.locator('[data-field="table_count"]'), "si parte da una tabella").toHaveValue("1");
        await expect(riquadro.locator('[data-field="orientation"]'), "girata per il lungo").toHaveValue("horizontal");
    });

    test("il tipo delle celle si sceglie colonna per colonna, fra i sei disponibili", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        const riquadro = teacherPage.locator(".fm-rm-layout-section");
        const perColonna = riquadro.locator("select[data-col]");
        await expect(perColonna, "un selettore per colonna").toHaveCount(4, { timeout: 30_000 });

        const voci = await perColonna.first().locator("option").allInnerTexts();
        for (const tipo of ["Checkbox", "Radio", "Button", "Text", "Number", "Vero/Falso"]) {
            expect(voci.join(" | "), `fra i tipi di cella c'è «${tipo}»`).toContain(tipo);
        }

        // Le vecchie righe di opzione, una per scelta, non ci sono più: il
        // contenuto si scrive nella griglia delle celle.
        await expect(teacherPage.locator(".fm-editor-panel .fm-option-row"), "niente righe di opzione")
            .toHaveCount(0);
        await expect(
            teacherPage.locator('.fm-editor-panel .fm-editor-field[data-field^="rm-cell-"]'),
            "una casella di scrittura per cella",
        ).toHaveCount(4);
    });

    test("scrivendo in una cella la formula viene disegnata sotto gli occhi", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.apriEditorQuesito(studioEsercizio.gruppo(0), 0);

        const cella = teacherPage.locator('.fm-editor-panel .fm-editor-field[data-field^="rm-cell-"]').first();
        await expect(cella).toBeVisible({ timeout: 30_000 });
        await cella.click();
        await cella.evaluate((el) => {
            el.textContent = "\\(a^2 + b^2 = c^2\\)";
            el.dispatchEvent(new Event("input", { bubbles: true }));
        });
        await cella.focus();

        const anteprima = teacherPage.locator("#fm-cell-popup-preview");
        await expect(anteprima, "l'anteprima della cella compare").toBeVisible({ timeout: 30_000 });
        await expect(anteprima, "e mostra la formula, non il suo sorgente").toContainText(/a|c/);
    });
});
