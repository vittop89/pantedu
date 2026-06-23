/**
 * G20.1 — E2E: docente edita propri template (cascade teacher → istituto → default)
 */
const { test, expect } = require("@playwright/test");

test("Area docente templates: nav + cascade + write/delete", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill("superadmin");
    await page.locator("input[name=password]").fill((process.env.E2E_TEACHER_PASS || ""));
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");

    // GET list
    const r = await page.request.get("/api/teacher/verifica/files");
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    expect(j.ok).toBe(true);
    expect(j.teacher_id).toBeGreaterThan(0);
    expect(j.files.length).toBeGreaterThan(0);
    console.log(`Teacher ${j.teacher_id} institute ${j.institute_code}: ${j.files.length} files`);

    // Visita pagina
    await page.goto("/area-docente/templates");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(800);

    // Verifica nav (3 tab: Dashboard | Profilo | Modelli)
    const navTabs = await page.locator(".fm-area-docente-nav__tab").count();
    expect(navTabs).toBe(3);

    const activeTab = await page.locator(".fm-area-docente-nav__tab--active").textContent();
    expect(activeTab).toContain("modelli");

    // Verifica tree caricato
    const treeText = await page.locator("#fm-tvf-tree").textContent();
    expect(treeText).toContain("Elementi comuni");
    expect(treeText).toContain("verifica.sty");

    // Test write/delete via API
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);
    const testPath = "texCommon/intestazione.tex";
    const testContent = "% G20.1 test teacher override\n\\noindent Test Vittorio";
    const wRes = await page.request.post("/api/teacher/verifica/files/write", {
        data: { path: testPath, content: testContent, _csrf: csrf },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(wRes.ok()).toBeTruthy();
    console.log("Write OK");

    // Read deve riportare il contenuto teacher
    const rRes = await page.request.get(`/api/teacher/verifica/files/read?path=${encodeURIComponent(testPath)}`);
    const rj = await rRes.json();
    expect(rj.is_mine).toBe(true);
    expect(rj.content).toBe(testContent);

    // Delete
    const dRes = await page.request.post("/api/teacher/verifica/files/delete", {
        data: { path: testPath, _csrf: csrf },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(dRes.ok()).toBeTruthy();

    // Read dopo delete: niente teacher override, content da istituto/default
    const r2 = await page.request.get(`/api/teacher/verifica/files/read?path=${encodeURIComponent(testPath)}`);
    const r2j = await r2.json();
    expect(r2j.is_mine).toBe(false);
    expect(r2j.content).not.toBe(testContent);
});
