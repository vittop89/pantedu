// @ts-check
/**
 * Area docente → Modelli: le tre schede e l'editor dei file TeX.
 * Riscrittura di g22_s15bis_fase5_templates_full.spec.js.
 *
 * La pagina raccoglie i modelli TeX del docente in tre schede — verifiche,
 * esercizi, risorse docente. Da due di esse si apre un editor: un albero dei
 * file a sinistra, il testo del file a destra. L'editor non si apre da solo:
 * lo apre un comando, e finché non lo si preme le schede restano navigabili
 * (prima la finestra si apriva da sé e le copriva).
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente stampe di
 * console (erano trentadue, con un `afterEach` che rovesciava nel registro
 * ogni errore della pagina: ora lo fa la diagnostica comune, allegando il
 * rapporto solo quando il test fallisce), e delle ventitré attese a tempo non
 * resta nulla.
 *
 * L'ultimo caso della spec storica — il comando «Editor» della barra che apre
 * i modelli in una cornice — sta in `editor/finestra-modelli`: qui resta la
 * parte che quello non copre, cioè che dentro la cornice l'editor si apre.
 */
const { test, expect } = require("../support/test");

/** Le tre schede, con l'etichetta che la pagina mostra quando è quella attiva. */
const SCHEDE = [
    { chiave: "verifiche", etichetta: "Verifiche" },
    { chiave: "esercizi", etichetta: "Esercizi" },
    { chiave: "risdoc", etichetta: "Modelli risdoc" },
];

/** Apre la pagina dei modelli su una scheda. */
async function apriScheda(/** @type {import("@playwright/test").Page} */ page, /** @type {string} */ chiave) {
    await page.goto(`/area-docente/templates?tab=${chiave}`);
    await expect(page.locator(".fm-subtab--active")).toBeVisible({ timeout: 30_000 });
}

/** Preme il comando che apre l'editor e aspetta che sia pronto. */
async function apriEditor(/** @type {import("@playwright/test").Page} */ page, /** @type {string} */ comando) {
    await expect(page.locator(".fm-vp-modal"), "l'editor non si apre da solo").toHaveCount(0);
    await page.locator(comando).click();
    const finestra = page.locator(".fm-vp-modal");
    await expect(finestra, "l'editor si apre").toBeVisible({ timeout: 30_000 });
    await expect(page.locator("[data-cm-host] .cm-editor"), "con il testo del file").toBeVisible({ timeout: 30_000 });
    // L'albero dei file arriva dopo: si aspetta la prima voce, non un tempo.
    await expect(page.locator("[data-filetree] [data-path]").first(), "con l'albero dei file").toBeVisible({ timeout: 30_000 });
    return finestra;
}

test.describe("Area docente — modelli TeX", () => {
    test("le tre schede si aprono, e da una si passa all'altra", async ({ teacherPage }) => {
        for (const scheda of SCHEDE) {
            await apriScheda(teacherPage, scheda.chiave);
            await expect(teacherPage.locator(".fm-subtab--active"), `scheda «${scheda.etichetta}»`)
                .toContainText(scheda.etichetta);
        }

        // Senza finestre aperte davanti, le schede si premono direttamente.
        await apriScheda(teacherPage, "verifiche");
        await teacherPage.locator('a.fm-subtab[href*="tab=esercizi"]').click();
        await expect(teacherPage.locator(".fm-subtab--active"), "si passa agli esercizi con un clic").toContainText("Esercizi");
        await expect(teacherPage.locator("body"), "e la pagina è quella delle collezioni").toContainText(/collezione/i);
    });

    test("l'editor delle verifiche mostra i file e cambia file quando si sceglie", async ({ teacherPage }) => {
        await apriScheda(teacherPage, "verifiche");
        await apriEditor(teacherPage, "#fm-tvf-open");

        const file = teacherPage.locator("[data-filetree] [data-path]");
        await expect(file.first(), "l'albero dei file è popolato").toBeVisible();
        expect(await file.count(), "con più di un file").toBeGreaterThan(1);
        await expect(
            teacherPage.locator("[data-cm-host] .cm-content"),
            "il testo del file non è vuoto",
        ).not.toBeEmpty();

        // I file di questa pagina appartengono ai modelli del docente, non a un
        // documento numerato: l'identificativo è una parola, e un tempo il
        // codice che apriva il file lo leggeva come numero e si fermava.
        await expect(file.first(), "i file sono quelli dei modelli").toHaveAttribute("data-doc-id", "teacher-templates");

        const primo = String(await file.nth(0).getAttribute("data-path"));
        const secondo = String(await file.nth(1).getAttribute("data-path"));
        await file.nth(0).click();
        await expect(teacherPage.locator("[data-editor-breadcrumb]"), "si apre il primo file").toContainText(primo);
        await file.nth(1).click();
        const percorso = teacherPage.locator("[data-editor-breadcrumb]");
        await expect(percorso, "e poi il secondo").toContainText(secondo);
        await expect(percorso, "che non è più il primo").not.toContainText(primo);
    });

    test("aprire i file delle risorse docente non li segna come modificati", async ({ teacherPage }) => {
        await apriScheda(teacherPage, "risdoc");
        await apriEditor(teacherPage, "#fm-trd-open");

        const file = teacherPage.locator("[data-filetree] [data-path]");
        const quanti = await file.count();
        expect(quanti, "l'albero ha i file dei modelli").toBeGreaterThanOrEqual(1);

        for (let i = 0; i < quanti; i++) {
            const percorso = await file.nth(i).getAttribute("data-path");
            await file.nth(i).click();
            await expect(teacherPage.locator("[data-editor-breadcrumb]"), `si apre ${percorso}`).toContainText(String(percorso));
            // Aprire un file non è modificarlo: il pallino delle modifiche non
            // deve comparire (compariva per una differenza di fine riga).
            await expect(
                teacherPage.locator(".fm-vp-filetree__item--dirty"),
                `aprire ${percorso} non lo segna come modificato`,
            ).toHaveCount(0);
        }
    });

    test("l'editor si apre anche dentro la cornice aperta dalla barra", async ({ contentFactory, studioEsercizio, teacherPage }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
        await studioEsercizio.topbar.premi("editor");

        const dentro = teacherPage.frameLocator(".fm-vd-templates-iframe");
        await expect(dentro.locator("#fm-tvf-open"), "il comando che apre l'editor").toBeVisible({ timeout: 30_000 });
        await dentro.locator("#fm-tvf-open").click();
        await expect(dentro.locator(".fm-vp-modal"), "l'editor si apre nella cornice").toBeVisible({ timeout: 30_000 });
        await expect(dentro.locator("[data-cm-host] .cm-editor"), "con il testo del file").toBeVisible({ timeout: 30_000 });
    });
});
