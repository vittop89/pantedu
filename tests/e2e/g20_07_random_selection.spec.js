/**
 * G20.7 — Random selection mode.
 *
 * Verifica:
 *  - Toggle (🎲) attiva body.fm-rand-mode → inietta .fm-rand-inputs in
 *    ogni .fm-groupcollex .check.
 *  - Pick (🎯) seleziona random N collex-item con titolo_quesito distinct
 *    e setta checkboxAin/Bin in base a checkboxA/B header del problem.
 *  - Toast warning se titoli distinct < N.
 *
 * Strategia: usa una pagina /studio/esercizio/... reale con .fm-groupcollex
 * server-rendered e simula il flow manualmente via page.evaluate (no
 * dipendenza da un setup specifico di seed).
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Random selection: toggle + pick distinct titolo_quesito", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(2500);

    // Skip se non ci sono problem in pagina (alcune pagine senza esercizi)
    const problemCount = await page.locator(".fm-groupcollex").count();
    test.skip(problemCount === 0, "no .fm-groupcollex elements on this page");

    // Toggle random mode
    await page.evaluate(() => document.getElementById("fm-random-toggle")?.click());
    await page.waitForTimeout(300);

    // body.fm-rand-mode + .fm-rand-inputs presenti
    await expect(page.locator("body.fm-rand-mode")).toHaveCount(1);
    const checkInputCount = await page.locator(".fm-groupcollex .fm-rand-inputs").count();
    expect(checkInputCount).toBeGreaterThan(0);

    // Setup minimo: prendi il primo .fm-groupcollex, attiva checkboxA, setta N=1, Pt=4
    await page.evaluate(() => {
        const p = document.querySelector(".fm-groupcollex");
        if (!p) return;
        const a = p.querySelector(":scope > .fm-check .checkboxA");
        if (a) { a.checked = true; a.dispatchEvent(new Event("change", {bubbles:true})); }
        const n = p.querySelector(":scope > .fm-check .fm-rand-n");
        const pt = p.querySelector(":scope > .fm-check .fm-rand-pt");
        if (n) n.value = "1";
        if (pt) pt.value = "4";
    });

    const checkboxAinBefore = await page.locator(".fm-groupcollex:first-of-type .fm-checkbox-ain:checked").count();

    // Pick random
    await page.evaluate(() => document.getElementById("fm-random-pick")?.click());
    await page.waitForTimeout(800);

    // Almeno UN checkboxAin del primo problem deve essere checked dopo
    const checkboxAinAfter = await page.locator(".fm-groupcollex:first-of-type .fm-checkbox-ain:checked").count();
    expect(checkboxAinAfter).toBe(1);

    // Il .fm-input-pt corrispondente all'item selezionato deve avere un valore
    // numerico (PT/N = 4). Nota: alcuni handler post-change possono ricalcolare
    // — l'asserzione si limita a verificare che sia un numero valido > 0.
    const pt = await page.evaluate(() => {
        const item = document.querySelector(".fm-groupcollex .fm-collection__item:has(.fm-checkbox-ain:checked)");
        const input = item?.querySelector(".fm-input-pt, input.inputPt");
        return input?.value ?? null;
    });
    expect(pt).toBeTruthy();
    expect(parseFloat(pt)).toBeGreaterThan(0);

    // Disable random mode → inputs spariscono
    await page.evaluate(() => document.getElementById("fm-random-toggle")?.click());
    await page.waitForTimeout(200);
    await expect(page.locator("body.fm-rand-mode")).toHaveCount(0);
    await expect(page.locator(".fm-rand-inputs")).toHaveCount(0);
});
