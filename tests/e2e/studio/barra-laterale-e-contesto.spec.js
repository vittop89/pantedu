// @ts-check
/**
 * Barra laterale, contesto della pagina di studio e intestazioni che restano
 * in vista mentre si scorre.
 * Riscrittura di upbar_sidebar_audit.spec.js, topbar_g9_smoke.spec.js e
 * sticky_diagnostics.spec.js.
 *
 * Tre cose che si vedono solo usando la pagina: la barra laterale si chiude e
 * si riapre, e lo ricorda; aprendo un esercizio la pagina entra in «contesto
 * esercizio» e la barra dei filtri arriva con i comandi che spettano a chi la
 * guarda; e mentre si scorre, l'intestazione del gruppo aperto resta
 * ancorata sotto le barre invece di scomparire in cima.
 *
 * Quest'ultima è la parte che la spec storica chiamava «diagnostica»: stampava
 * le altezze e chiudeva con «Assert soft — no regressione». Qui l'ancoraggio è
 * verificato: l'intestazione resta a una distanza dal bordo compresa fra le
 * barre che ha sopra, e non si sposta scorrendo.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente schermate
 * salvate in `tests/e2e-results/artifacts/` (che Playwright svuota a ogni
 * giro), niente stampe, e delle diciannove attese a tempo non resta nulla.
 */
const { test, expect } = require("../support/test");

test.describe("Studio — barra laterale", () => {
    test("la barra laterale si chiude e si riapre, e il contenuto le lascia il posto", async ({ homeDocente, teacherPage }) => {
        await homeDocente.vaiA();

        const corpo = teacherPage.locator("body");
        const interruttore = teacherPage.locator(".fm-sb-slider");
        await expect(corpo, "all'apertura la barra è aperta").not.toHaveClass(/fm-sidebar-closed/);
        await expect(interruttore, "e l'interruttore mostra la croce").toHaveText("✖");

        const margine = async () =>
            teacherPage.evaluate(() => {
                const contenuto = document.getElementById("fm-content");
                return contenuto ? parseInt(getComputedStyle(contenuto).marginLeft, 10) : -1;
            });
        const conBarra = await margine();
        expect(conBarra, "col la barra aperta il contenuto è spostato").toBeGreaterThan(100);

        await interruttore.click();
        await expect(corpo, "premendo l'interruttore la barra si chiude").toHaveClass(/fm-sidebar-closed/);
        await expect(interruttore, "e l'interruttore mostra le tre righe").toHaveText("☰");
        await expect
            .poll(margine, { message: "e il contenuto si riprende lo spazio", timeout: 15_000 })
            .toBeLessThan(60);

        await interruttore.click();
        await expect(corpo, "premendolo di nuovo la barra torna").not.toHaveClass(/fm-sidebar-closed/);
        await expect(interruttore).toHaveText("✖");
    });

    test("la barra laterale ricorda di essere chiusa dopo un ricaricamento", async ({ homeDocente, teacherPage }) => {
        await homeDocente.vaiA();
        await teacherPage.locator(".fm-sb-slider").click();
        await expect(teacherPage.locator("body")).toHaveClass(/fm-sidebar-closed/);

        await teacherPage.reload();
        await expect(teacherPage.locator("body"), "ricaricando resta chiusa").toHaveClass(/fm-sidebar-closed/);
        await expect(teacherPage.locator(".fm-sb-slider"), "e l'interruttore lo mostra").toHaveText("☰");

        // Si rimette com'era: lo stato vive nella sessione del browser.
        await teacherPage.locator(".fm-sb-slider").click();
        await expect(teacherPage.locator("body")).not.toHaveClass(/fm-sidebar-closed/);
    });

    test("il comando del tema scuro cambia la pagina e lo ricorda", async ({ homeDocente, teacherPage }) => {
        await homeDocente.vaiA();
        const corpo = teacherPage.locator("body");
        const comando = teacherPage.locator(".fm-sb-dark").first();
        const eraScuro = await corpo.evaluate((el) => el.classList.contains("fm-dark"));

        await comando.click();
        if (eraScuro) {
            await expect(corpo, "il tema torna chiaro").not.toHaveClass(/fm-dark/);
        } else {
            await expect(corpo, "il tema diventa scuro").toHaveClass(/fm-dark/);
            expect(
                await teacherPage.evaluate(() => localStorage.getItem("fm_dark_mode")),
                "e la scelta viene ricordata",
            ).toBe("1");
        }

        await comando.click();
        // Si rimette com'era per non lasciare la preferenza cambiata.
        if (eraScuro) {
            await expect(corpo).toHaveClass(/fm-dark/);
        } else {
            await expect(corpo).not.toHaveClass(/fm-dark/);
        }
    });
});

test.describe("Studio — contesto della pagina dell'esercizio", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 2, itemsPerGroup: 3, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
    });

    test("aprendo un esercizio la pagina entra in contesto esercizio", async ({ teacherPage }) => {
        await expect(teacherPage, "l'indirizzo è quello dello studio").toHaveURL(/\/studio\/esercizio\//);
        await expect(teacherPage.locator("body"), "e la pagina lo dichiara").toHaveClass(/exercise-context/);
    });

    test("la barra dei filtri arriva con i comandi che spettano a chi la guarda", async ({ studioEsercizio, teacherPage }) => {
        await studioEsercizio.upbar.apri();

        await expect(teacherPage.locator("#btnP"), "il comando dei problemi").toBeAttached();
        await expect(teacherPage.locator("#sel-dif"), "il filtro della difficoltà").toBeAttached();
        await expect(teacherPage.locator(".fm-sb-dark"), "e il comando del tema").toBeAttached();

        // I comandi dei vecchi salvataggi non ci sono più: li ha sostituiti la
        // barra degli strumenti moderna.
        await expect(teacherPage.locator("#btnAct"), "niente comando «attiva»").toHaveCount(0);
        await expect(teacherPage.locator("#btnCopyver"), "niente copia della verifica").toHaveCount(0);
    });

    test("il vecchio riquadro di uscita non c'è più: si esce dal banner della sessione", async ({ teacherPage }) => {
        await expect(teacherPage.locator("#logout-widget-container"), "niente riquadro di uscita").toHaveCount(0);
        await expect(teacherPage.locator("#btnLogout"), "e niente suo bottone").toHaveCount(0);
        await expect(teacherPage.locator('a[href="/logout"]').first(), "si esce dal collegamento nella barra").toBeVisible();
    });

    test("l'intestazione del gruppo aperto resta ancorata mentre si scorre", async ({ studioEsercizio, teacherPage }) => {
        const gruppo = studioEsercizio.gruppo(0);
        await gruppo.apri();

        // Il modulo ancora le intestazioni dei gruppi APERTI
        // (verifica-sticky.js cerca `.fm-collapsible.active`): si aspetta che
        // il contenuto sia davvero disteso, altrimenti la pagina non scorre e
        // non c'è niente da ancorare. Il gruppo in modifica invece
        // l'intestazione la cede al pannello dell'editor, e non si ancora:
        // quello è comportamento voluto, e non è questo il test che lo guarda.
        const intestazione = teacherPage.locator(".fm-groupcollex .fm-collapsible").first();
        await expect(intestazione, "l'intestazione del gruppo è in pagina").toBeVisible();
        await expect
            .poll(
                async () => intestazione.evaluate(
                    (el) => Math.round(el.nextElementSibling?.getBoundingClientRect().height ?? 0),
                ),
                { message: "il contenuto del gruppo si distende", timeout: 15_000 },
            )
            .toBeGreaterThan(0);

        // Le barre sopra decidono a che altezza si ferma: la somma delle loro
        // altezze è il limite superiore, e zero quello inferiore.
        const altezzaDelleBarre = await teacherPage.evaluate(() => {
            const alto = (/** @type {string} */ selettore) => {
                const el = document.querySelector(selettore);
                if (!el) return 0;
                const r = el.getBoundingClientRect();
                return r.height;
            };
            return alto("#fm-topbar") + alto(".fm-upbar") + alto("#scrollbarInfo") + 80;
        });

        // Si scorre finché l'intestazione si ancora davvero, non di un numero
        // fisso: quanto contenuto ci sia sopra di lei dipende dall'esercizio, e
        // gli 800 pixel di prima ogni tanto non bastavano. Il modulo ricalcola
        // solo sull'evento di scorrimento, quindi se la pagina si assesta dopo
        // — MathJax, immagini — nessuno ricalcola più: per questo si riscorre
        // a ogni giro, partendo ogni volta da dove l'intestazione sta adesso.
        await expect
            .poll(
                async () => teacherPage.evaluate(() => {
                    const el = document.querySelector(".fm-groupcollex .fm-collapsible");
                    if (!el) return null;
                    const stato = getComputedStyle(el).position;
                    if (stato !== "fixed") {
                        window.scrollTo(0, window.scrollY + el.getBoundingClientRect().top + 200);
                    }
                    return stato;
                }),
                { message: "l'intestazione si ancora", timeout: 15_000 },
            )
            .toBe("fixed");
        const quantoScorrere = await teacherPage.evaluate(() => window.scrollY);

        const dopoPrimoScorrimento = await intestazione.evaluate((el) => el.getBoundingClientRect().top);
        expect(dopoPrimoScorrimento, "resta dentro la finestra").toBeGreaterThanOrEqual(-4);
        expect(dopoPrimoScorrimento, "sotto le barre che ha sopra").toBeLessThanOrEqual(altezzaDelleBarre);

        // Scorrendo ancora non si muove: è quello che vuol dire «ancorata».
        await teacherPage.evaluate((y) => window.scrollTo(0, y), quantoScorrere + 600);
        const dopoSecondoScorrimento = await intestazione.evaluate((el) => el.getBoundingClientRect().top);
        expect(
            Math.abs(dopoSecondoScorrimento - dopoPrimoScorrimento),
            "continuando a scorrere l'intestazione non si sposta",
        ).toBeLessThanOrEqual(4);
    });
});
