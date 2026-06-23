/**
 * G20.7 — Topbar btns Overleaf/ZIP/VSC sostituiti da logo SVG.
 * SalvaTEX label → TEX.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Topbar logos: Overleaf/ZIP/VSC img + SalvaTEX -> TEX", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1000);

    // SalvaTEX label = TEX
    const tex = page.locator('#fm-topbar [data-fm-action="salvatex"] .fm-topbar__lbl');
    await expect(tex).toHaveText("TEX");

    // Overleaf btn → img logo
    const overleafImg = page.locator('#fm-topbar [data-fm-action="overleaf"] img.fm-topbar__logo');
    await expect(overleafImg).toHaveCount(1);
    await expect(overleafImg).toHaveAttribute("src", "/img/topbar/overleaf.svg");

    // ZIP btn → bold testo (no img)
    const zipBtn = page.locator('#fm-topbar [data-fm-action="zip"]');
    await expect(zipBtn.locator("img")).toHaveCount(0);
    await expect(zipBtn.locator("strong")).toHaveText("ZIP");

    // VSC btn → img logo
    const vscImg = page.locator('#fm-topbar [data-fm-action="vsc"] img.fm-topbar__logo');
    await expect(vscImg).toHaveCount(1);
    await expect(vscImg).toHaveAttribute("src", "/img/topbar/vscode.svg");

    // Verifica che i 2 SVG residui siano servibili (200 OK + content-type svg).
    // ZIP usa testo bold, non img.
    for (const url of ["/img/topbar/overleaf.svg", "/img/topbar/vscode.svg"]) {
        const r = await page.request.get(url);
        expect(r.ok(), `${url} 200`).toBeTruthy();
        const ct = r.headers()["content-type"] || "";
        expect(ct, `${url} content-type`).toMatch(/svg|xml/);
    }
});
