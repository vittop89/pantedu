/**
 * Helper condivisi per i test e2e.
 *
 * Pantedu non ha API admin per creare utenti via HTTP. Per ora:
 * - Login admin con credenziali di /log/data/admin_users.json
 *   (assumiamo password fornita via env FM_E2E_ADMIN_PASSWORD)
 * - Registrazione teacher viene fatta via form /register; test si
 *   aspetta pending stato + approva via dashboard admin
 */

const { expect } = require("@playwright/test");

const ADMIN_USERNAME = process.env.FM_E2E_ADMIN_USERNAME || "admin";
const ADMIN_PASSWORD = process.env.FM_E2E_ADMIN_PASSWORD || "admin";

async function loginAs(page, username, password) {
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

async function loginAdmin(page) {
    await loginAs(page, ADMIN_USERNAME, ADMIN_PASSWORD);
}

async function registerTeacher(page, data) {
    await page.goto("/register");
    await page.selectOption('select[name="role"]', "teacher");
    await page.fill('input[name="first_name"]', data.firstName);
    await page.fill('input[name="last_name"]',  data.lastName);
    await page.fill('input[name="email"]',      data.email);
    await page.fill('input[name="password"]',   data.password);
    await Promise.all([
        page.waitForURL(/\/register\?(ok|error)/),
        page.click('button[type="submit"]'),
    ]);
}

async function logout(page) {
    await page.goto("/logout");
}

async function approvePendingByEmail(page, email) {
    await loginAdmin(page);
    const res = await page.request.get("/admin/registrations");
    const json = await res.json();
    const entry = (json.pending || []).find((p) => p.email === email);
    if (!entry) throw new Error(`no pending entry for ${email}`);

    // Retrieve CSRF token from admin dashboard
    await page.goto("/admin/dashboard");
    const csrf = await page.evaluate(() => {
        // The dashboard injects CSRF into inline script `const CSRF = "..."`
        const m = /const CSRF\s*=\s*"([^"]+)"/.exec(document.documentElement.innerHTML);
        return m ? m[1] : null;
    });
    if (!csrf) throw new Error("csrf token not found on admin dashboard");

    const ar = await page.request.post(`/admin/registrations/${entry.id}/approve`, {
        form: { _csrf: csrf },
    });
    expect(ar.ok()).toBeTruthy();
    return entry.username;
}

module.exports = {
    ADMIN_USERNAME,
    ADMIN_PASSWORD,
    loginAs,
    loginAdmin,
    logout,
    registerTeacher,
    approvePendingByEmail,
};
