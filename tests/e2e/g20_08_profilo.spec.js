const { test, expect } = require("@playwright/test");
test("Profilo docente: lista + add/remove istituto", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill("superadmin");
    await page.locator("input[name=password]").fill((process.env.E2E_TEACHER_PASS || ""));
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/area-docente/profilo");
    await page.waitForLoadState("networkidle");

    // Hide overlay
    await page.evaluate(() => {
        const o = document.getElementById("fm-modal-overlay");
        if (o) o.style.display = "none";
    });

    // Verifica title
    const h1 = await page.locator("h1").textContent();
    expect(h1).toContain("Profilo");

    // Aspetta il caricamento current
    await page.waitForFunction(() => {
        const div = document.getElementById("fm-profile-current");
        return div && !div.textContent.includes("Caricamento");
    }, { timeout: 10000 });

    const tableRows = await page.locator("#fm-profile-current tbody tr").count();
    console.log(`Istituti collegati: ${tableRows}`);
    expect(tableRows).toBeGreaterThan(0);

    // Verifica che il XXPS00000A sia listato
    const codes = await page.locator("#fm-profile-current tbody tr td:first-child").allTextContents();
    console.log("Codes:", codes);
    expect(codes).toContain("XXPS00000A");
});
