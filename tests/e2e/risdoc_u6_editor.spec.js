/**
 * U6 e2e — override editor split-view.
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

test.describe("U6: override editor", () => {
    test("visita /risdoc/edit/{id} → topbar + tabs + textarea + guida", async ({ page }) => {
        await login(page);
        const api = await page.request.get("/api/risdoc/templates");
        const tid = (await api.json()).templates?.[0]?.id;
        expect(tid).toBeTruthy();

        const resp = await page.goto(`/risdoc/edit/${tid}`);
        expect(resp.status()).toBe(200);

        await expect(page.locator(".fm-re-editor")).toBeVisible();
        await expect(page.locator(".fm-re-tab[data-kind='html']")).toBeVisible();
        await expect(page.locator(".fm-re-textarea")).toBeVisible();

        // Guida panel mostra almeno una mapping entry
        await expect(page.locator(".fm-re-guide-entry").first()).toBeVisible();

        // Attendi carica HTML nel textarea
        await page.waitForFunction(() => {
            const ta = document.querySelector(".fm-re-textarea");
            return ta && ta.value.length > 50;
        }, { timeout: 5000 });
    });

    test("edit + save persiste come override; revert torna al sorgente", async ({ page }) => {
        page.on("console", msg => console.log(`[browser] ${msg.type()}: ${msg.text()}`));
        page.on("pageerror", err => console.log(`[page-error] ${err.message}`));
        await login(page);
        const api = await page.request.get("/api/risdoc/templates");
        const tid = (await api.json()).templates?.[0]?.id;

        await page.goto(`/risdoc/edit/${tid}`);
        await page.waitForFunction(() => (document.querySelector(".fm-re-textarea")?.value?.length ?? 0) > 50);
        // Piccolo delay per assicurarsi che loadKind abbia completato (status → idle)
        await page.waitForTimeout(500);

        const marker = "\n<!-- RISDOC-PTR-TEST-" + Date.now() + " -->";

        await page.evaluate((m) => {
            const ta = document.querySelector(".fm-re-textarea");
            ta.focus();
            ta.value = ta.value + m;
            ta.dispatchEvent(new Event("input", { bubbles: true }));
        }, marker);

        // Attendi debounced save (800ms debounce + network)
        await page.waitForTimeout(1500);
        const status = await page.evaluate(() => document.querySelector(".fm-re-status")?.dataset.status);
        console.log("status after save wait:", status);

        // Reload pagina e verifica override attivo
        await page.reload();
        await page.waitForFunction(() => (document.querySelector(".fm-re-textarea")?.value?.length ?? 0) > 50);
        const body = await page.evaluate(() => document.querySelector(".fm-re-textarea").value);
        expect(body).toContain(marker.trim());

        // Revert
        page.on("dialog", d => d.accept());
        await page.click('[data-action="revert"]');
        await page.waitForFunction(() => {
            const s = document.querySelector(".fm-re-status")?.dataset.status;
            return s === "idle" || s === "saved";
        }, { timeout: 3000 });

        const bodyAfterRevert = await page.evaluate(() => document.querySelector(".fm-re-textarea").value);
        expect(bodyAfterRevert).not.toContain(marker.trim());
    });
});
