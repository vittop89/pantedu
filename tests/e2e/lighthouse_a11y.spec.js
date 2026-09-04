/**
 * Lighthouse a11y score baseline check — Phase C.4.
 *
 * Run Lighthouse via Playwright headless Chrome su pagine pubbliche
 * principali. Asserta minimum score 90/100 sulla categoria accessibility.
 *
 * NB: Lighthouse score include solo controlli AUTOMATABILI (~25-30%
 * dei criteri WCAG totali). Test screen reader manuale + pa11y resta
 * necessario per coverage completa AA.
 *
 * Run:
 *   npm install --save-dev lighthouse playwright-lighthouse
 *   FM_E2E_BASE_URL=https://pantedu.eu npx playwright test lighthouse_a11y
 */

const { test, chromium } = require("@playwright/test");

let playAudit;
test.beforeAll(async () => {
    try {
        ({ playAudit } = await import("playwright-lighthouse"));
    } catch (_) {
        test.skip(true, "playwright-lighthouse non installato (npm install --save-dev playwright-lighthouse lighthouse)");
    }
});

const PAGES = [
    { path: "/",                       label: "home" },
    { path: "/login",                  label: "login" },
    { path: "/legal/tos",              label: "tos" },
    { path: "/privacy/informativa",    label: "privacy" },
    { path: "/accessibility",          label: "accessibility-statement" },
];

const MIN_SCORE_A11Y = 90;

for (const p of PAGES) {
    test(`lighthouse a11y >= ${MIN_SCORE_A11Y} on ${p.path}`, async () => {
        const browser = await chromium.launch({
            args: ["--remote-debugging-port=9222"],
        });
        const context = await browser.newContext();
        const page = await context.newPage();

        try {
            await page.goto(p.path);
            await page.waitForLoadState("networkidle", { timeout: 10000 });

            const result = await playAudit({
                page,
                port: 9222,
                thresholds: {
                    accessibility: MIN_SCORE_A11Y,
                    performance: 0,
                    "best-practices": 0,
                    seo: 0,
                    pwa: 0,
                },
                reports: {
                    formats: { html: true, json: true },
                    name: `lighthouse-${p.label}`,
                    directory: "tests/e2e/lighthouse-reports",
                },
            });

            const score = result?.lhr?.categories?.accessibility?.score;
            console.log(`  ${p.path} -> a11y score: ${score ? (score * 100).toFixed(0) : "?"} / 100`);
        } finally {
            await browser.close();
        }
    });
}
