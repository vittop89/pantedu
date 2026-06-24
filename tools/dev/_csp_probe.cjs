/**
 * CSP report-only probe — visita pagine teacher e raccoglie le violazioni
 * `securitypolicyviolation` che la policy strict (nonce + strict-dynamic)
 * genererebbe. Serve a costruire il worklist esatto per il passaggio a
 * enforce. Richiede CSP_MODE=report-only attivo + login docente.
 *
 * Uso (con env già caricate da .env.local):
 *   node tools/dev/_csp_probe.cjs
 */
const { chromium } = require("@playwright/test");

const BASE = process.env.FM_E2E_BASE_URL || "http://pantedu.local";
const USER = process.env.E2E_TEACHER_USER || process.env.FM_U;
const PASS = process.env.E2E_TEACHER_PASS || process.env.FM_P;

const PAGES = process.env.CSP_PROBE_PAGES
    ? process.env.CSP_PROBE_PAGES.split(",")
    : [
        "/?home=1",
        "/teacher/dashboard",
        "/studio",
        "/exercises",
        "/risdoc",
        "/me/profilo",
        // admin (docente1 è admin)
        "/admin/dashboard",
        "/admin/logs",
        "/admin/backup",
        "/admin/institutes",
        "/admin/sidebar-config",
        "/admin/waf/blocks",
        "/admin/waf/config",
        "/admin/waf/rules",
        "/admin/waf/anomalies",
    ];

(async () => {
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ baseURL: BASE });
    const page = await ctx.newPage();

    // login
    await page.goto("/login");
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }).catch(() => {}),
        page.click('button[type="submit"]'),
    ]);

    const allViol = [];
    for (const path of PAGES) {
        const viol = [];
        await page.exposeFunction(`__cspViol_${PAGES.indexOf(path)}`, (v) => viol.push(v)).catch(() => {});
        await page.addInitScript(() => {
            document.addEventListener("securitypolicyviolation", (e) => {
                // dedup-ish: invia al collector globale via console (parsabile)
                console.log("CSPVIOL " + JSON.stringify({
                    directive: e.violatedDirective,
                    blocked: e.blockedURI,
                    source: e.sourceFile,
                    line: e.lineNumber,
                    sample: (e.sample || "").slice(0, 80),
                }));
            });
        });
        const onConsole = (msg) => {
            const t = msg.text();
            if (t.startsWith("CSPVIOL ")) {
                try { viol.push(JSON.parse(t.slice(8))); } catch (_) {}
            }
        };
        page.on("console", onConsole);
        try {
            await page.goto(path, { waitUntil: "networkidle", timeout: 20000 });
            await page.waitForTimeout(1500);
        } catch (e) {
            viol.push({ directive: "(nav-error)", blocked: e.message.slice(0, 80) });
        }
        page.off("console", onConsole);
        // aggrega per (directive, source, line)
        const seen = new Map();
        for (const v of viol) {
            const k = `${v.directive}|${v.source || ""}|${v.line || ""}|${v.sample || ""}`;
            seen.set(k, (seen.get(k) || 0) + 1);
        }
        console.log(`\n=== ${path} — ${viol.length} violazioni (${seen.size} uniche) ===`);
        for (const [k, n] of seen) console.log(`  [${n}x] ${k}`);
        allViol.push({ path, count: viol.length, unique: seen.size });
    }

    console.log("\n===== SOMMARIO =====");
    for (const r of allViol) console.log(`  ${r.path}: ${r.count} viol (${r.unique} uniche)`);
    await browser.close();
})().catch((e) => { console.error("PROBE ERROR:", e.message); process.exit(1); });
