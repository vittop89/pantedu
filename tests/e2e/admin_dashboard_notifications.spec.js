/**
 * E2E (Phase 13) — Admin dashboard + notifications badge.
 *
 *  - GET /api/admin/notifications → JSON aggregato
 *  - /admin/dashboard mostra alert se notifications.total > 0
 *  - .sel-session-banner riceve .fm-admin-badge dinamicamente
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "admin_dashboard");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 10_000 });

test.describe("Admin dashboard + notifications (Phase 13)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
    });

    test("GET /api/admin/notifications ritorna counters JSON", async ({ page }) => {
        const j = await page.evaluate(async () =>
            (await (await fetch("/api/admin/notifications", { credentials: "same-origin" })).json()),
        );
        expect(j.ok).toBe(true);
        for (const k of [
            "total", "pending_registrations", "blocked_credentials",
            "blocked_ips", "failed_logins_24h", "new_teacher_content_24h",
        ]) {
            expect(j).toHaveProperty(k);
            expect(typeof j[k]).toBe("number");
        }
    });

    test("/admin/dashboard renderizza overview moderno + link tools unificato", async ({ page }) => {
        await page.goto("/admin/dashboard");
        await expect(page.locator("h1").filter({ hasText: "Admin Dashboard" })).toBeVisible();
        // Tile counters present
        await expect(page.locator(".fm-tile h3").filter({ hasText: "Accessi totali" })).toBeVisible();
        // Link Tools unificato (sostituisce le pagine legacy admin)
        await expect(page.locator('a[href="/admin/tools"]')).toBeVisible();
        // Legacy admin links rimossi
        await expect(page.locator('a[href="/log/admin/user_manager.php"]')).toHaveCount(0);
        await expect(page.locator('a[href="/log/security/monitoring/dashboard.php"]')).toHaveCount(0);
        await shot(page, "01_dashboard_modern");
    });

    test("Sidebar banner riceve .fm-admin-badge se notifications.total > 0", async ({ page }) => {
        await page.goto("/?home=1");
        await page.waitForFunction(() => window.FM?.initAdminBadge);
        // Aspetta che il polling completi
        const total = await page.evaluate(async () =>
            (await (await fetch("/api/admin/notifications")).json()).total,
        );
        // Se total > 0, il badge deve apparire entro 3s; altrimenti il
        // banner resta clean (negative test).
        if (total > 0) {
            await expect(page.locator(".sel-session-banner .fm-admin-badge")).toBeVisible({ timeout: 4_000 });
            const badgeText = await page.locator(".fm-admin-badge").textContent();
            expect(badgeText).toContain(String(total));
            await shot(page, "02_banner_badge");
        } else {
            await page.waitForTimeout(800);
            await expect(page.locator(".sel-session-banner .fm-admin-badge")).toHaveCount(0);
        }
    });

    test("Pending registration aumenta notifications.pending_registrations", async ({ page }) => {
        // Snapshot iniziale
        const before = await page.evaluate(async () =>
            (await (await fetch("/api/admin/notifications")).json()).pending_registrations,
        );
        await page.context().clearCookies();
        // Crea istituto + registra studente (richiede istituto)
        await loginAdmin(page);
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const inst = await page.evaluate(async ({ csrf }) => {
            const code = "BADGE_INST_" + Date.now();
            const body = new URLSearchParams({ code, name: "Inst " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, { csrf });
        expect(inst.ok).toBe(true);

        await page.context().clearCookies();
        await page.goto("/register");
        await page.fill('input[name="first_name"]', "Mario");
        await page.fill('input[name="last_name"]',  "Badge");
        const email = "stud_badge_" + Date.now() + "@e2e.test";
        await page.fill('input[name="email"]',      email);
        await page.fill('input[name="password"]',   "PasswordE2E_2024!");
        await page.selectOption('#institute_id', String(inst.id));
        // Phase 13.5: studente deve selezionare anche indirizzo+classe
        await expect.poll(async () =>
            await page.locator('#reg_indirizzo option').count(),
            { timeout: 5_000 }).toBeGreaterThan(1);
        await page.selectOption('#reg_indirizzo', { index: 1 });
        await page.selectOption('#reg_classe',    { index: 1 });
        await Promise.all([
            page.waitForURL(/\/register\?(ok|error)/),
            page.click('button[type="submit"]'),
        ]);
        // Re-login admin e verifica counter aumentato
        await loginAdmin(page);
        const after = await page.evaluate(async () =>
            (await (await fetch("/api/admin/notifications")).json()).pending_registrations,
        );
        expect(after).toBeGreaterThan(before);
    });
});
