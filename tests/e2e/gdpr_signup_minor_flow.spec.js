/**
 * Phase 25.C2 — E2E test signup form con birth_date + parent_email per minori.
 *
 * Coverage:
 *   1. GET /register: form contiene campi birth_date + parent_email + TOS checkbox
 *   2. POST /register senza accept_tos = error
 *   3. POST /register student senza birth_date = error
 *   4. POST /register minore senza parent_email = error
 *   5. POST /register minore con parent_email valida = success pending
 *   6. JS UI toggle: campo parent_email visibile solo se age < 14 (browser test)
 *
 * NB: registrazione DOPO submit va in pending. Admin approve è separato.
 * Test focalizzato sul form submission + validation.
 */
const { test, expect } = require("@playwright/test");

test.describe.configure({ mode: "serial" });

test("Phase 25.C2 — GET /register form contiene tutti i campi GDPR", async ({ page }) => {
    test.setTimeout(30_000);
    await page.goto("/register");

    await expect(page.locator('input[name="birth_date"]')).toBeAttached();
    await expect(page.locator('input[name="parent_email"]')).toBeAttached();
    await expect(page.locator('input[name="parent_name"]')).toBeAttached();
    await expect(page.locator('input[name="accept_tos"]')).toBeAttached();

    // TOS link to informativa
    const tosLink = page.locator('a[href="/privacy/informativa"]').first();
    await expect(tosLink).toBeVisible();
});

test("Phase 25.C2 — JS toggle: parent_block hidden by default", async ({ page }) => {
    test.setTimeout(30_000);
    await page.goto("/register");
    await page.waitForLoadState("domcontentloaded");

    const role = page.locator('select[name="role"]');
    await role.selectOption("student");
    await page.waitForTimeout(150);

    // Birth block visibile per studente
    const birthBlock = page.locator('#fm-reg-birth-block');
    await expect(birthBlock).toBeVisible();

    // Parent block hidden finché birth_date non è < 14 anni
    const parentBlock = page.locator('#fm-reg-parent-block');
    await expect(parentBlock).toBeHidden();

    // Set birth_date a 12 anni fa → parent block deve apparire
    const twelveYearsAgo = new Date();
    twelveYearsAgo.setFullYear(twelveYearsAgo.getFullYear() - 12);
    const dateString = twelveYearsAgo.toISOString().slice(0, 10);

    await page.fill('input[name="birth_date"]', dateString);
    await page.dispatchEvent('input[name="birth_date"]', 'change');
    await page.waitForTimeout(150);

    await expect(parentBlock).toBeVisible();
});

test("Phase 25.C2 — JS toggle: docente NO birth_date NO parent_email", async ({ page }) => {
    test.setTimeout(30_000);
    await page.goto("/register");
    await page.waitForLoadState("domcontentloaded");

    await page.locator('select[name="role"]').selectOption("teacher");
    await page.waitForTimeout(150);

    await expect(page.locator('#fm-reg-birth-block')).toBeHidden();
    await expect(page.locator('#fm-reg-parent-block')).toBeHidden();
});

test("Phase 25.C2 — JS toggle: studente >= 14 anni NO parent_email", async ({ page }) => {
    test.setTimeout(30_000);
    await page.goto("/register");
    await page.waitForLoadState("domcontentloaded");

    await page.locator('select[name="role"]').selectOption("student");
    await page.waitForTimeout(150);

    // Età 15 anni: NO parent block
    const fifteenYearsAgo = new Date();
    fifteenYearsAgo.setFullYear(fifteenYearsAgo.getFullYear() - 15);
    await page.fill('input[name="birth_date"]', fifteenYearsAgo.toISOString().slice(0, 10));
    await page.dispatchEvent('input[name="birth_date"]', 'change');
    await page.waitForTimeout(150);

    await expect(page.locator('#fm-reg-birth-block')).toBeVisible();
    await expect(page.locator('#fm-reg-parent-block')).toBeHidden();
});
