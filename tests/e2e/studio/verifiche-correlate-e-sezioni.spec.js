// @ts-check
/**
 * Verifiche correlate all'esercizio, e le sei sezioni della barra laterale.
 * Riscrittura di studio_eser_verifiche_sidebar.spec.js e del primo caso di
 * studio_eser_wizard.spec.js.
 *
 * Aprendo un esercizio, la pagina va a cercare le verifiche che ne parlano —
 * quelle che hanno lo stesso argomento — e le mette in fondo, così il docente
 * vede in un colpo l'esercizio e le prove che ne sono nate. Il caricamento è
 * differito: arriva dopo, e la pagina si allunga.
 *
 * La barra laterale ha sei sezioni, e premerne una la apre segnalandolo.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente
 * `dispatchEvent("click")`, e la coppia esercizio/verifica nasce dal test
 * invece di essere la copia fissa numero 1291 — che sul computer di chi non ha
 * quel dump non esiste.
 */
const { test, expect } = require("../support/test");

test.describe("Studio esercizio — verifiche correlate e sezioni", () => {
    test("le verifiche che parlano dell'esercizio si caricano e compaiono in fondo", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        // La coppia si riconosce dall'argomento: la verifica ha come argomento
        // il titolo dell'esercizio.
        const coppia = await contentFactory.pair({ gruppiNellaVerifica: 2 });

        const chiamata = teacherPage.waitForResponse(
            (r) => /related-verifiche\.html/.test(r.url()) && r.status() === 200,
            { timeout: 30_000 },
        );
        await studioEsercizio.vaiA(coppia.esercizio.studioUrl);
        await chiamata;

        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="esercizio"]').first(),
            "l'esercizio è reso",
        ).toBeAttached({ timeout: 30_000 });
        await expect(
            teacherPage.locator('.fm-contract-wrap[data-kind="verifica"]').first(),
            "e la verifica correlata pure",
        ).toBeAttached({ timeout: 30_000 });

        // La verifica arriva dopo, e i comandi della scelta vanno rimessi
        // anche sui suoi quesiti: senza, la parte in fondo alla pagina
        // resterebbe da guardare e basta.
        const contenitore = teacherPage.locator("#type_verAll");
        await expect(contenitore, "la verifica correlata ha il suo posto").toBeAttached({ timeout: 30_000 });
        await expect
            .poll(() => contenitore.locator(".fm-groupcollex > .fm-pos-check-es .selection").count(), {
                message: "i comandi della scelta arrivano anche sulla verifica correlata",
                timeout: 30_000,
            })
            .toBeGreaterThan(0);
    });

    test("la barra laterale ha le sue sei sezioni, e premerne una la apre", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        const sezioni = await teacherPage
            .locator(".fm-sb-sec[data-sidepage]")
            .evaluateAll((elementi) => [...new Set(elementi.map((el) => el.getAttribute("data-sidepage")))].sort());
        expect(sezioni, "mappe, esercizi, laboratorio, verifiche, BES/DSA, risorse docente")
            .toEqual(["bes", "eser", "lab", "mappe", "risdoc", "verif"]);

        const esercizi = teacherPage.locator('.fm-sb-sec[data-sidepage="eser"]');
        const bordo = async () => esercizi.evaluate((el) => getComputedStyle(el).borderStyle);
        const prima = await bordo();
        await esercizi.click();
        await expect
            .poll(bordo, { message: "la sezione premuta si segnala come aperta", timeout: 15_000 })
            .not.toBe(prima);
    });

    test("per il docente la pagina è già in modalità verifica, e il comando TEX/PDF apre la generazione", async ({
        contentFactory, studioEsercizio, teacherPage,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);

        await expect(
            teacherPage.locator("body"),
            "al docente la pagina si apre già in modalità verifica",
        ).toHaveClass(/fm-verifica-mode/, { timeout: 30_000 });

        await studioEsercizio.topbar.premi("salvatex");
        await expect(
            teacherPage.locator("#infoVer, .fm-scelte-verifica-wrapper, #fm-vd-genera-modal").first(),
            "il comando TEX/PDF apre l'assemblaggio della verifica",
        ).toBeVisible({ timeout: 30_000 });
    });
});
