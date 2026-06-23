/**
 * G22.S15.bis Fase 5 — Local bundle ora include modelli/ (texCommon + risdoc).
 * Verifica che il manifest restituisca path corretti.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test("Local bundle: modelli/texCommon e modelli/risdoc presenti", async ({ page }) => {
    test.setTimeout(45000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // Drena il bundle paginato e raccogli tutti i path
    const allPaths = [];
    let offset = 0;
    while (true) {
        const r = await page.request.get(`/api/teacher/sync-local-bundle?offset=${offset}&limit=50`);
        expect(r.status()).toBe(200);
        const j = await r.json();
        expect(j.ok).toBe(true);
        (j.files || []).forEach(f => allPaths.push(f.path));
        if (!j.hasMore) break;
        offset += 50;
        if (offset > 500) break; // safety
    }
    console.log(`Bundle: ${allPaths.length} files`);
    const modelliTexCommon = allPaths.filter(p => /\/modelli\/texCommon\//.test(p));
    const modelliRisdoc    = allPaths.filter(p => /\/modelli\/risdoc\//.test(p));
    console.log(`modelli/texCommon: ${modelliTexCommon.length} files →`,
        modelliTexCommon.slice(0, 5));
    console.log(`modelli/risdoc: ${modelliRisdoc.length} files →`,
        modelliRisdoc.slice(0, 5));

    expect(modelliTexCommon.length, "modelli/texCommon dovrebbe contenere file").toBeGreaterThan(0);
    expect(modelliRisdoc.length, "modelli/risdoc dovrebbe contenere file").toBeGreaterThan(0);

    // Path layout: {institute}/modelli/{texCommon|risdoc}/...
    modelliTexCommon.forEach(p => {
        expect(p).toMatch(/^[^/]+\/modelli\/texCommon\//);
    });
    modelliRisdoc.forEach(p => {
        expect(p).toMatch(/^[^/]+\/modelli\/risdoc\//);
    });
});
