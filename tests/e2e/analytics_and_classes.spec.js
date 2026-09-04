/**
 * E2E (Phase 13.5) — Admin Analytics + studente con classe.
 *
 *   - GET /admin/analytics                        → page render
 *   - GET /api/admin/analytics                    → snapshot JSON
 *   - GET /api/admin/analytics/teacher/{id}       → drill-down
 *   - GET /api/admin/analytics/cross-search       → cross-teacher
 *   - Studente registra con indirizzo+classe → pending entry contiene course
 *   - Sidebar: fm-sb-dark (mini icon) presente
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "analytics");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 10_000 });

test.describe("Admin Analytics + student class (Phase 13.5)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
    });

    test("GET /api/admin/analytics ritorna snapshot valido", async ({ page }) => {
        await loginAdmin(page);
        const j = await page.evaluate(async () =>
            (await (await fetch("/api/admin/analytics")).json()),
        );
        expect(j.ok).toBe(true);
        for (const k of [
            "users_by_role", "content_by_type", "content_by_vis",
            "top_authors", "top_institutes", "access_30d_role",
            "access_24h_total", "access_7d_total",
        ]) {
            expect(j).toHaveProperty(k);
        }
        expect(typeof j.access_24h_total).toBe("number");
    });

    test("/admin/analytics renderizza page con 3 tabs", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/analytics");
        await expect(page.locator("h1").filter({ hasText: "Admin Analytics" })).toBeVisible();
        for (const tab of ["overview", "teachers", "search"]) {
            await expect(page.locator(`.fm-tab[data-tab="${tab}"]`)).toBeVisible();
        }
        // Overview lazy load
        await expect.poll(async () =>
            await page.locator("#fm-an-overview").innerText(),
            { timeout: 8_000 }).not.toBe("Caricamento…");
        await shot(page, "01_analytics_overview");
    });

    test("Cross-teacher search ritorna content + risk_flags", async ({ page }) => {
        await loginAdmin(page);
        const j = await page.evaluate(async () =>
            (await (await fetch("/api/admin/analytics/cross-search?limit=5")).json()),
        );
        expect(j.ok).toBe(true);
        expect(Array.isArray(j.rows)).toBe(true);
        for (const r of j.rows) {
            expect(r).toHaveProperty("teacher_id");
            expect(r).toHaveProperty("teacher_username");
            expect(r).toHaveProperty("body_snippet");
            expect(Array.isArray(r.risk_flags)).toBe(true);
        }
    });

    test("Studente registra con indirizzo+classe → pending.course = 'ind.cls'", async ({ page }) => {
        // Setup: admin crea istituto
        await loginAdmin(page);
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const inst = await page.evaluate(async (csrf) => {
            const code = "STU-CLS-" + Date.now();
            const body = new URLSearchParams({ code, name: "Inst " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, csrf);
        expect(inst.ok).toBe(true);
        await page.context().clearCookies();

        // Student register con tutti i campi
        await page.goto("/register");
        await page.fill('input[name="first_name"]', "Mario");
        await page.fill('input[name="last_name"]',  "Studente");
        const email = "stud_cls_" + Date.now() + "@e2e.test";
        await page.fill('input[name="email"]', email);
        await page.fill('input[name="password"]', "PasswordE2E_2024!");
        await page.selectOption('#institute_id', String(inst.id));
        // I select indirizzo/classe sono popolati AJAX da /curriculum
        await expect.poll(async () =>
            await page.locator('#reg_indirizzo option').count(),
            { timeout: 5_000 }).toBeGreaterThan(1);
        await expect.poll(async () =>
            await page.locator('#reg_classe option').count(),
            { timeout: 5_000 }).toBeGreaterThan(1);
        await page.selectOption('#reg_indirizzo', { index: 1 });
        await page.selectOption('#reg_classe',    { index: 1 });
        await Promise.all([
            page.waitForURL(/\/register\?(ok|error)/),
            page.click('button[type="submit"]'),
        ]);
        expect(page.url()).toContain("ok=1");

        // Admin verifica entry contiene course
        await loginAdmin(page);
        const pending = await page.evaluate(async () =>
            (await (await fetch("/admin/registrations")).json()),
        );
        const entry = (pending.pending || []).find(e => e.email === email);
        expect(entry, "entry pending presente").toBeTruthy();
        expect(entry.indirizzo).toBeTruthy();
        expect(entry.classe).toBeTruthy();
        expect(entry.course).toBe(`${entry.indirizzo}.${entry.classe}`);
    });

    test("Studente senza indirizzo+classe → errore section_required", async ({ page }) => {
        await loginAdmin(page);
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const inst = await page.evaluate(async (csrf) => {
            const code = "STU-NS-" + Date.now();
            const body = new URLSearchParams({ code, name: "Inst " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, csrf);
        await page.context().clearCookies();

        await page.goto("/register");
        await page.fill('input[name="first_name"]', "Sec");
        await page.fill('input[name="last_name"]',  "Required");
        await page.fill('input[name="email"]',      "no_sec_" + Date.now() + "@e2e.test");
        await page.fill('input[name="password"]',   "PasswordE2E_2024!");
        await page.selectOption('#institute_id', String(inst.id));
        // Disabilito completamente la validation HTML5 client + force-disable
        // il required su tutti i select per evitare blocco submit.
        await page.evaluate(() => {
            const f = document.getElementById("fm-register-form");
            f.setAttribute("novalidate", "novalidate");
            ["reg_indirizzo", "reg_classe"].forEach(id => {
                const el = document.getElementById(id);
                if (el) el.required = false;
            });
        });
        await Promise.all([
            page.waitForURL(/\/register\?(error|ok)/, { timeout: 10_000 }),
            page.click('button[type="submit"]'),
        ]);
        expect(page.url()).toContain("error=section_required");
    });

    test("Sidebar: fm-sb-dark mini icon presente accanto al banner", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/?home=1");
        await expect(page.locator(".sel-session-banner .fm-sb-dark.fm-darkmode-mini")).toBeVisible();
        // Click toggle body.fm-dark
        await page.locator(".fm-sb-dark").click();
        await expect(page.locator("body")).toHaveClass(/fm-dark/);
        await page.locator(".fm-sb-dark").click();
        await expect(page.locator("body")).not.toHaveClass(/fm-dark/);
        await shot(page, "02_dark_toggle");
    });
});
