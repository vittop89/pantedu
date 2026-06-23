/**
 * G20.0 Phase 9 — Admin file-tree editor smoke test.
 * Vittorio è teacher (no super_admin) → endpoint admin restituiscono 403.
 * Test login come admin di sistema (richiede credenziali admin).
 *
 * Per ora testiamo solo che gli endpoint sono raggiungibili (anche con 403).
 */
const { test, expect } = require("@playwright/test");

test("Admin files endpoints: routes registered + auth check", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill("superadmin");
    await page.locator("input[name=password]").fill((process.env.E2E_TEACHER_PASS || ""));
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Endpoints non super_admin → 403 con error: forbidden
    const r1 = await page.request.get("/api/admin/verifica/scopes");
    console.log("scopes:", r1.status(), (await r1.text()).slice(0, 100));
    expect([200, 403]).toContain(r1.status());

    const r2 = await page.request.get("/api/admin/verifica/files?scope=_default");
    console.log("files _default:", r2.status());
    expect([200, 403]).toContain(r2.status());

    if (r2.ok()) {
        const j = await r2.json();
        console.log("Files in _default:", j.files?.length);
        expect(j.files?.length).toBeGreaterThan(0);
    }

    // Test admin templates page render
    await page.goto("/admin/templates#verifiche");
    await page.waitForLoadState("networkidle");
    await page.evaluate(() => {
        const o = document.getElementById("fm-modal-overlay");
        if (o) o.style.display = "none";
    });
    const treeExists = await page.evaluate(() => !!document.getElementById("fm-vfiles-tree"));
    const scopeExists = await page.evaluate(() => !!document.getElementById("fm-vfiles-scope"));
    console.log("UI: tree=", treeExists, "scope=", scopeExists);
    expect(treeExists).toBe(true);
    expect(scopeExists).toBe(true);
});
