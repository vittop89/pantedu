/**
 * Full audit: visita home → naviga a tutte le aree principali, raccoglie
 * errori console JS e network 4xx/5xx; verifica integrazioni DB↔pagina.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function captureErrors(page) {
    const errors = [];
    const failed = [];
    page.on("console", msg => {
        if (msg.type() === "error") errors.push(msg.text());
    });
    page.on("response", res => {
        const u = res.url();
        if (res.status() >= 400 && !u.includes("favicon")) failed.push(`${res.status()} ${u}`);
    });
    return { errors, failed };
}

test.describe("Audit completo (admin)", () => {
    test("home renderizza e bootstrap.js carica window.FM", async ({ page }) => {
        const { errors, failed } = await captureErrors(page);
        await page.goto("/");
        await page.waitForFunction(() => window.FM?.Api && window.FM?.Endpoints);
        const critical = errors.filter(e => !/gas-client|MIME type|Failed to load resource/i.test(e));
        expect(critical, critical.join("\n")).toEqual([]);
        const networkBad = failed.filter(f => !/gas-client/i.test(f));
        expect(networkBad, networkBad.join("\n")).toEqual([]);
    });

    test("admin dashboard accessibile", async ({ page }) => {
        await loginAdmin(page);
        const res = await page.goto("/admin/dashboard");
        expect(res.status()).toBeLessThan(400);
    });

    test("teacher dashboard renderizza per admin (rank 100 ≥ 40)", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/teacher/dashboard");
        await expect(page.locator('h1')).toContainText(/area docente/i);
        // verifica che lo script verifiche async finisca
        await page.waitForFunction(() => {
            const el = document.getElementById('fm-tv-list');
            return el && !/Caricamento/i.test(el.textContent);
        }, { timeout: 5000 });
    });

    test("/exercises filtri DB-backed funzionano", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/exercises");
        await page.selectOption('select[name="materia"]', 'MAT');
        await page.click('button[type="submit"]');
        await page.waitForFunction(() => {
            const el = document.getElementById('fm-ex-results');
            return el && /risultati/i.test(el.textContent);
        }, { timeout: 5000 });
        const text = await page.locator('#fm-ex-results').textContent();
        expect(text).toMatch(/risultati/i);
    });

    test("page-to-page: sidebar link to /exercises clickable", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/teacher/dashboard");
        const link = page.locator('a[href="/exercises"]');
        await expect(link).toBeVisible();
        await link.click();
        await expect(page).toHaveURL(/\/exercises/);
    });

    test("DB↔page: /teacher/verifiche.json restituisce schema corretto", async ({ page }) => {
        await loginAdmin(page);
        const res = await page.request.get("/teacher/verifiche.json");
        const data = await res.json();
        expect(data).toHaveProperty('ok', true);
        expect(data).toHaveProperty('rows');
        if (data.rows.length > 0) {
            const r = data.rows[0];
            expect(r).toHaveProperty('id');
            expect(r).toHaveProperty('filename');
            expect(r).toHaveProperty('variant');
        }
    });

    test("logout funziona e termina sessione", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/logout");
        const res = await page.request.get("/auth/user-info");
        const data = await res.json();
        expect(data.authenticated).toBe(false);
    });
});
