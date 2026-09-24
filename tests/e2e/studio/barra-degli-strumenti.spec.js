// @ts-check
/**
 * La barra degli strumenti della pagina di studio: i comandi che ci sono e i
 * pannelli che aprono.
 * Riscrittura di studio_eser_topbar.spec.js.
 *
 * La barra raccoglie tutto quel che si fa sulla pagina: generare la verifica,
 * scaricarne il pacchetto, aprire i modelli, i filtri, le informazioni di
 * stampa, le tre versioni, la scelta casuale dei quesiti. È il posto dove un
 * comando può sparire senza che nessuno se ne accorga finché non serve.
 *
 * Le etichette e le icone dei comandi del documento sono verificate in
 * `verifiche/barra-strumenti`: qui si guarda che ci siano tutti, e che ognuno
 * apra la propria cosa.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente
 * `dispatchEvent("click")` (i comandi si premono), niente `page.evaluate` per
 * leggere lo stato, e delle cinque attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — barra degli strumenti", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("la barra porta tutti i suoi comandi", async ({ studioEsercizio, teacherPage }) => {
        const barra = studioEsercizio.topbar;

        for (const azione of /** @type {const} */ (["salvatex", "zip", "editor", "filtri", "info"])) {
            await expect(barra.comando(azione), `il comando «${azione}»`).toBeVisible();
        }

        await expect(teacherPage.locator("#fm-create-exercise-btn"), "creazione di un esercizio").toBeAttached();
        await expect(teacherPage.locator("#modHeaderBtn"), "modifica dell'intestazione").toBeAttached();
        await expect(teacherPage.locator("#savePrintInfoBtn"), "salvataggio delle informazioni di stampa").toBeAttached();
        await expect(teacherPage.locator("#loadPrintInfoBtn"), "e loro caricamento").toBeAttached();
        await expect(teacherPage.locator(".fm-salva-scelte-btn"), "salvataggio delle scelte").toBeAttached();
        await expect(teacherPage.locator(".fm-carica-scelte-btn"), "e loro caricamento").toBeAttached();
        await expect(teacherPage.locator(".fm-version-btn"), "le tre versioni del documento").toHaveCount(3);
        await expect(teacherPage.locator("#fm-random-toggle"), "la scelta casuale").toBeAttached();
        await expect(teacherPage.locator("#fm-random-pick"), "e quante prenderne").toBeAttached();
    });

    test("«filtri» mostra la barra dei filtri", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.upbar.apri();
        await expect(teacherPage.locator("#sel-dif"), "il filtro della difficoltà si vede").toBeVisible();
    });

    test("«Info» apre le informazioni di stampa", async ({ studioEsercizio, teacherPage }) => {
        // Da solo, e non dopo i filtri: il loro cassetto copre la barra.
        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer"), "il pannello si apre").toBeVisible({ timeout: 30_000 });
    });

    test("«Editor» apre i modelli, e la finestra si chiude", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.topbar.premi("editor");
        const finestra = teacherPage.locator("#fm-vd-templates-modal");
        await expect(finestra, "i modelli si aprono").toBeVisible({ timeout: 30_000 });

        await finestra.locator('[data-action="close"]').click();
        await expect(finestra, "e si chiudono").toHaveCount(0, { timeout: 15_000 });
    });

    test("il comando dell'intestazione apre il proprio editor, e «Annulla» lo chiude", async ({ teacherPage }) => {
        await teacherPage.locator("#modHeaderBtn").click();
        const editor = teacherPage.locator(".fm-header-editor");
        await expect(editor, "l'editor dell'intestazione si apre").toBeVisible({ timeout: 15_000 });

        await editor.getByRole("button", { name: "Annulla" }).click();
        await expect(editor, "e si chiude senza salvare").toHaveCount(0, { timeout: 15_000 });
    });

    test("le tre versioni si scelgono una alla volta", async ({ teacherPage }) => {
        const versioni = teacherPage.locator(".fm-version-btn");
        const attiva = async () =>
            versioni.evaluateAll((elementi) => elementi.findIndex((el) => /active/.test(el.className)));

        await versioni.nth(1).click();
        await expect.poll(attiva, { message: "la seconda versione diventa quella attiva", timeout: 15_000 }).toBe(1);

        await versioni.nth(2).click();
        await expect.poll(attiva, { message: "e poi la terza", timeout: 15_000 }).toBe(2);

        await versioni.nth(0).click();
        await expect.poll(attiva, { message: "e si torna alla prima", timeout: 15_000 }).toBe(0);
    });

    test("la scelta casuale si accende e si spegne", async ({ teacherPage }) => {
        const comando = teacherPage.locator("#fm-random-toggle");
        const stato = async () => comando.getAttribute("aria-pressed");
        const prima = await stato();

        await comando.click();
        await expect.poll(stato, { message: "il comando cambia stato", timeout: 15_000 }).not.toBe(prima);

        await comando.click();
        await expect.poll(stato, { message: "e torna com'era", timeout: 15_000 }).toBe(prima);
    });
});
