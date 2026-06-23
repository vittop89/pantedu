/**
 * Diagnostic — /admin/institutes: sezione "Database scuole MIUR" (upload).
 *   - la pagina renderizza la sezione + form multipart con input file
 *   - POST senza file → no_file
 *   - POST con JSON non-@graph → not_miur_graph_json (non scrive su disco)
 * Cattura console.log (memory feedback_e2e_console_logs).
 */
const { test, expect } = require("@playwright/test");

const USER = process.env.E2E_TEACHER_USER || "superadmin";
const PASS = process.env.E2E_TEACHER_PASS || process.env.FM_P || "";

test("admin institutes — sezione MIUR upload + validazione", async ({ page }) => {
    test.setTimeout(60_000);
    const logs = [];
    page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
    page.on("pageerror", (e) => logs.push(`[pageerror] ${e.message}`));

    await page.goto("/login");
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);

    const resp = await page.goto("/admin/institutes");
    console.log(`[test] GET /admin/institutes → ${resp.status()}`);
    await page.waitForLoadState("domcontentloaded");

    const hasSection = await page.locator("#miur-schools").count();
    const formEnctype = await page.locator("#fm-miur-form").getAttribute("enctype");
    const fileInputs = await page.locator('#fm-miur-form input[type="file"]').count();
    console.log(`[test] sezione=${hasSection} enctype=${formEnctype} fileInputs=${fileInputs}`);
    await page.screenshot({ path: "tests/e2e-results/admin_institutes_miur.png", fullPage: true });
    expect(hasSection, "sezione presente").toBeGreaterThan(0);
    expect(formEnctype, "form multipart").toBe("multipart/form-data");
    expect(fileInputs, "due input file").toBe(2);

    const csrf = await page.locator('#fm-miur-form input[name="_csrf"]').inputValue();

    // 1) nessun file → no_file
    const r1 = await page.evaluate(async (csrf) => {
        const fd = new FormData();
        fd.append("_csrf", csrf);
        const r = await fetch("/admin/institutes/miur/update", {
            method: "POST", body: fd, credentials: "same-origin",
            headers: { "X-CSRF-Token": csrf, "X-Requested-With": "XMLHttpRequest" },
        });
        return { status: r.status, json: await r.json().catch(() => null) };
    }, csrf);
    console.log(`[test] no-file → ${r1.status} ${JSON.stringify(r1.json)}`);

    // 2) JSON non-@graph (>1KB) → not_miur_graph_json (non scrive il file reale)
    const r2 = await page.evaluate(async (csrf) => {
        const blob = new Blob([JSON.stringify({ foo: "x".repeat(2000) })], { type: "application/json" });
        const fd = new FormData();
        fd.append("_csrf", csrf);
        fd.append("statali_file", blob, "fake.json");
        const r = await fetch("/admin/institutes/miur/update", {
            method: "POST", body: fd, credentials: "same-origin",
            headers: { "X-CSRF-Token": csrf, "X-Requested-With": "XMLHttpRequest" },
        });
        return { status: r.status, json: await r.json().catch(() => null) };
    }, csrf);
    console.log(`[test] non-graph → ${r2.status} ${JSON.stringify(r2.json)}`);

    console.log(`\n[test] ===== CONSOLE (${logs.length}) =====\n${logs.slice(-15).join("\n")}`);

    expect(r1.json?.error, "no_file").toBe("no_file");
    expect(r2.json?.error, "not_miur_graph_json").toBe("not_miur_graph_json");
});
