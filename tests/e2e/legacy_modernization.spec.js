/**
 * Verifica modernizzazione legacy (Phase 13):
 *   - AuthCode.php neutralizzato quando ExerciseViewController wrappa
 *     (no debug log duplicato)
 *   - CopilotController (route moderna sostituisce api/copilot*.php)
 *   - /admin/tools/hash UI moderna (sostituisce log/admin/generate_hash.php)
 *
 * NB (2026-06-03): rimossi i test del rewrite client-side /eser/→/studio/
 * (parseLegacyEserPath + rewriteLegacyEserLinksToStudio): la feature era
 * stata disattivata in Phase 15 (redirect 302 server-side) e il dead code
 * è stato eliminato da bootstrap-compat.js.
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "legacy_modernization");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 15_000 });

test.describe("Phase 13 — legacy modernization", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
    });

    test("AuthCode.php skip (FM_LEGACY_WRAPPED) — pagina /eser/ serve senza duplicati log", async ({ page }) => {
        // Visitando /eser/... il router esegue auth middleware, poi
        // ExerciseViewController define FM_LEGACY_WRAPPED, poi include la
        // pagina legacy. AuthCode.php early-return → nessun side effect.
        // Non abbiamo accesso al log server-side da E2E; verifichiamo
        // almeno che la pagina renderizza (no 500).
        const res = await page.request.get(
            "/eser/sc/eser_sc2s/MAT/2.0_MAT-Sistemi_lineari-sc2s.php",
        );
        expect([200, 302]).toContain(res.status());
    });

    test("/admin/tools/hash UI moderna genera hash via /admin/generate-hash", async ({ page }) => {
        await page.goto("/admin/tools/hash");
        await expect(page.locator("h1")).toContainText("Generatore hash");
        await page.fill('input[name="password"]', "TestPass!@");
        // Submit via evaluate per leggere la response promise
        const hashResult = await page.evaluate(async () => {
            const form = document.getElementById("hash-form");
            const fd   = new FormData(form);
            const res  = await fetch("/admin/generate-hash", {
                method: "POST",
                credentials: "same-origin",
                body: new URLSearchParams(fd).toString(),
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
            });
            return { status: res.status, body: await res.json() };
        });
        expect(hashResult.status).toBe(200);
        expect(hashResult.body.ok).toBe(true);
        expect(hashResult.body.hash).toMatch(/^\$2y\$/);
        await shot(page, "02_hash_tool");
    });

    test("CopilotController endpoint /api/copilot/chat esiste + blocca senza CSRF", async ({ page }) => {
        // Senza CSRF il middleware rifiuta: 403 (forbidden) / 419 (csrf
        // invalid) / 400 / 500 a seconda di dove intercetta. L'importante
        // è che NON passi come 200 (no auth bypass).
        const noCsrf = await page.request.post("/api/copilot/chat", {
            data: { token: "sk-test", payload: {} },
        });
        expect(noCsrf.status()).not.toBe(200);
        expect(noCsrf.status()).toBeGreaterThanOrEqual(400);
    });

    test("Unit 4: modali/cookie usano il prefix fm-modal / fm-cookie", async ({ page }) => {
        // Verifica che il partial modals.php renderizzi con i nuovi ID/classi
        // e che non siano più presenti i vecchi legacy. Le classi state runtime
        // (.active, .is-open) sono fuori scope.
        await page.goto("/?home=1");
        await page.waitForLoadState("domcontentloaded");

        const snapshot = await page.evaluate(() => {
            const ids = [
                "fm-modal-overlay",
                "fm-license-modal",
                "fm-cookie-modal",
                "fm-author-modal",
                "fm-license-section",
            ];
            const legacyIds = [
                "modal-overlay",
                "license-info-modal",
                "cookie-consent-modal",
                "author-banner",
            ];
            const fmModals = document.querySelectorAll(".fm-modal").length;
            const fmModalBodies = document.querySelectorAll(".fm-modal-body").length;
            const fmModalClose = document.querySelectorAll(".fm-modal-close").length;
            const fmCookieCat = document.querySelectorAll(".fm-cookie-cat").length;
            const legacyBannerModal = document.querySelectorAll(".banner-modal").length;
            const legacyBannerContent = document.querySelectorAll(".banner-content").length;
            const legacyCloseBtn = document.querySelectorAll(".close-banner-btn").length;
            const legacyCookieCategory = document.querySelectorAll(".cookie-category").length;
            return {
                fmFound: ids.filter((id) => !!document.getElementById(id)),
                legacyFound: legacyIds.filter((id) => !!document.getElementById(id)),
                fmModals, fmModalBodies, fmModalClose, fmCookieCat,
                legacyBannerModal, legacyBannerContent, legacyCloseBtn, legacyCookieCategory,
            };
        });

        // Positivi: i nuovi ID esistono, le nuove classi sono in uso
        expect(snapshot.fmFound).toEqual(
            expect.arrayContaining(["fm-modal-overlay", "fm-license-modal", "fm-cookie-modal", "fm-author-modal", "fm-license-section"]),
        );
        expect(snapshot.fmModals).toBeGreaterThanOrEqual(3);
        expect(snapshot.fmModalBodies).toBeGreaterThanOrEqual(3);
        expect(snapshot.fmModalClose).toBeGreaterThanOrEqual(3);
        expect(snapshot.fmCookieCat).toBeGreaterThanOrEqual(2);

        // Negativi: nessun residuo legacy (in zona modali/cookie)
        expect(snapshot.legacyFound).toEqual([]);
        expect(snapshot.legacyBannerModal).toBe(0);
        expect(snapshot.legacyBannerContent).toBe(0);
        expect(snapshot.legacyCloseBtn).toBe(0);
        // .cookie-category potrebbe apparire nel partial privacy-policy standalone,
        // ma non nel rendering della home (modals.php only).
        expect(snapshot.legacyCookieCategory).toBe(0);
    });
});
