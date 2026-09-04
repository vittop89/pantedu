/**
 * G20.6 — verifica che Info + verTitlePrefix + verTitle siano dentro
 * .fm-printinfo-actions, dopo #loadPrintInfoBtn (zone --eser).
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Info + verTitle dentro .fm-printinfo-actions dopo loadPrintInfoBtn", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    // G20.7 (post-update): verTitle/Prefix NON sono piu' nel topbar zone scelte;
    // vivono in .fm-titolo.fm-related-header (montato dinamicamente).
    //  zone 2 .fm-printinfo-actions: [💾 save | 📂 load | ⓘ Info]
    //  zone 3 .scelte-verifica-wrapper: [help-circle | 💾 salva | 📂 carica |
    //                                    versione | v1v2v3 | 🎲 toggle | 🎯 pick]
    const printinfo = page.locator("#fm-topbar .fm-printinfo-actions");
    const scelte    = page.locator("#fm-topbar .scelte-verifica-wrapper");
    await expect(printinfo).toHaveCount(1);
    await expect(scelte).toHaveCount(1);

    // Zone 2: save/load/Info; verTitle/Prefix assenti
    await expect(printinfo.locator("#savePrintInfoBtn")).toHaveCount(1);
    await expect(printinfo.locator("#loadPrintInfoBtn")).toHaveCount(1);
    await expect(printinfo.locator('[data-fm-action="info"]')).toHaveCount(1);
    await expect(printinfo.locator("#verTitle")).toHaveCount(0);
    await expect(printinfo.locator("#verTitlePrefix")).toHaveCount(0);

    // Zone 3: niente verTitle/Prefix nel topbar
    await expect(scelte.locator(".salva-scelte-btn")).toHaveCount(1);
    await expect(scelte.locator(".carica-scelte-btn")).toHaveCount(1);
    await expect(scelte.locator(".scelte-versioni")).toHaveCount(1);
    await expect(scelte.locator("#fm-random-toggle")).toHaveCount(1);
    await expect(scelte.locator("#fm-random-pick")).toHaveCount(1);
    await expect(scelte.locator("#verTitle")).toHaveCount(0);
    await expect(scelte.locator("#verTitlePrefix")).toHaveCount(0);

    // verTitle/Prefix devono essere SOMEWHERE nella pagina (in
    // #wrapInfoVer source o in .fm-related-header se la sezione c'e').
    await expect(page.locator("#verTitle")).toHaveCount(1);
    await expect(page.locator("#verTitlePrefix")).toHaveCount(1);

    // Ordine zone 2: save → load → Info
    const order2 = await printinfo.evaluate((el) =>
        Array.from(el.children).map(c => c.id || c.dataset?.fmAction || c.className)
    );
    expect(order2).toEqual(["savePrintInfoBtn", "loadPrintInfoBtn", "info"]);

    // Ordine zone 3: help-circle → salva → carica → versione → versioni →
    //                random-toggle → random-pick
    const order3 = await scelte.evaluate((el) =>
        Array.from(el.children).map(c => c.id || c.className.replace(
            /^.*?(help-circle|salva-scelte-btn|carica-scelte-btn|scelte-versioni).*$/, "$1"))
    );
    expect(order3).toEqual([
        "help-circle",
        "salva-scelte-btn",
        "carica-scelte-btn",
        "versione",
        "scelte-versioni",
        "fm-random-toggle",
        "fm-random-pick",
    ]);
});
