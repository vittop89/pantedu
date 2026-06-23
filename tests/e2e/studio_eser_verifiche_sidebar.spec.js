/**
 * Studio/esercizio — VERIFICHE CORRELATE (caricate dinamicamente) + SIDEBAR.
 * Le verifiche correlate arrivano via GET /api/study/related-verifiche.html e
 * sono rese come .fm-contract-wrap[data-kind="verifica"]. La sidebar ha 6 sezioni.
 */
const { test } = require("@playwright/test");
const H = require("./studio-eser-helpers");
const { expect } = H;

test.describe("studio/esercizio — verifiche correlate + sidebar", () => {
    test("le verifiche correlate si caricano dinamicamente e rendono contenuto", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        let relatedCalled = false;
        page.on("response", (r) => { if (/related-verifiche\.html/.test(r.url()) && r.status() === 200) relatedCalled = true; });
        await H.loginTeacher(page);
        await H.gotoEser(page, "1291");
        // attende il caricamento dinamico delle verifiche correlate
        await page.waitForFunction(
            () => document.querySelectorAll("#type_verAll, .fm-related-verifiche, .fm-contract-wrap[data-kind='verifica']").length > 0,
            { timeout: 15000 },
        ).catch(() => {});
        expect(relatedCalled, "chiamata API related-verifiche.html").toBe(true);
        const v = await page.evaluate(() => ({
            wraps: document.querySelectorAll(".fm-contract-wrap").length,
            verificaWraps: document.querySelectorAll(".fm-contract-wrap[data-kind='verifica']").length,
            esercizioWraps: document.querySelectorAll(".fm-contract-wrap[data-kind='esercizio']").length,
        }));
        expect(v.esercizioWraps, "contract esercizio reso").toBeGreaterThanOrEqual(1);
        expect(v.verificaWraps, "verifica correlata resa").toBeGreaterThanOrEqual(1);
        expect(errors, errors.join("\n")).toEqual([]);
    });

    test("la sidebar ha le 6 sezioni e i toggle funzionano", async ({ page }) => {
        const errors = H.trackJsErrors(page);
        await H.loginTeacher(page);
        await H.gotoEser(page, "1291");
        const sections = await page.$$eval("#fm-sb-scroll .fm-sb-sec[data-sidepage], .fm-sb-sec[data-sidepage]", (ns) => [...new Set(ns.map((n) => n.getAttribute("data-sidepage")))]);
        expect(sections.sort(), "6 sezioni sidebar").toEqual(["bes", "eser", "lab", "mappe", "risdoc", "verif"].sort());
        // toggle di una sezione (Esercizi) cambia il bordo
        const before = await page.locator('.fm-sb-sec[data-sidepage="eser"]').evaluate((el) => getComputedStyle(el).borderStyle);
        await page.evaluate(() => document.querySelector('.fm-sb-sec[data-sidepage="eser"]')?.dispatchEvent(new MouseEvent("click", { bubbles: true })));
        await page.waitForTimeout(400);
        const after = await page.locator('.fm-sb-sec[data-sidepage="eser"]').evaluate((el) => getComputedStyle(el).borderStyle);
        expect(after, `bordo sezione cambia (before=${before})`).not.toBe(before);
        expect(errors, errors.join("\n")).toEqual([]);
    });
});
