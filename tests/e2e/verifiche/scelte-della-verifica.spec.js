// @ts-check
/**
 * Le scelte della verifica: le tre versioni, il salvataggio e il ritorno.
 * Riscrittura del terzo caso di g19_print_info_scelte.spec.js e di
 * g20_07_auto_restore_scelte.spec.js.
 *
 * Una pagina di studio può servire a montare fino a tre verifiche diverse: v1,
 * v2, v3. Ogni versione ha le sue scelte — quali quesiti, con quale titolo — e
 * si salvano a parte. Tornando sulla pagina, la versione su cui si stava
 * lavorando e il suo titolo si ritrovano da soli: era il difetto per cui il
 * docente ricominciava ogni volta da capo.
 *
 * Cosa cambia rispetto a prima: le due password lette dall'ambiente non ci
 * sono più, i comandi si premono invece di essere chiamati da codice, e
 * l'esercizio su cui si lavora nasce dal test, così le scelte salvate non
 * restano attaccate a una pagina vera.
 */
const { test, expect } = require("../support/test");

test.describe("Verifiche — scelte della pagina", () => {
    /**
     * L'attesa del ripristino automatico delle scelte.
     *
     * Seicento millisecondi dopo che l'interfaccia è pronta, la pagina rilegge
     * dal server le scelte della versione ricordata e riscrive i campi, titolo
     * compreso. Chi scrive prima che quello sia successo si vede cancellare
     * quel che ha scritto: è il motivo per cui questa spec falliva un giro
     * ogni tanto, e per cui i test aspettano che sia passato.
     *
     * @type {Promise<unknown> | null}
     */
    let ripristinoInCorso = null;

    /**
     * Comincia ad aspettare il ripristino. Va chiamata **prima** di aprire la
     * pagina: la richiesta parte da sola poco dopo il caricamento.
     * @param {import("@playwright/test").Page} pagina
     */
    function aspettaIlRipristino(pagina) {
        ripristinoInCorso = pagina.waitForResponse(
            (risposta) => /\/verifiche\/scelte/.test(risposta.url()),
            { timeout: 60_000 },
        );
    }

    /** Attende che il ripristino automatico sia avvenuto. */
    async function attendiIlRipristino() {
        expect(ripristinoInCorso, "l'attesa del ripristino era stata avviata").not.toBeNull();
        await ripristinoInCorso;
    }

    test.beforeEach(async ({ contentFactory, studioEsercizio, teacherPage }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 2, publish: true });
        aspettaIlRipristino(teacherPage);
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("le tre versioni si escludono a vicenda", async ({ teacherPage }) => {
        await attendiIlRipristino();
        const versioni = teacherPage.locator(".fm-version-btn");
        await expect(versioni, "le versioni sono tre").toHaveCount(3);

        await versioni.nth(1).click();
        await expect(versioni.nth(1), "la seconda diventa quella attiva").toHaveClass(/fm-version-btn--active/, { timeout: 15_000 });
        await expect(versioni.nth(0), "e la prima si spegne").not.toHaveClass(/fm-version-btn--active/);
    });

    test("«Salva scelte» le manda al server e «Carica scelte» le riporta indietro", async ({
        studioEsercizio, teacherPage, naming,
    }) => {
        await attendiIlRipristino();
        const titolo = naming.unique("scelte");
        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await teacherPage.locator("#verTitle").fill(titolo);

        const salvataggio = teacherPage.waitForResponse(
            (r) => /\/verifiche\/scelte\b/.test(r.url()) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await teacherPage.locator(".fm-salva-scelte-btn").first().press("Enter");
        expect((await salvataggio).status(), "le scelte sono salvate").toBe(200);

        // Si cancella il titolo dal campo: se il caricamento funziona, torna.
        await teacherPage.locator("#verTitle").fill("");
        const caricamento = teacherPage.waitForResponse(
            (r) => /\/verifiche\/scelte\b/.test(r.url()) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await teacherPage.locator(".fm-carica-scelte-btn").first().press("Enter");
        expect((await caricamento).status(), "il caricamento risponde").toBe(200);
        await expect(teacherPage.locator("#verTitle"), "e il titolo torna com'era")
            .toHaveValue(titolo, { timeout: 30_000 });
    });

    test("tornando sulla pagina la versione e il titolo si ritrovano da soli", async ({
        contentFactory, studioEsercizio, teacherPage, naming,
    }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        aspettaIlRipristino(teacherPage);
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
        await attendiIlRipristino();

        // Si sceglie la seconda versione prima di scrivere il titolo: premere
        // una versione ricarica le sue scelte, e sovrascriverebbe quel che c'è.
        const versioni = teacherPage.locator(".fm-version-btn");
        await versioni.nth(1).click();
        await expect(versioni.nth(1)).toHaveClass(/fm-version-btn--active/, { timeout: 15_000 });

        const titolo = naming.unique("ritrovato");
        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await teacherPage.locator("#verTitle").fill(titolo);

        const salvataggio = teacherPage.waitForResponse(
            (r) => /\/verifiche\/scelte\b/.test(r.url()) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await teacherPage.locator(".fm-salva-scelte-btn").first().press("Enter");
        expect((await salvataggio).status()).toBe(200);

        // Si va altrove e si torna: la pagina deve ricordarsi dov'era.
        await teacherPage.goto("/area-docente");
        aspettaIlRipristino(teacherPage);
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
        await attendiIlRipristino();

        await expect
            .poll(
                () => teacherPage.locator(".fm-version-btn.fm-version-btn--active").getAttribute("data-version"),
                { message: "la versione su cui si lavorava è di nuovo quella attiva", timeout: 30_000 },
            )
            .toBe("v2");
        await expect(teacherPage.locator("#verTitle"), "e il titolo è tornato")
            .toHaveValue(titolo, { timeout: 30_000 });
    });
});
