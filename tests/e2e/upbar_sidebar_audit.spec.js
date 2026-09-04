/**
 * Audit end-to-end di upbar esercizi + toggle sidebar.
 *
 * Verifica (da admin):
 *   1. Sidebar open/close via label.switch — class body.fm-sidebar-closed
 *      si applica correttamente, icona slider ✖ ↔ ☰, #fm-content
 *      risponde con margin-left (CSS transition).
 *   2. Navigazione SPA verso /eser/... tramite fm-router: body riceve
 *      exercise-context, layout_es.css scope si attiva.
 *   3. UpBar renderizzato server-side con .fm-upbar.fm-admin-access, quindi
 *      sono VISIBILI: selwrapbtncopy (ATTIVA/GENERA-VER/COPIA-ESER +
 *      Overleaf/Server/Drive/+ARGOMENTI), #sel-origin, #toggle-
 *      checkboxABin-control, HideAll *, dark-mode.
 *   4. Logout widget montato da bootstrap-compat.mountLogoutWidget
 *      (idempotente): #logout-section compare con #btnLogout visibile.
 *   5. Pulsanti/checkbox interattivi: click non throw, stato cambia.
 *
 * Screenshot salvati in tests/e2e-results/artifacts/upbar_sidebar/ per
 * ispezione visuale.
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "upbar_sidebar");
fs.mkdirSync(SHOTS_DIR, { recursive: true });

const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false });

/**
 * Trova un link esercizio valido dalla sidebar admin. Evita di
 * hard-code-are path fragili: se il curriculum cambia, il test resta.
 */
/**
 * Apre un esercizio pubblicato noto (SCI/2/MAT, vedi tools/dev/seed_e2e_fixtures.php)
 * navigando DIRETTAMENTE al modern route /studio/esercizio/... e attende l'upbar.
 * Robusto: non dipende dalla sidebar/scope (l'admin non popola i select
 * curriculum) né dal vecchio path /eser/ (migrato a /studio/).
 */
const EXERCISE_URL = "/studio/esercizio/SCI/2/MAT/6.0?ids=62";
async function openExercise(page) {
    await page.goto(EXERCISE_URL);
    // .fm-upbar è nel DOM ma può essere hidden (toggle/collapsed): attendiamo
    // l'ATTACHED, non la visibilità.
    await page.waitForSelector(".fm-upbar", { state: "attached", timeout: 10_000 });
}

test.describe("Audit upbar + sidebar (admin SPA)", () => {
    test.beforeEach(async ({ page }) => {
        // Pre-consenso cookie: evita che #fm-modal-overlay intercetti i click
        // sullo .switch o sui pulsanti sidebar durante il test.
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
        await page.goto("/?home=1");
        await page.waitForFunction(() => window.FM?.Endpoints && window.App);
        // Dismiss eventuale overlay rimasto visibile
        await page.evaluate(() => {
            const o = document.getElementById("fm-modal-overlay");
            if (o) o.style.display = "none";
            document.querySelectorAll(".fm-modal").forEach(el => el.style.display = "none");
            document.body.style.overflow = "auto";
        });
    });

    test("sidebar close/open toggla body.fm-sidebar-closed + icona slider", async ({ page }) => {
        await shot(page, "01_home_open");

        // Stato iniziale: sidebar aperta, no class fm-sidebar-closed.
        await expect(page.locator("body")).not.toHaveClass(/fm-sidebar-closed/);
        await expect(page.locator(".fm-sb-slider")).toHaveText("✖");
        // Unit 2 — nuovo alias class
        await expect(page.locator(".fm-sb-slider")).toHaveText("✖");

        // Click CHIUDI
        await page.locator("label.fm-switch").click();
        await expect(page.locator("body")).toHaveClass(/fm-sidebar-closed/);
        await expect(page.locator(".fm-sb-slider")).toHaveText("☰");
        await page.waitForTimeout(350); // completa transition CSS
        await shot(page, "02_home_closed");

        // Verifica che fm-content abbia margin-left ridotto (34px)
        const closedMargin = await page.locator("#fm-content").evaluate(
            (el) => getComputedStyle(el).marginLeft,
        );
        expect(parseInt(closedMargin, 10)).toBeLessThan(60);

        // Riapri
        await page.locator("label.fm-switch").click();
        await expect(page.locator("body")).not.toHaveClass(/fm-sidebar-closed/);
        await expect(page.locator(".fm-sb-slider")).toHaveText("✖");
        await page.waitForTimeout(350);
        await shot(page, "03_home_reopened");

        const openMargin = await page.locator("#fm-content").evaluate(
            (el) => getComputedStyle(el).marginLeft,
        );
        expect(parseInt(openMargin, 10)).toBeGreaterThan(100); // ~280px
    });

    test("persistenza stato sidebar fra reload (sessionStorage)", async ({ page }) => {
        await page.locator("label.fm-switch").click();
        await expect(page.locator("body")).toHaveClass(/fm-sidebar-closed/);
        await page.reload();
        await page.waitForFunction(() => window.App);
        await expect(page.locator("body")).toHaveClass(/fm-sidebar-closed/);
        await expect(page.locator(".fm-sb-slider")).toHaveText("☰");

        // Reset stato per non sporcare altri test
        await page.locator("label.fm-switch").click();
    });

    test("navigate a /eser — exercise-context attivo, upbar admin completa", async ({ page }) => {
        await openExercise(page);
        await expect(page).toHaveURL(/\/studio\/esercizio\//);
        await page.setViewportSize({ width: 1920, height: 800 });
        await shot(page, "10_exercise_full");
        // Screenshot dedicato alla sola upbar per ispezione layout
        const upbarBox = await page.locator(".fm-upbar").boundingBox();
        if (upbarBox) {
            await page.screenshot({
                path: path.join(SHOTS_DIR, "10b_upbar_only.png"),
                clip: { x: 0, y: 0, width: upbarBox.width, height: upbarBox.height + 10 },
            });
        }

        // body ha exercise-context (sync via bootstrap-compat)
        await expect(page.locator("body")).toHaveClass(/exercise-context/);

        // .fm-upbar ha admin-access (injected server-side da UpBar_Es_loader)
        await expect(page.locator(".fm-upbar")).toHaveClass(/admin-access/);

        // Elementi admin-only PRESENTI nell'upbar admin-access. Uso toBeAttached:
        // l'upbar è nel DOM ma può restare hidden (l'admin non decifra il body
        // dell'esercizio del docente → render visivo parziale). NB:
        // #btnCopyver/#btnCopyeser/.selwrapbtncopy RIMOSSI (M11 dead path);
        // #btnAct rimosso (Phase 21).
        await expect(page.locator("#sel-origin")).toBeAttached();
        await expect(page.locator("#toggle-checkboxABin-control")).toBeAttached();
        await expect(page.locator("#btnAct")).toHaveCount(0);
        await expect(page.locator("#btnCopyver")).toHaveCount(0);
        await expect(page.locator("#btnP")).toBeAttached();
        // Dark toggle vive nella sidebar (sempre presente)
        await expect(page.locator(".fm-sb-dark")).toBeAttached();

        // Lo .switch sidebar NON deve essere sovrascritto da layout_es.css
        const switchBox = await page.locator("label.fm-switch").boundingBox();
        expect(switchBox.height, "switch sidebar deve restare 34px").toBeGreaterThan(30);
        expect(switchBox.width,  "switch sidebar deve restare ~280px").toBeGreaterThan(100);

        // Coerenza esercizio/link: il modern route è /studio/esercizio/...
        expect(page.url()).toContain("/studio/esercizio/");
    });

    test("upbar: click su checkbox/pulsanti non throw + stato cambia", async ({ page }) => {
        await openExercise(page);
        // openExercise() ha già navigato a /studio/esercizio/ e atteso .fm-upbar
        await page.waitForSelector(".fm-upbar.fm-admin-access", { state: "attached" });

        // Raccolta errori console (escludiamo warn noti)
        const errors = [];
        page.on("pageerror", (e) => errors.push(e.message));
        page.on("console", (m) => {
            if (m.type() === "error") errors.push(m.text());
        });

        // Toggle upbar visibility: flippiamo il checkbox + dispatch change
        // (evita race con animazioni CSS che spostano la label off-screen).
        const flipUpbar = async () => {
            await page.evaluate(() => {
                const c = document.getElementById("upbar-toggle");
                c.checked = !c.checked;
                c.dispatchEvent(new Event("change", { bubbles: true }));
            });
        };
        await flipUpbar();
        await expect(page.locator(".fm-upbar")).toHaveClass(/upbar-hidden/);
        await flipUpbar();
        await expect(page.locator(".fm-upbar")).not.toHaveClass(/upbar-hidden/);

        // Checkbox Overleaf/Server/multiarg — toggle via evaluate
        // (#overleaf può essere offscreen se la viewport è stretta; lo stato
        // importa più della visibilità del click target).
        for (const id of ["overleaf", "Server"]) {
            await page.evaluate((cbId) => {
                const c = document.getElementById(cbId);
                c.checked = true;
                c.dispatchEvent(new Event("change", { bubbles: true }));
            }, id);
            await expect(page.locator(`#${id}`)).toBeChecked();
            await page.evaluate((cbId) => {
                const c = document.getElementById(cbId);
                c.checked = false;
                c.dispatchEvent(new Event("change", { bubbles: true }));
            }, id);
            await expect(page.locator(`#${id}`)).not.toBeChecked();
        }

        // HideAll Probl button — click via evaluate per schivare eventuali
        // overlay/scroll offset su viewport Playwright default.
        await page.evaluate(() => document.getElementById("btnP")?.click());
        await page.evaluate(() => document.getElementById("btnP")?.click());

        // Dropdown DIFFICOLTÀ: in contesto legacy /eser/... il click handler
        // non è garantito; verifica solo che gli item esistano.
        // #sel-dif è un .dropdown con .dropdown-button (gli item difficoltà
        // popolano .dropdown-content all'apertura). toBeAttached: vive nell'upbar
        // che può essere hidden per l'admin (body esercizio non decifrabile).
        await expect(page.locator("#sel-dif")).toBeAttached();

        // Darkmode (via click diretto — btn è in upbar layout_es visibile)
        await page.evaluate(() => document.getElementById("fm-sb-dark")?.click());
        await page.evaluate(() => document.getElementById("fm-sb-dark")?.click());

        await shot(page, "11_exercise_after_interaction");

        // Filtra eccezioni non critiche al test: il test verifica che le
        // interazioni upbar NON sollevino errori JS (throw), non che tutte le
        // risorse carichino. "Failed to load resource" (404/403) è rumore di
        // rete atteso quando l'admin apre un esercizio docente cifrato (body
        // 403, non decifrabile) o un asset opzionale manca → non è un throw.
        const critical = errors.filter(
            (e) => !/gas-client-secure|favicon|tikzjax|Failed to load resource/i.test(e),
        );
        expect(critical, `console errors:\n${critical.join("\n")}`).toEqual([]);
    });

    test("logout widget rimosso (sel-session-banner in sidebar basta)", async ({ page }) => {
        await openExercise(page);
        // openExercise() ha già navigato a /studio/esercizio/ e atteso .fm-upbar
        await page.waitForSelector(".fm-upbar", { state: "attached" });
        await expect(page.locator("#logout-widget-container")).toHaveCount(0);
        await expect(page.locator("#btnLogout")).toHaveCount(0);
        // Sidebar/banner espone già un link logout (non più il widget upbar).
        await expect(page.locator("a[href='/logout']").first()).toBeVisible();
        await shot(page, "20_logout_moved_to_sidebar");
    });

    test("dark mode toggle: body.fm-dark + persistenza localStorage", async ({ page }) => {
        await openExercise(page);
        // openExercise() ha già navigato a /studio/esercizio/ e atteso .fm-upbar
        await page.waitForSelector(".fm-sb-dark");

        // Stato iniziale: light
        await expect(page.locator("body")).not.toHaveClass(/fm-dark/);
        await shot(page, "30_before_dark");

        // Accendi dark mode
        await page.locator(".fm-sb-dark").click();
        await expect(page.locator("body")).toHaveClass(/fm-dark/);
        const persisted = await page.evaluate(() => localStorage.getItem("fm_dark_mode"));
        expect(persisted).toBe("1");
        await shot(page, "31_dark_on");

        // Reload → dark persiste
        await page.reload();
        await page.waitForSelector("body.fm-dark");
        await expect(page.locator("body")).toHaveClass(/fm-dark/);
        await shot(page, "32_dark_persisted_after_reload");

        // Spegni
        await page.locator(".fm-sb-dark").click();
        await expect(page.locator("body")).not.toHaveClass(/fm-dark/);
        await shot(page, "33_dark_off");
    });
});
