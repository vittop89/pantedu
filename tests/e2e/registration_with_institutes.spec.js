/**
 * E2E (Phase 13) — registrazione con istituti.
 *
 *  - Form /register: select institute_id (student) o institute_ids[] (teacher)
 *  - POST /register: salva institute_id/_ids su entry pending
 *  - Admin approve: persistenza users.institute_id (student) +
 *    teacher_institutes pivot (teacher)
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin, approvePendingByEmail } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "registration_institutes");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 10_000 });

const STUDENT_INST = "E2E-INST-STUD-" + Math.random().toString(36).slice(2, 6).toUpperCase();
const TEACHER_INST_A = "E2E-INST-TEA-A-" + Math.random().toString(36).slice(2, 6).toUpperCase();
const TEACHER_INST_B = "E2E-INST-TEA-B-" + Math.random().toString(36).slice(2, 6).toUpperCase();

test.describe("Registration con istituti (Phase 13)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
    });

    test("Form /register mostra select istituto + toggla student/teacher", async ({ page }) => {
        await page.goto("/register");
        await expect(page.locator("#fm-reg-inst-student")).toBeVisible();
        await expect(page.locator("#fm-reg-inst-teacher")).toBeHidden();

        // Cambia ruolo a teacher
        await page.selectOption("#role", "teacher");
        await expect(page.locator("#fm-reg-inst-student")).toBeHidden();
        await expect(page.locator("#fm-reg-inst-teacher")).toBeVisible();
        // Multi-select presente
        await expect(page.locator("#institute_ids")).toHaveAttribute("multiple", "");
        await shot(page, "01_register_form");
    });

    test("Submit student senza istituto → errore institute_required", async ({ page }) => {
        await page.goto("/register");
        await page.fill('input[name="first_name"]', "Studente");
        await page.fill('input[name="last_name"]',  "Senza Istituto");
        await page.fill('input[name="email"]',      "stud_no_inst_" + Date.now() + "@e2e.test");
        await page.fill('input[name="password"]',   "PasswordE2E_2024!");
        // Bypass client-side required: lasciamo institute_id vuoto ma
        // settiamo indirizzo+classe (Phase 13.5: section_required scattava
        // PRIMA di institute_required senza questi campi).
        await expect.poll(async () =>
            await page.locator('#reg_indirizzo option').count(),
            { timeout: 5_000 }).toBeGreaterThan(1);
        await page.selectOption('#reg_indirizzo', { index: 1 });
        await page.selectOption('#reg_classe',    { index: 1 });
        await page.evaluate(() => {
            document.getElementById("institute_id").required = false;
        });
        await Promise.all([
            page.waitForURL(/\/register\?(error|ok)/),
            page.click('button[type="submit"]'),
        ]);
        expect(page.url()).toContain("error=institute_required");
    });

    test("Submit student con istituto → pending entry contiene institute_id", async ({ page, request }) => {
        // Setup: admin crea istituto
        await loginAdmin(page);
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const ic = await page.evaluate(async ({ code, csrf }) => {
            const body = new URLSearchParams({ code, name: "Istituto " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return r.json();
        }, { code: STUDENT_INST, csrf });
        expect(ic.ok).toBe(true);
        const instId = ic.id;
        await page.context().clearCookies();

        // Studente registra (Phase 13.5: serve anche indirizzo+classe)
        await page.goto("/register");
        await page.fill('input[name="first_name"]', "Mario");
        await page.fill('input[name="last_name"]',  "Studente");
        const studEmail = "stud_inst_" + Date.now() + "@e2e.test";
        await page.fill('input[name="email"]',      studEmail);
        await page.fill('input[name="password"]',   "PasswordE2E_2024!");
        await page.selectOption('#institute_id', String(instId));
        await expect.poll(async () =>
            await page.locator('#reg_indirizzo option').count(),
            { timeout: 5_000 }).toBeGreaterThan(1);
        await page.selectOption('#reg_indirizzo', { index: 1 });
        await page.selectOption('#reg_classe',    { index: 1 });
        await Promise.all([
            page.waitForURL(/\/register\?(ok|error)/),
            page.click('button[type="submit"]'),
        ]);
        expect(page.url()).toContain("ok=1");

        // Admin verifica pending entry contiene institute_id
        await loginAdmin(page);
        const pending = await page.evaluate(async () => (await (await fetch("/admin/registrations")).json()));
        const entry = (pending.pending || []).find(e => e.email === studEmail);
        expect(entry, "entry pending presente").toBeTruthy();
        expect(entry.institute_id).toBe(instId);
    });

    test("Submit teacher con N istituti → pending entry contiene institute_ids[]", async ({ page }) => {
        // Setup admin: crea 2 istituti
        await loginAdmin(page);
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);
        const idA = (await page.evaluate(async ({ code, csrf }) => {
            const body = new URLSearchParams({ code, name: "Inst " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() });
            return r.json();
        }, { code: TEACHER_INST_A, csrf })).id;
        const idB = (await page.evaluate(async ({ code, csrf }) => {
            const body = new URLSearchParams({ code, name: "Inst " + code, _csrf: csrf });
            const r = await fetch("/api/institutes", { method: "POST", credentials: "same-origin", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString() });
            return r.json();
        }, { code: TEACHER_INST_B, csrf })).id;
        await page.context().clearCookies();

        // Teacher registra con 2 istituti
        await page.goto("/register");
        await page.selectOption("#role", "teacher");
        await page.fill('input[name="first_name"]', "Prof");
        await page.fill('input[name="last_name"]',  "Multi-Istituto");
        const tEmail = "teach_multi_" + Date.now() + "@e2e.test";
        await page.fill('input[name="email"]', tEmail);
        await page.fill('input[name="password"]', "PasswordE2E_2024!");
        await page.selectOption('#institute_ids', [String(idA), String(idB)]);
        await Promise.all([
            page.waitForURL(/\/register\?(ok|error)/),
            page.click('button[type="submit"]'),
        ]);
        expect(page.url()).toContain("ok=1");

        // Admin: verifica pending contains entrambi
        await loginAdmin(page);
        const pending = await page.evaluate(async () => (await (await fetch("/admin/registrations")).json()));
        const entry = (pending.pending || []).find(e => e.email === tEmail);
        expect(entry).toBeTruthy();
        expect(entry.institute_ids).toEqual(expect.arrayContaining([idA, idB]));
    });
});
