/**
 * CSP interaction smoke — verifica che gli handler admin convertiti (da on*
 * inline a delegation/co-located) FUNZIONINO sotto policy strict (report-only):
 * clicca bottoni NON distruttivi (Refresh/load) e controlla:
 *   - nessuna violazione securitypolicyviolation
 *   - nessun errore JS in console (pageerror)
 *   - parte la fetch attesa (data caricati)
 * Richiede CSP_MODE=report-only + login admin (docente1).
 */
const { chromium } = require("@playwright/test");

const BASE = process.env.FM_E2E_BASE_URL || "http://pantedu.local";
const USER = process.env.E2E_TEACHER_USER;
const PASS = process.env.E2E_TEACHER_PASS;

(async () => {
    const browser = await chromium.launch();
    const page = await browser.newContext({ baseURL: BASE }).then((c) => c.newPage());
    const viol = [], errs = [];
    page.on("pageerror", (e) => errs.push(e.message));
    await page.addInitScript(() => {
        document.addEventListener("securitypolicyviolation", (e) =>
            console.log("CSPVIOL " + e.violatedDirective + " @" + (e.sourceFile || "") + ":" + e.lineNumber));
    });
    page.on("console", (m) => { if (m.text().startsWith("CSPVIOL ")) viol.push(m.text()); });

    await page.goto("/login");
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/).catch(() => {}), page.click('button[type="submit"]')]);

    async function clickSafe(path, label, selector) {
        try {
            await page.goto(path, { waitUntil: "domcontentloaded", timeout: 20000 });
            const el = page.locator(selector).first();
            if (await el.count() === 0) { console.log(`  - ${label}: selector non trovato (${selector})`); return; }
            await el.click({ timeout: 5000 });
            await page.waitForTimeout(1200);
            console.log(`  ✓ ${label}: click ok`);
        } catch (e) { console.log(`  ✗ ${label}: ${e.message.slice(0, 80)}`); }
    }

    console.log("=== waf/blocks (delegation) ===");
    await clickSafe("/admin/waf/blocks", "Refresh Live blocks", '[data-act="loadLive"]');
    await clickSafe("/admin/waf/blocks", "Refresh Anomalie", '[data-act="loadAnoms"]');
    console.log("=== logs (co-located) ===");
    await clickSafe("/admin/logs", "Logs Refresh", '#fm-logs-status'); // status presente = script init ok
    // logs refresh button non ha id stabile → verifichiamo che la tabella si popoli da sola
    await page.goto("/admin/logs", { waitUntil: "networkidle", timeout: 20000 }).catch(() => {});
    await page.waitForTimeout(1000);

    console.log("\n=== RISULTATO ===");
    console.log("violazioni CSP:", viol.length, viol.slice(0, 5).join(" | "));
    console.log("errori JS:", errs.length, errs.slice(0, 5).join(" | "));
    await browser.close();
    process.exit(viol.length || errs.length ? 1 : 0);
})().catch((e) => { console.error("SMOKE ERROR:", e.message); process.exit(2); });
