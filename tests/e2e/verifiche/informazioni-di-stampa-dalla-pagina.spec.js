// @ts-check
/**
 * Il pannello delle informazioni di stampa, usato dalla pagina.
 * Riscrittura della parte dall'interfaccia di g19_print_info_scelte.spec.js,
 * di g19_18_print_info_load_modal.spec.js e di
 * g20_07_print_info_modal_edit.spec.js.
 *
 * Sono i dati che finiscono in testa alla verifica stampata: anno, classe,
 * sezione, Istituto, tempo a disposizione, quante copie servono e quante per
 * chi ha bisogno delle varianti. Il docente li compila una volta e poi li
 * ripesca: «Carica» apre l'elenco dei salvataggi, e da lì si sceglie o si
 * corregge.
 *
 * Il giro via API — salvataggio, elenco, cancellazione, chiave a cinque campi
 * e chiave vecchia — è in `verifiche/informazioni-di-stampa`; qui si guarda
 * quel che fa la pagina.
 *
 * Cosa cambia rispetto a prima: le due password lette dall'ambiente e scritte
 * nel modulo di accesso non ci sono più, i comandi si premono invece di essere
 * chiamati con `querySelector(...).click()`, i salvataggi di prova si
 * cancellano anche se il test fallisce, e delle attese a tempo non resta nulla.
 */
const { test, expect, attendiAnimazioniFerme } = require("../support/test");

test.describe("Verifiche — informazioni di stampa dalla pagina", () => {
    test.beforeEach(async ({ contentFactory, studioEsercizio }) => {
        const esercizio = await contentFactory.exercise({ groups: 1, itemsPerGroup: 1, publish: true });
        await studioEsercizio.vaiA(esercizio.studioUrl);
        await studioEsercizio.topbar.attendiPronta();
    });

    test("aprendo il pannello la classe è già quella scelta nella barra laterale", async ({
        studioEsercizio, teacherPage,
    }) => {
        // Il campo non si compila a mano: lo riempie la pagina con la classe
        // che c'è nella barra laterale, così chi stampa non deve ricopiarla.
        const classeScelta = await teacherPage.locator("#sel-cls").inputValue();
        expect(classeScelta, "la barra laterale ha una classe scelta").not.toBe("");

        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await expect
            .poll(() => teacherPage.locator("#classe").inputValue(), {
                message: "la classe del pannello segue quella della barra",
                timeout: 30_000,
            })
            .toBe(classeScelta);
        await expect(
            teacherPage.locator("#addressSchool"),
            "e anche l'indirizzo della scuola è proposto",
        ).not.toHaveValue("");
    });

    test("«Salva» manda le informazioni al server, e l'elenco le ritrova", async ({
        studioEsercizio, teacherApi, teacherPage, cleanup, naming,
    }) => {
        const sezione = naming.unique("sez").slice(-8).toUpperCase();
        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });

        const scrivi = async (/** @type {string} */ id, /** @type {string} */ valore) => {
            const campo = teacherPage.locator(`#${id}`);
            await campo.fill(valore);
            await campo.dispatchEvent("change");
        };
        await scrivi("anno", "2025-26");
        await scrivi("verTime", "55 min");
        await scrivi("sezione", sezione);
        await scrivi("nPrint", "15");
        await scrivi("nPrintDSA", "2");
        await scrivi("nPrintDIS", "1");

        const salvataggio = teacherPage.waitForResponse(
            (r) => /\/api\/teacher\/print-info\b/.test(r.url()) && r.request().method() === "POST",
            { timeout: 30_000 },
        );
        await attendiAnimazioniFerme(teacherPage);
        await teacherPage.locator("#savePrintInfoBtn").click();
        const risposta = await salvataggio;
        expect(risposta.status(), "il salvataggio riesce").toBe(200);

        const nostra = await teacherApi.printInfo.findBySezione(sezione);
        expect(nostra, `il salvataggio della sezione ${sezione} è nell'elenco`).toBeTruthy();
        if (nostra) {
            cleanup.add("teacher", `cancella le informazioni di stampa ${sezione}`, async () => {
                await teacherApi.printInfo.delete(nostra.page_key);
            });
            expect(nostra.verTime, "con il tempo scritto nel pannello").toBe("55 min");
        }
    });

    test("«Carica» apre l'elenco, e scegliere un salvataggio riempie il pannello", async ({
        studioEsercizio, teacherApi, teacherPage, cleanup, naming, env,
    }) => {
        const sezione = naming.unique("car").slice(-8).toUpperCase();
        const salvato = {
            indirizzo: env.terna.indirizzo,
            classe: env.terna.classe,
            materia: env.terna.materia,
            sezione,
            istituto: "Istituto della suite E2E",
            anno: "2025-26",
            verTime: "55 min",
            nPrint: "10",
            nPrintDSA: "1",
            nPrintDIS: "0",
        };
        const { key } = await teacherApi.printInfo.save(salvato);
        cleanup.add("teacher", `cancella le informazioni di stampa ${sezione}`, async () => {
            await teacherApi.printInfo.delete(key);
        });

        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await attendiAnimazioniFerme(teacherPage);
        await teacherPage.locator("#loadPrintInfoBtn").click();

        const elenco = teacherPage.locator("#fm-load-printinfo-modal");
        await expect(elenco, "l'elenco dei salvataggi si apre").toBeVisible({ timeout: 30_000 });
        const scheda = elenco.locator(".fm-pi-card").filter({ hasText: sezione }).first();
        await expect(scheda, "con la scheda del salvataggio appena fatto").toBeVisible({ timeout: 30_000 });
        await expect(scheda, "la scheda mostra i dati per esteso").toContainText("55 min");

        await scheda.locator(".fm-load-row").click();
        await expect(elenco, "scegliendo, l'elenco si chiude").toHaveCount(0, { timeout: 15_000 });
        await expect(teacherPage.locator("#sezione"), "e il pannello prende i dati scelti").toHaveValue(sezione);
    });

    test("dalla scheda si corregge un dato, e la correzione resta", async ({
        studioEsercizio, teacherApi, teacherPage, cleanup, naming, env,
    }) => {
        const sezione = naming.unique("mod").slice(-8).toUpperCase();
        const salvato = {
            indirizzo: env.terna.indirizzo,
            classe: env.terna.classe,
            materia: env.terna.materia,
            sezione,
            istituto: "Istituto della suite E2E",
            anno: "2025-26",
            verTime: "55 min",
            nPrint: "5",
            nPrintDSA: "2",
            nPrintDIS: "1",
        };
        const { key } = await teacherApi.printInfo.save(salvato);
        cleanup.add("teacher", `cancella le informazioni di stampa ${sezione}`, async () => {
            await teacherApi.printInfo.delete(key);
        });

        await studioEsercizio.topbar.premi("info");
        await expect(teacherPage.locator("#infoVer")).toBeVisible({ timeout: 30_000 });
        await attendiAnimazioniFerme(teacherPage);
        await teacherPage.locator("#loadPrintInfoBtn").click();

        const elenco = teacherPage.locator("#fm-load-printinfo-modal");
        const scheda = elenco.locator(".fm-pi-card").filter({ hasText: sezione }).first();
        await expect(scheda).toBeVisible({ timeout: 30_000 });

        await scheda.locator(".fm-edit-row").click();
        const modulo = scheda.locator("form.fm-pi-card-edit");
        await expect(modulo, "la correzione si fa lì dentro").toBeVisible({ timeout: 15_000 });
        await modulo.locator('input[name="verTime"]').fill("90 min");
        await modulo.locator('button[type="submit"]').click();

        const dopo = elenco.locator(".fm-pi-card").filter({ hasText: sezione }).first();
        await expect(dopo, "la scheda mostra il dato corretto").toContainText("90 min", { timeout: 30_000 });

        expect(
            (await teacherApi.printInfo.findBySezione(sezione))?.verTime,
            "e la correzione è arrivata al server",
        ).toBe("90 min");
    });
});
