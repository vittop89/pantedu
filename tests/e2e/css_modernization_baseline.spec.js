/**
 * Visual regression baseline — CSS modernization Sprint H+
 *
 * Cattura screenshot baseline per pagine chiave prima di refactor legacy
 * (classi WARM/HOT identificate in docs/analysis/legacy-class-summary.md).
 *
 * Esecuzione:
 *   1. Baseline (prima di refactor):
 *      FM_BASELINE=1 npx playwright test css_modernization_baseline
 *      -> salva in tests/e2e/screenshots/baseline/
 *
 *   2. Post-refactor (compare):
 *      npx playwright test css_modernization_baseline
 *      -> salva in tests/e2e/screenshots/current/ + diff in tests/e2e/screenshots/diff/
 *
 *   3. Visual diff (pixel-level):
 *      node tests/e2e/screenshots/compare-baseline.mjs
 *
 * Pagine target (covering identified HOT/WARM classes):
 *   - /esercizio/* (uses .fm-groupcollex, .fm-collection__item, .fm-sol, .fm-rm-table)
 *   - /admin/* (uses .fm-admin-access, .fm-upbar)
 *   - /editor/* (uses .fm-draggable-container, .fm-checkbox-ain)
 *   - Login + dark mode toggle (uses .fm-dark)
 */
const path = require("path");
const fs = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin, loginTeacher } = require("./helpers");

const BASELINE_MODE = process.env.FM_BASELINE === "1";
const ROOT_DIR = path.join(__dirname, "screenshots");
const TARGET_DIR = BASELINE_MODE
    ? path.join(ROOT_DIR, "baseline")
    : path.join(ROOT_DIR, "current");
fs.mkdirSync(TARGET_DIR, { recursive: true });

const shot = async (page, name) => {
    const p = path.join(TARGET_DIR, `${name}.png`);
    await page.screenshot({ path: p, fullPage: true, timeout: 15_000 });
    return p;
};

const setDarkMode = (page, dark = true) =>
    page.evaluate((d) => {
        const root = document.documentElement;
        if (d) {
            root.classList.add("fm-dark");
            root.setAttribute("data-theme", "dark");
        } else {
            root.classList.remove("fm-dark");
            root.setAttribute("data-theme", "light");
        }
    }, dark);

test.describe("CSS modernization — visual regression baseline", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
    });

    test("home-light", async ({ page }) => {
        await loginTeacher(page).catch(() => loginAdmin(page));
        await page.goto("/?home=1");
        await page.waitForLoadState("networkidle");
        await setDarkMode(page, false);
        await shot(page, "01-home-light");
    });

    test("home-dark", async ({ page }) => {
        await loginTeacher(page).catch(() => loginAdmin(page));
        await page.goto("/?home=1");
        await page.waitForLoadState("networkidle");
        await setDarkMode(page, true);
        await shot(page, "02-home-dark");
    });

    test("admin-dashboard-light", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/dashboard");
        await page.waitForLoadState("networkidle");
        await setDarkMode(page, false);
        await shot(page, "03-admin-dashboard-light");
    });

    test("admin-dashboard-dark", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/dashboard");
        await page.waitForLoadState("networkidle");
        await setDarkMode(page, true);
        await shot(page, "04-admin-dashboard-dark");
    });

    test("esercizio-route-light", async ({ page }) => {
        await loginTeacher(page).catch(() => loginAdmin(page));
        // Naviga a un esercizio "sample" — se non esiste, skippa
        await page.goto("/esercizio/sample");
        const ok = await page.locator(".fm-groupcollex, .fm-collection__item, .fm-card").first().isVisible({ timeout: 5_000 }).catch(() => false);
        test.skip(!ok, "esercizio sample page not available");
        await setDarkMode(page, false);
        await shot(page, "05-esercizio-light");
    });

    test("editor-builder-light", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/tools/editor");
        const ok = await page.locator(".fm-editor-panel, .fm-draggable-container").first().isVisible({ timeout: 5_000 }).catch(() => false);
        test.skip(!ok, "editor page not available");
        await setDarkMode(page, false);
        await shot(page, "06-editor-light");
    });

    test("topbar-modal-light-dark", async ({ page }) => {
        await loginTeacher(page).catch(() => loginAdmin(page));
        await page.goto("/?home=1");
        await page.waitForLoadState("networkidle");
        // Open modal if exists (info button)
        const infoBtn = page.locator('[data-action="info"], button.info, .fm-info-btn').first();
        if (await infoBtn.isVisible().catch(() => false)) {
            await infoBtn.click();
            await page.waitForTimeout(300);
        }
        await setDarkMode(page, false);
        await shot(page, "07-topbar-modal-light");
        await setDarkMode(page, true);
        await shot(page, "08-topbar-modal-dark");
    });
});
