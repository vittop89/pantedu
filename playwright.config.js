/**
 * Configurazione Playwright per e2e Pantedu.
 *
 * Target: XAMPP Apache su pantedu.local (vhost già configurato).
 * Autenticazione: admin vive in log/data/admin_users.json;
 * teacher/studenti vengono creati al volo via /register e approvati
 * per test via helper fixtures.
 */

const { defineConfig, devices } = require("@playwright/test");

// ADR-024 — carica E2E_*/FM_E2E_* da .env.local (gitignored). Le credenziali
// docente NON vivono più hardcoded nei file .spec committati: gli spec leggono
// process.env.E2E_TEACHER_USER / E2E_TEACHER_PASS. Fallback: env già esportate.
try {
    const fs = require("fs");
    const path = require("path");
    const raw = fs.readFileSync(path.join(__dirname, ".env.local"), "utf8");
    for (const line of raw.split(/\r?\n/)) {
        const m = line.match(/^\s*(E2E_[A-Z0-9_]+|FM_E2E_[A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
        if (m && !process.env[m[1]]) process.env[m[1]] = m[2];
    }
} catch (_) { /* .env.local assente in CI: credenziali via env di shell */ }

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://pantedu.local";

module.exports = defineConfig({
    testDir: "./tests/e2e",
    timeout: 30_000,
    expect: { timeout: 5_000 },
    fullyParallel: false,          // sessioni sidestepping fra test
    retries: 0,
    reporter: [["list"], ["html", { open: "never" }]],

    use: {
        baseURL:       BASE_URL,
        trace:         "on-first-retry",
        screenshot:    "only-on-failure",
        video:         "retain-on-failure",
        navigationTimeout: 15_000,
        actionTimeout:     8_000,
    },

    projects: [
        {
            name: "chromium",
            use: { ...devices["Desktop Chrome"] },
        },
    ],

    outputDir: "tests/e2e-results",
});
