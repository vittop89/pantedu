/** G22.S15.bis Fase 5 — GitHub sync smoke (status + sezione dashboard). */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test.describe("GitHub sync", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/login");
        await page.locator("input[name=username]").fill(USERNAME);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");
    });

    test("/api/teacher/github/status risponde JSON", async ({ page }) => {
        const r = await page.request.get("/api/teacher/github/status");
        expect(r.status()).toBe(200);
        const j = await r.json();
        expect(j.ok).toBe(true);
        // Configurato o no, deve esserci il flag
        expect(typeof j.configured).toBe("boolean");
    });

    test("Dashboard: sezione GitHub presente con bottoni", async ({ page }) => {
        await page.goto("/teacher/dashboard");
        await page.waitForTimeout(800);
        const cookieBtn = page.locator("#fm-cookie-modal button").first();
        if (await cookieBtn.count() > 0) await cookieBtn.click().catch(() => {});

        await expect(page.locator("#fm-github-section")).toBeVisible();
        await expect(page.locator("#fm-github-configure")).toBeVisible();
        // Lo status pill deve avere un label (non vuoto)
        await page.waitForTimeout(800);
        const label = await page.locator("#fm-github-status .fm-drive-label").textContent();
        expect(label?.length || 0).toBeGreaterThan(5);
    });

    test("Topbar: 4 sync buttons visibili (Drive/Local/GitHub/All)", async ({ page }) => {
        // Vai su pagina con session banner teacher (esercizio)
        await page.goto("/studio/esercizio/sc/3/MAT/2");
        await page.waitForTimeout(2000);

        // Verifica presenza dei 4 pulsanti nella sync bar
        const driveBtn = page.locator(".fm-session-drive-sync");
        const localBtn = page.locator(".fm-session-local-sync");
        const githubBtn = page.locator(".fm-session-github-sync");
        const allBtn = page.locator(".fm-session-sync-all");
        await expect(driveBtn).toBeVisible({ timeout: 5000 });
        await expect(localBtn).toBeVisible();
        await expect(githubBtn).toBeVisible();
        await expect(allBtn).toBeVisible();
        // GitHub button NON deve essere disabled (ora funziona)
        const disabled = await githubBtn.getAttribute("disabled");
        expect(disabled).toBeNull();
    });
});
