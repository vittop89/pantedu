/**
 * WCAG 2.2 AA — accessibilità delle pagine AUTENTICATE (docente + admin/superadmin).
 *
 * Complementare a a11y_wcag_aa.spec.js (pagine pubbliche). Le pagine autenticate
 * non sono testabili dal CI con `php -S` (richiedono DB + sessione) → questa spec
 * gira contro un ambiente reale con login.
 *
 * Run (contro prod, con IP whitelistato nel WAF):
 *   FM_E2E_BASE_URL=https://pantedu.eu \
 *   FM_E2E_USER=... FM_E2E_PASS=... \
 *   npx playwright test a11y_authenticated --project=chromium
 *
 * Credenziali: NON hardcoded. Lette da env (vedi .env.e2e.local, gitignored).
 * superadmin è docente + super-admin → copre entrambi i rami.
 */

const { test, expect } = require("@playwright/test");
const AxeBuilder = require("@axe-core/playwright").default;

const USER = process.env.FM_E2E_USER || "";
const PASS = process.env.FM_E2E_PASS || "";

// Pagine autenticate rappresentative (docente + admin/superadmin).
const PAGES = [
    { path: "/area-docente/profilo",        label: "Profilo docente" },
    { path: "/area-docente/dashboard",      label: "Dashboard docente" },
    { path: "/me/change-password",          label: "Cambio password" },
    { path: "/admin/dashboard",             label: "Admin dashboard" },
    { path: "/admin/waf/dashboard",         label: "WAF dashboard" },
    { path: "/admin/waf/config",            label: "WAF config" },
    { path: "/admin/templates",             label: "Admin templates" },
    { path: "/admin/sidebar-config",        label: "Sidebar config" },
    { path: "/admin/institutes",            label: "Admin istituti" },
    { path: "/admin/system/deployment",     label: "Deployment / registrazione" },
];

const AXE_TAGS = [
    "wcag2a", "wcag2aa", "wcag21a", "wcag21aa", "wcag22a", "wcag22aa", "best-practice",
];

test.describe.configure({ mode: "serial" });

test("a11y autenticato: pagine docente + admin/superadmin", async ({ page }) => {
    test.setTimeout(180_000);
    // Senza credenziali (es. CI standard) la spec si SALTA invece di fallire:
    // richiede login reale + accesso al sito (tunnel/whitelist WAF). Eseguire
    // manualmente con FM_E2E_USER/FM_E2E_PASS + FM_E2E_BASE_URL=https://pantedu.eu.
    test.skip(!USER || !PASS, "FM_E2E_USER/FM_E2E_PASS assenti — audit autenticato saltato");

    // Login (gestisce eventuale challenge WAF: attende che l'URL non sia più /login).
    await page.goto("/login");
    await page.waitForLoadState("networkidle").catch(() => {});
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 30_000 }),
        page.click('button[type="submit"]'),
    ]);

    const allFindings = [];
    for (const p of PAGES) {
        const resp = await page.goto(p.path, { waitUntil: "domcontentloaded" });
        const status = resp ? resp.status() : 0;
        if (status >= 400) {
            allFindings.push({ page: p.path, error: `HTTP ${status}` });
            continue;
        }
        await page.waitForLoadState("networkidle").catch(() => {});
        await page.waitForTimeout(700); // settle animazioni/lazy

        let results;
        try {
            results = await new AxeBuilder({ page: page }).withTags(AXE_TAGS).analyze();
        } catch (e) {
            allFindings.push({ page: p.path, error: `axe: ${e.message}` });
            continue;
        }
        const critical = results.violations.filter(
            (v) => v.impact === "critical" || v.impact === "serious"
        );
        if (critical.length) {
            allFindings.push({
                page: p.path,
                violations: critical.map((v) => ({
                    id: v.id, impact: v.impact, nodes: v.nodes.length,
                    sample: v.nodes[0]?.html?.slice(0, 160),
                    target: v.nodes[0]?.target,
                })),
            });
        }
    }

    if (allFindings.length) {
        console.error("A11y autenticato — problemi:\n" + JSON.stringify(allFindings, null, 2));
    }
    expect(allFindings, "Violazioni a11y / errori su pagine autenticate").toEqual([]);
});
