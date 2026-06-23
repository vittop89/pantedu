/**
 * G20.7 — Click ⚙ Editor topbar apre modal iframe su /area-docente/templates.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Editor topbar btn → iframe modal su /area-docente/templates?embed=1", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(1500);

    // Click ⚙ Editor btn
    await page.locator('#fm-topbar [data-fm-action="editor"]').click();
    await page.waitForTimeout(500);

    // Modal presente
    const modal = page.locator("#fm-vd-templates-modal");
    await expect(modal).toHaveCount(1);
    await expect(modal).toBeVisible();

    // Iframe punta a /area-docente/templates?embed=1
    const iframe = modal.locator("iframe.fm-vd-templates-iframe");
    await expect(iframe).toHaveCount(1);
    const src = await iframe.getAttribute("src");
    expect(src).toContain("/area-docente/templates");
    expect(src).toContain("embed=1");

    // Iframe carica + tree visibile
    const frame = page.frameLocator("#fm-vd-templates-modal iframe");
    await expect(frame.locator("#fm-tvf-tree")).toBeVisible({ timeout: 10000 });
    await expect(frame.locator("#fm-tvf-tree")).toContainText(/verifica\.sty|Caricamento|Elementi comuni/);

    // Header: link "Apri pagina completa"
    await expect(modal.locator(".fm-vd-templates-open-tab")).toHaveAttribute("href", "/area-docente/templates");
    await expect(modal.locator(".fm-vd-templates-open-tab")).toHaveAttribute("target", "_blank");

    // Close via × button
    await modal.locator('[data-action="close"]').click();
    await page.waitForTimeout(300);
    await expect(page.locator("#fm-vd-templates-modal")).toHaveCount(0);
});
