/**
 * E2E (Phase 13) — admin tools UI unificata + endpoint moderni
 * sostituiscono log/admin/user_manager.php + log/security/* legacy.
 *
 *  - GET  /admin/tools                                       → page HTML tabs
 *  - POST /admin/generate-hash (Content-Type fixed)           → bcrypt
 *  - GET  /api/admin/users                                    → list+filter
 *  - POST /api/admin/users/{id}/active                        → toggle
 *  - GET  /api/admin/security/blocked-credentials             → list
 *  - GET  /api/admin/security/blocked-ips                     → list
 *  - POST /api/admin/security/credentials/unblock             → remove
 *  - POST /api/admin/security/ips/unblock                     → remove
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "admin_tools");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 10_000 });

test.describe("Admin Tools UI (Phase 13)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
    });

    test("/admin/tools render con 6 tabs", async ({ page }) => {
        await page.goto("/admin/tools");
        await expect(page.locator("h1").filter({ hasText: "Admin Tools" })).toBeVisible();
        for (const tab of ["notifications", "users", "registrations", "security", "logs", "hash"]) {
            await expect(page.locator(`.fm-tab[data-tab="${tab}"]`)).toBeVisible();
        }
        // Tab notifications attivo di default
        await expect(page.locator(`.fm-tab[data-tab="notifications"].fm-tab--active`)).toBeVisible();
        await shot(page, "01_tabs");
    });

    test("Hash tool genera bcrypt + (Content-Type fix)", async ({ page }) => {
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const r = await page.evaluate(async (csrf) => {
            const body = new URLSearchParams({ password: "TestPwd!E2E_phase13", cost: "10", _csrf: csrf });
            const res = await fetch("/admin/generate-hash", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                body: body.toString(),
            });
            return { status: res.status, body: await res.json() };
        }, csrf);
        expect(r.status).toBe(200);
        expect(r.body.ok).toBe(true);
        expect(r.body.hash).toMatch(/^\$2y\$10\$/);
    });

    test("Users API: list + role + active toggle", async ({ page }) => {
        // List
        const list = await page.evaluate(async () =>
            (await (await fetch("/api/admin/users?limit=10")).json()),
        );
        expect(list.ok).toBe(true);
        expect(Array.isArray(list.rows)).toBe(true);
        expect(list.rows.length).toBeGreaterThan(0);

        // Trova un utente non-admin per togglare (skip self)
        const target = list.rows.find(u => u.username !== "admin" && u.role !== "administrator");
        if (!target) {
            test.skip("nessun utente non-admin in DB per testare toggle");
            return;
        }
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const newActive = target.active ? 0 : 1;
        const toggle = await page.evaluate(async ({ id, active, csrf }) => {
            const body = new URLSearchParams({ active: String(active), _csrf: csrf });
            const r = await fetch(`/api/admin/users/${id}/active`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: r.status, body: await r.json() };
        }, { id: target.id, active: newActive, csrf });
        expect(toggle.status).toBe(200);
        expect(toggle.body.ok).toBe(true);
        expect(toggle.body.active).toBe(!!newActive);

        // Restore
        await page.evaluate(async ({ id, active, csrf }) => {
            const body = new URLSearchParams({ active: String(active), _csrf: csrf });
            await fetch(`/api/admin/users/${id}/active`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
        }, { id: target.id, active: target.active ? 1 : 0, csrf });
    });

    test("Users API: cannot-disable-self protection", async ({ page }) => {
        const me = await page.evaluate(async () =>
            (await (await fetch("/api/admin/users?q=admin")).json()).rows
                .find(u => u.username === "admin"),
        );
        expect(me).toBeTruthy();
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const r = await page.evaluate(async ({ id, csrf }) => {
            const body = new URLSearchParams({ active: "0", _csrf: csrf });
            const res = await fetch(`/api/admin/users/${id}/active`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: res.status, body: await res.json() };
        }, { id: me.id, csrf });
        expect(r.status).toBe(403);
        expect(r.body.error).toBe("cannot_disable_self");
    });

    test("Security API: blocked-credentials + blocked-ips JSON list", async ({ page }) => {
        const c = await page.evaluate(async () =>
            (await (await fetch("/api/admin/security/blocked-credentials")).json()),
        );
        expect(c.ok).toBe(true);
        expect(Array.isArray(c.rows)).toBe(true);
        const i = await page.evaluate(async () =>
            (await (await fetch("/api/admin/security/blocked-ips")).json()),
        );
        expect(i.ok).toBe(true);
        expect(Array.isArray(i.rows)).toBe(true);
        // Phase 13: blocked-ips include `associated_usernames` per ogni IP
        for (const row of i.rows) {
            expect(row).toHaveProperty("associated_usernames");
            expect(Array.isArray(row.associated_usernames)).toBe(true);
        }
    });

    test("Security API: anomalies (detect excessive_access + credential_sharing)", async ({ page }) => {
        const r = await page.evaluate(async () =>
            (await (await fetch("/api/admin/security/anomalies")).json()),
        );
        expect(r.ok).toBe(true);
        expect(Array.isArray(r.rows)).toBe(true);
        expect(r.summary).toBeTruthy();
        for (const k of ["total", "active", "excessive_access", "credential_sharing"]) {
            expect(r.summary).toHaveProperty(k);
            expect(typeof r.summary[k]).toBe("number");
        }
        // Ogni anomaly ha campi obbligatori
        for (const a of r.rows) {
            expect(["excessive_access", "credential_sharing"]).toContain(a.type);
            expect(["low", "medium", "high"]).toContain(a.risk_level);
            expect(typeof a.count).toBe("number");
            expect(typeof a.blocked).toBe("boolean");
            expect(a.fingerprint).toMatch(/^(ea|cs):/);
        }
    });

    test("Security API: block + unblock IP roundtrip", async ({ page }) => {
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const testIp = "203.0.113." + Math.floor(Math.random() * 254);
        const block = await page.evaluate(async ({ ip, csrf }) => {
            const body = new URLSearchParams({ ip, section: "test_e2e", reason: "e2e_test", _csrf: csrf });
            const r = await fetch("/api/admin/security/ips/block", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, { ip: testIp, csrf });
        expect(block.ok).toBe(true);

        // Verifica presente in lista
        const list = await page.evaluate(async () =>
            (await (await fetch("/api/admin/security/blocked-ips")).json()).rows,
        );
        expect(list.find(r => r.ip === testIp)).toBeTruthy();

        // Unblock
        const unblock = await page.evaluate(async ({ ip, csrf }) => {
            const body = new URLSearchParams({ ip, section: "test_e2e", _csrf: csrf });
            const r = await fetch("/api/admin/security/ips/unblock", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, { ip: testIp, csrf });
        expect(unblock.ok).toBe(true);
        expect(unblock.removed).toBe(1);
    });

    test("Security config: GET ritorna struttura + POST aggiorna soglie", async ({ page }) => {
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const get0 = await page.evaluate(async () =>
            (await (await fetch("/api/admin/security/config")).json()),
        );
        expect(get0.ok).toBe(true);
        expect(get0.config.security_alerts).toBeTruthy();

        const r = await page.evaluate(async (csrf) => {
            const body = new URLSearchParams({
                ea_enabled: "1",
                ea_threshold_per_section: "5",
                ea_low_min: "5", ea_low_max: "10",
                ea_medium_min: "11", ea_medium_max: "20",
                ea_high_min: "21",
                cs_enabled: "1",
                cs_min_ips_required: "3",
                _csrf: csrf,
            });
            const res = await fetch("/api/admin/security/config", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: res.status, body: await res.json() };
        }, csrf);
        expect(r.status).toBe(200);
        expect(r.body.ok).toBe(true);
        expect(r.body.config.security_alerts.excessive_access.threshold_per_section).toBe(5);
        expect(r.body.config.security_alerts.credential_sharing.min_ips_required).toBe(3);
    });

    test("Student-login fallback: account admin reale → grant 'user_account'", async ({ page }) => {
        await page.context().clearCookies();
        await page.goto("/?home=1");
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const r = await page.evaluate(async (csrf) => {
            const body = new URLSearchParams({
                username: "admin", password: "e2e_test_2024", _csrf: csrf,
            });
            const res = await fetch("/api/access/student-login", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: res.status, body: await res.json() };
        }, csrf);
        expect(r.status).toBe(200);
        expect(r.body.ok).toBe(true);
        expect(r.body.grant.source).toBe("user_account");
        expect(r.body.grant.label).toContain("Self-access");
    });

    test("Tools page: tab switching e cross-tab event refresh notifications", async ({ page }) => {
        await page.goto("/admin/tools");
        await page.waitForFunction(() =>
            document.querySelectorAll("#fm-notif-grid .fm-tile").length > 1,
        );
        await shot(page, "02_notifications_loaded");
        // Switch a tab Users
        await page.locator('.fm-tab[data-tab="users"]').click();
        await expect(page.locator('.fm-tab-panel[data-panel="users"]')).toBeVisible();
        // Switch a Security
        await page.locator('.fm-tab[data-tab="security"]').click();
        await expect(page.locator('.fm-tab-panel[data-panel="security"]')).toBeVisible();
        // Lazy load: aspetta che #fm-sec-creds sia popolato (anche se "Nessun record")
        await expect.poll(async () =>
            await page.locator("#fm-sec-creds").innerText(),
            { timeout: 8_000 }).not.toBe("Caricamento…");
        await shot(page, "03_security_tab");
    });
});
