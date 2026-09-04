/**
 * U5 e2e — /risdoc/view/{id} rende template con topbar + body.
 */
const { test, expect } = require("@playwright/test");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false, date: new Date().toISOString(),
        }));
        sessionStorage.clear();
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test.describe("U5: template view", () => {
    test("visita /risdoc/view/{id} → topbar + body + edit btn", async ({ page }) => {
        await login(page);
        // pick first template id via API
        const apiRes = await page.request.get("/api/risdoc/templates?origin=risdoc&category=MODELLI");
        const apiJson = await apiRes.json();
        const tid = apiJson.templates?.[0]?.id;
        expect(tid).toBeTruthy();

        const resp = await page.goto(`/risdoc/view/${tid}`);
        expect(resp.status()).toBe(200);

        // Topbar
        await expect(page.locator(".fm-risdoc-topbar")).toBeVisible();
        await expect(page.locator(".fm-risdoc-topbar strong")).toContainText("MODELLI");
        // Action buttons
        await expect(page.locator('[data-action="zip"]')).toBeVisible();
        await expect(page.locator('[data-action="overleaf"]')).toBeVisible();
        // Body renders
        await expect(page.locator("#fm-risdoc-content")).toBeVisible();
        const bodyHtml = await page.locator("#fm-risdoc-content").innerHTML();
        expect(bodyHtml.length).toBeGreaterThan(100);
        // Source badge
        await expect(page.locator(".fm-risdoc-badge")).toContainText(/Sorgente|Override/);
    });

    test("docente super-admin vede btn 'Modifica contenuto'", async ({ page }) => {
        await login(page);
        const api = await page.request.get("/api/risdoc/templates");
        const tid = (await api.json()).templates?.[0]?.id;
        await page.goto(`/risdoc/view/${tid}`);
        await expect(page.locator('a[href*="/risdoc/edit/"]')).toBeVisible();
    });

    test("404 per id inesistente", async ({ page }) => {
        await login(page);
        const resp = await page.goto("/risdoc/view/99999999");
        expect(resp.status()).toBe(404);
    });
});
