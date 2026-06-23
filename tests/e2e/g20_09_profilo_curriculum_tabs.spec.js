const { test, expect } = require("@playwright/test");

// Verifica fix: tab Classi/Materie del "Curriculum dell'istituto attivo"
// erano nascosti da .fm-d-none (style.display="" non bastava). + pannello
// "Le mie..." raggruppato per istituto (no falsi doppioni).
test("Profilo: tab Classi/Materie visibili + gruppi per istituto", async ({ page }) => {
    test.setTimeout(60000);
    const logs = [];
    page.on("console", m => logs.push(`[${m.type()}] ${m.text()}`));

    await page.goto("/login");
    await page.locator("input[name=username]").fill(process.env.E2E_TEACHER_USER || "superadmin");
    await page.locator("input[name=password]").fill(process.env.E2E_TEACHER_PASS || "");
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/area-docente/profilo");
    await page.waitForLoadState("networkidle");
    await page.evaluate(() => {
        for (const id of ["fm-modal-overlay", "fm-cookie-modal", "bottom-bar"]) {
            const o = document.getElementById(id); if (o) o.style.display = "none";
        }
    });

    // Attendi render editor (form add indirizzi).
    await page.waitForSelector('.fm-curr-panel[data-panel="indirizzi"] [data-add-form]', { timeout: 10000 });

    // Tab CLASSI → click via dispatchEvent (cookie-banner occlude, gotcha noto).
    await page.locator('#fm-curr-tabs .fm-subtab[data-kind="classi"]').dispatchEvent("click");
    const classiPanel = page.locator('.fm-curr-panel[data-panel="classi"]');
    await expect(classiPanel).toBeVisible();
    await expect(classiPanel.locator('[data-add-form]')).toBeVisible();
    const classiRows = await classiPanel.locator('table.fm-curr-table tbody tr').count();
    console.log(`CLASSI righe editor: ${classiRows}`);
    expect(classiRows).toBeGreaterThan(0);
    // checkbox "Attiva" presente (= spunta sidebar).
    expect(await classiPanel.locator('input[name="active"]').count()).toBeGreaterThan(0);

    // Tab MATERIE.
    await page.locator('#fm-curr-tabs .fm-subtab[data-kind="materie"]').dispatchEvent("click");
    const materiePanel = page.locator('.fm-curr-panel[data-panel="materie"]');
    await expect(materiePanel).toBeVisible();
    await expect(materiePanel.locator('[data-add-form]')).toBeVisible();
    // indirizzi ora nascosto.
    await expect(page.locator('.fm-curr-panel[data-panel="indirizzi"]')).toBeHidden();

    // Pannello "Le mie..." raggruppato per istituto.
    const heads = await page.locator('#fm-profile-pivot .fm-pivot-list__head').count();
    console.log(`Header gruppi-istituto nel pannello "Le mie...": ${heads}`);
    expect(heads).toBeGreaterThan(0);

    console.log("=== CONSOLE ===\n" + logs.join("\n"));
});
