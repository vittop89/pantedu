/**
 * Phase 5 visual regression baseline — Sprint 26.
 *
 * Estende il test visual_regression_a11y.spec.js a una baseline COMPLETA
 * per le pagine che Phase 5 layout.css cleanup potrebbe toccare. Permette
 * di eseguire mass-removal di rules legacy con safety net.
 *
 * USAGE:
 *
 *   # Step 1 — primo run su live: salva snapshot baseline
 *   FM_E2E_BASE_URL=https://pantedu.eu npx playwright test visual_regression_phase5 --update-snapshots
 *
 *   # Step 2 — dopo ogni Phase 5 layout.css change: diff vs baseline
 *   FM_E2E_BASE_URL=https://pantedu.eu npx playwright test visual_regression_phase5
 *
 *   # Step 3 — se diff atteso (rimozione legacy gia' migrata a modulo):
 *   FM_E2E_BASE_URL=https://pantedu.eu npx playwright test visual_regression_phase5 --update-snapshots
 *
 * AUTHENTICATED PAGES:
 *   Per testare anche pagine autenticate (admin/teacher), set env
 *   FM_E2E_AUTH_USER + FM_E2E_AUTH_PASS prima del run.
 *   Le pagine auth sono skip se le credenziali non sono set.
 *
 * Soglia diff: maxDiffPixelRatio 0.02 (strict). Phase 5 deve essere
 * pixel-equivalent (rimuoviamo regole gia' sovrascritte da moduli).
 */

const { test, expect } = require("@playwright/test");

/* Pagine PUBLIC — non richiedono auth */
const PUBLIC_PAGES = [
    { path: "/",              label: "home" },
    { path: "/login",         label: "login" },
    { path: "/register",      label: "register" },
    { path: "/accessibility", label: "accessibility-statement" },
    { path: "/privacy",       label: "privacy" },
    { path: "/cookie-policy", label: "cookie-policy" },
];

/* Pagine AUTH — richiedono login (FM_E2E_AUTH_USER + FM_E2E_AUTH_PASS) */
const AUTH_PAGES = [
    /* Area teacher (utenti con .sidebar layout, layout.css heavy) */
    { path: "/teacher/dashboard",   label: "teacher-dashboard" },
    { path: "/teacher/templates",   label: "teacher-templates" },

    /* Area docente */
    { path: "/area_docente/profilo",   label: "docente-profilo" },
    { path: "/area_docente/templates", label: "docente-templates" },
    { path: "/area_docente/fonti",     label: "docente-fonti" },

    /* Admin (super_admin only) */
    { path: "/admin/dashboard",      label: "admin-dashboard" },
    { path: "/admin/templates",      label: "admin-templates" },
    { path: "/admin/monitoring",     label: "admin-monitoring" },
    { path: "/admin/waf/dashboard",  label: "admin-waf-dashboard" },
    { path: "/admin/waf/anomalies",  label: "admin-waf-anomalies" },
    { path: "/admin/logs",           label: "admin-logs" },
    { path: "/admin/backup",         label: "admin-backup" },
    { path: "/admin/crypto-status",  label: "admin-crypto-status" },
];

const VIEWPORTS = [
    { name: "desktop", width: 1280, height: 720 },
    { name: "tablet",  width: 768,  height: 1024 },
    { name: "mobile",  width: 360,  height: 740 },
];

/** Disable animations + reduce flakiness + scrub PII per OSS-readiness */
async function stabilize(page) {
    await page.addStyleTag({
        content: `
            *, *::before, *::after {
                animation-duration: 0s !important;
                animation-delay: 0s !important;
                transition-duration: 0s !important;
                caret-color: transparent !important;
            }
            /* Hide timestamps che cambiano ad ogni render */
            [data-timestamp], time { visibility: hidden !important; }

            /* PII scrub: nascondi liste contenuti reali (titoli mappe/esercizi,
             * nomi studenti, email, content didattico). Le aree mantengono
             * dimensioni layout ma il content è nascosto da screenshot OSS-safe. */
            .fm-db-block li,
            .fm-db-block a,
            .fm-newcat-host,
            ul.fm-db-block,
            #fm-sp-mappe ul, #fm-sp-lab ul, #fm-sp-eser ul,
            #fm-sp-verif ul, #fm-sp-bes ul, #fm-sp-risdoc ul,
            .fm-an-table tr td:not(:first-child),
            [data-content-id], [data-template-id],
            .fm-pi-card, .fm-tpl-card, .fm-vd-block,
            .fm-search-result, .fm-ex-result,
            .fm-waf-block-row, .fm-waf-anomaly-row,
            .fm-log-line, .fm-pool-share-row {
                visibility: hidden !important;
            }
            /* Username/email scrub: sostituisce visualmente con placeholder. */
            .fm-session-user, .fm-session-banner strong,
            .fm-area-docente-page h1 + p,
            [data-username], [data-user-email] {
                color: transparent !important;
                text-shadow: 0 0 6px #888 !important;
            }
        `,
    });
}

/** Login flow per pagine auth */
async function login(page) {
    const user = process.env.FM_E2E_AUTH_USER;
    const pass = process.env.FM_E2E_AUTH_PASS;
    if (!user || !pass) return false;

    await page.goto("/login");
    await page.fill('input[name="username"]', user);
    await page.fill('input[name="password"]', pass);
    await Promise.all([
        page.waitForURL((u) => !u.toString().includes("/login"), { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
    return true;
}

/* ──────────── PUBLIC pages ──────────── */

for (const pg of PUBLIC_PAGES) {
    for (const vp of VIEWPORTS) {
        test(`P5 baseline (public): ${pg.label} @ ${vp.name}`, async ({ page }) => {
            await page.setViewportSize({ width: vp.width, height: vp.height });
            await page.goto(pg.path, { timeout: 30000 });
            await page.waitForLoadState("networkidle", { timeout: 15000 });
            await stabilize(page);

            expect(await page.screenshot({ fullPage: true })).toMatchSnapshot(
                `phase5-${pg.label}-${vp.name}.png`,
                { maxDiffPixelRatio: 0.02 },
            );
        });
    }
}

/* ──────────── AUTH pages (skipped if no credentials) ──────────── */

const HAS_AUTH = !!(process.env.FM_E2E_AUTH_USER && process.env.FM_E2E_AUTH_PASS);

/* Skip-all module side-effect avoided: registriamo test() solo se HAS_AUTH.
   Senza credenziali i test auth semplicemente non esistono (no skip globale). */
if (HAS_AUTH) for (const pg of AUTH_PAGES) {
    for (const vp of VIEWPORTS) {
        test(`P5 baseline (auth): ${pg.label} @ ${vp.name}`, async ({ page }) => {
            await page.setViewportSize({ width: vp.width, height: vp.height });
            const ok = await login(page);
            if (!ok) test.skip();

            await page.goto(pg.path, { timeout: 30000 });
            await page.waitForLoadState("networkidle", { timeout: 15000 });
            await stabilize(page);

            expect(await page.screenshot({ fullPage: true })).toMatchSnapshot(
                `phase5-${pg.label}-${vp.name}.png`,
                { maxDiffPixelRatio: 0.02 },
            );
        });
    }
}
