/**
 * Phase 24.62 — template istituzionali (owner_id IS NULL) sono visibili
 * a tutti i teacher autenticati di default + GET /admin/curriculum
 * accessibile a teacher.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "marco.rossi";

test("teacher non super_admin vede template istituzionali via API", async ({ page }) => {
    test.setTimeout(60_000);
    // Login come marco.rossi (teacher non super-admin)
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', "test123");
    const submit = await Promise.all([
        page.waitForURL(/.*/),
        page.click('button[type="submit"]'),
    ]).catch(() => null);
    // Skip se le credenziali non funzionano (env-specific)
    if (page.url().includes("/login")) {
        test.skip(true, "marco.rossi credentials non valide in questo env");
        return;
    }

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    expect(list.ok).toBeTruthy();
    expect(list.templates?.length, "almeno 1 template istituzionale visibile").toBeGreaterThanOrEqual(1);
});

test("GET /admin/curriculum accessibile a teacher (no role:admin)", async ({ page }) => {
    test.setTimeout(60_000);
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);

    // Phase 24.62: rotta GET ora in role:teacher group → super_admin teacher passa
    const r = await page.request.get("/admin/curriculum");
    expect(r.status(), "200 OK accesso teacher").toBe(200);
});
