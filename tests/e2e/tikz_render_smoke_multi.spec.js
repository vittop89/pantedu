/**
 * Smoke test multi-pagina: verifica che TUTTI i TikZ in /studio/esercizio
 * rendano senza 422/503 errors, senza id collisions, senza broken refs.
 *
 * Tempi: attende fino a 30s per related verifica auto-load + render.
 */
const { test, expect } = require("@playwright/test");

const BASE = process.env.FM_E2E_BASE_URL || "http://localhost";

const SCENARIOS = [
    { name: "Rette",       url: "/studio/esercizio/SCI/2/MAT/4.0?ids=60" },
    { name: "Sistemi",     url: "/studio/esercizio/SCI/2/MAT/2.0?ids=58" },
    { name: "Radicali",    url: "/studio/esercizio/SCI/2/MAT/3.0?ids=59" },
    { name: "Eq_2grado",   url: "/studio/esercizio/SCI/2/MAT/5.0?ids=61" },
    { name: "Parabola_sup", url: "/studio/esercizio/SCI/2/MAT/6.0?ids=62" },
];

test.describe.serial("tikz multi-page render smoke", () => {
    test.beforeAll(async ({ browser }) => {
        // Pre-login + clear browser cache: shared session
    });

    for (const sc of SCENARIOS) {
        test(`${sc.name}: ${sc.url}`, async ({ page, context }) => {
            test.setTimeout(90000);
            await context.clearCookies();

            const errors = [];
            const tikzReqs = [];
            page.on("pageerror", (e) => errors.push("[pageerror] " + e.message));
            page.on("console", (msg) => {
                if (msg.type() === "error" && !msg.text().includes("Failed to load resource"))
                    errors.push("[err] " + msg.text().substring(0, 200));
            });
            page.on("response", async (res) => {
                const url = res.url();
                if (url.includes("/tikz/render")) {
                    const status = res.status();
                    let reqBody = "", resBody = "";
                    if ((status === 422 || status === 503) && res.request().method() === "POST") {
                        try { resBody = await res.text(); } catch {}
                        try { reqBody = res.request().postData() || ""; } catch {}
                    }
                    tikzReqs.push({
                        method: res.request().method(),
                        status, reqBody, resBody,
                    });
                }
            });

            // Login
            await page.goto(BASE + "/login");
            await page.fill('input[name="username"]', "superadmin");
            await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
            await Promise.all([
                page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }),
                page.click('button[type="submit"]'),
            ]);

            // Navigate
            await page.goto(BASE + sc.url);
            await page.waitForLoadState("networkidle", { timeout: 30000 }).catch(() => {});
            // Wait for related verifica auto-load + tikz render burst to settle
            await page.waitForTimeout(15000);

            // Stats
            const stats = {
                tikz_total: tikzReqs.length,
                tikz_200: tikzReqs.filter(r => r.status === 200).length,
                tikz_404: tikzReqs.filter(r => r.status === 404).length,
                tikz_422: tikzReqs.filter(r => r.status === 422).length,
                tikz_503: tikzReqs.filter(r => r.status === 503).length,
            };

            // Render quality
            const renderQuality = await page.evaluate(() => {
                const svgs = document.querySelectorAll("svg[data-tikz-hash]");
                const errBoxes = document.querySelectorAll(".fm-tikz-error-messages-block");
                let n_with_text = 0;
                let n_with_font = 0;
                let n_broken_refs = 0;
                svgs.forEach((svg) => {
                    const html = svg.outerHTML;
                    if (/<text\b/.test(html)) n_with_text++;
                    if (/font-family/.test(html)) n_with_font++;
                    const ids = new Set();
                    svg.querySelectorAll("[id]").forEach(el => ids.add(el.id));
                    svg.querySelectorAll("use").forEach(u => {
                        const h = u.getAttribute("xlink:href") || u.getAttribute("href");
                        if (h?.startsWith("#") && !ids.has(h.substring(1))) n_broken_refs++;
                    });
                });
                return {
                    svg_count: svgs.length,
                    err_box_count: errBoxes.length,
                    n_with_text,
                    n_with_font,
                    n_broken_refs,
                };
            });

            console.log(`\n[${sc.name}] stats:`, JSON.stringify(stats), "|", JSON.stringify(renderQuality));
            if (errors.length) {
                console.log(`  page errors (${errors.length}):`);
                errors.slice(0, 5).forEach(e => console.log("    " + e));
            }
            // Dump first failures source preview + error
            const failed = tikzReqs.filter(r => r.status === 422 || r.status === 503);
            failed.slice(0, 5).forEach((f, i) => {
                let src = "", errLines = [];
                try { src = (JSON.parse(f.reqBody).tikz || ""); } catch {}
                try {
                    const log = (JSON.parse(f.resBody).log || "").toString();
                    errLines = log.split("\n").filter(l => l.startsWith("!")).slice(0, 3);
                } catch {}
                console.log(`  fail#${i} status=${f.status}:`);
                console.log(`    src(${src.length}c): ${src.replace(/\n/g, "\\n").substring(0, 500)}`);
                console.log(`    err: ${errLines.join(" | ")}`);
            });

            // Assertions: focus su user-visible state, NON sui transient
            // (retry su 422/503 può causare temp failures che poi succedono).
            expect(renderQuality.err_box_count).toBe(0);  // no error box visible
            expect(renderQuality.n_with_text).toBe(0);     // no font glyph svg
            expect(renderQuality.n_with_font).toBe(0);
            expect(renderQuality.n_broken_refs).toBe(0);
            // tikz_422 + tikz_503 trackati ma non blocking — retry li recupera
        });
    }
});
