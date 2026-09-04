/**
 * U7 e2e — JSON editor (tab JSON nell'override editor).
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

test.describe("U7: JSON editor", () => {
    test("tab JSON mostra picker + carica primo file", async ({ page }) => {
        await login(page);
        const api = await page.request.get("/api/risdoc/templates?origin=risdoc");
        const tid = (await api.json()).templates?.[0]?.id;

        await page.goto(`/risdoc/edit/${tid}`);
        await page.waitForTimeout(800);

        await page.click('[data-kind="json"]');
        await page.waitForTimeout(1000);

        await expect(page.locator(".fm-re-jsonpicker")).toBeVisible();
        const options = await page.locator(".fm-re-json-select option").count();
        expect(options).toBeGreaterThan(3);  // almeno qualche JSON

        const body = await page.evaluate(() => document.querySelector(".fm-re-textarea").value);
        expect(body.length).toBeGreaterThan(10);

        // Inline validation OK
        const validityText = await page.locator(".fm-re-json-validity").textContent();
        expect(validityText).toMatch(/valido/i);
    });

    test("edit JSON + save persiste, revert torna sorgente", async ({ page }) => {
        await login(page);
        const api = await page.request.get("/api/risdoc/templates?origin=risdoc");
        const tid = (await api.json()).templates?.[0]?.id;

        await page.goto(`/risdoc/edit/${tid}`);
        await page.waitForTimeout(600);
        await page.click('[data-kind="json"]');
        // Attendi che loadKind completi: textarea popolato + status non-loading
        await page.waitForFunction(() => {
            const ta = document.querySelector(".fm-re-textarea");
            const s  = document.querySelector(".fm-re-status")?.dataset.status;
            return ta && ta.value.length > 20 && s !== "loading";
        }, { timeout: 5000 });

        const currentPath = await page.evaluate(() => document.querySelector(".fm-re-json-select").value);
        expect(currentPath).toBeTruthy();

        const marker = "fm_ptr_test_" + Date.now();
        await page.evaluate((m) => {
            const ta = document.querySelector(".fm-re-textarea");
            try {
                const parsed = JSON.parse(ta.value);
                // Handle array / object JSON root
                if (Array.isArray(parsed)) parsed.push({ __fm_ptr_marker: m });
                else parsed.__fm_ptr_marker = m;
                ta.value = JSON.stringify(parsed, null, 2);
                ta.dispatchEvent(new Event("input", { bubbles: true }));
            } catch (e) { throw new Error("JSON parse: " + e.message); }
        }, marker);

        // Attendi save completato (status → saved o idle)
        await page.waitForFunction(() => {
            const s = document.querySelector(".fm-re-status")?.dataset.status;
            return s === "saved" || s === "idle" || s === "override-active";
        }, { timeout: 5000 });
        await page.waitForTimeout(300);
        const status = await page.evaluate(() => document.querySelector(".fm-re-status")?.dataset.status);
        expect(["saved", "idle", "override-active"]).toContain(status);

        // Reload → verifica marker presente (override loaded)
        await page.reload();
        await page.waitForTimeout(600);
        await page.click('[data-kind="json"]');
        await page.waitForFunction(() => document.querySelector(".fm-re-json-select")?.options.length > 0, { timeout: 5000 });
        await page.selectOption(".fm-re-json-select", currentPath);
        await page.waitForFunction((p) => {
            const ta = document.querySelector(".fm-re-textarea");
            return ta && ta.value.length > 20 && document.querySelector(".fm-re-status")?.dataset.status !== "loading";
        }, { timeout: 5000 });
        const bodyAfterReload = await page.evaluate(() => document.querySelector(".fm-re-textarea").value);
        expect(bodyAfterReload).toContain(marker);

        // Revert
        page.on("dialog", d => d.accept());
        await page.click('[data-action="revert"]');
        await page.waitForTimeout(1200);
        const bodyAfterRevert = await page.evaluate(() => document.querySelector(".fm-re-textarea").value);
        expect(bodyAfterRevert).not.toContain(marker);
    });
});
