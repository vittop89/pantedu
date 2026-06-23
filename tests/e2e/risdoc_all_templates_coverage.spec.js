// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * E2E coverage serio: per ogni template risdoc renderizza su pilot
 * corrente, salva screenshot, misura contenuto, testa interazioni base,
 * confronta con legacy quando possibile.
 *
 * Output:
 *   tests/e2e-results/coverage/<branch>/<id>-<slug>.png
 *   tests/e2e-results/coverage/<branch>/report.json
 *
 * Uso:
 *   npm run e2e -- tests/e2e/risdoc_all_templates_coverage.spec.js
 */

const { test, expect } = require("@playwright/test");
const fs   = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const TEMPLATES = [
    { id: 16, name: "Piano_annuale_(docente)",        slug: "piano-annuale-docente",        complexity: "high" },
    { id: 17, name: "Scheda_progetto_(FIS)",          slug: "scheda-progetto-fis",          complexity: "high" },
    { id: 18, name: "Rendicontazione_progetto",       slug: "rendicontazione-progetto",     complexity: "medium" },
    { id: 19, name: "Relazione_finale_classe",        slug: "relazione-finale-classe-docente", complexity: "high" },
    { id: 20, name: "Scheda_di_recupero",             slug: "scheda-di-recupero",           complexity: "medium" },
    { id: 21, name: "Relazione_recupero_debiti",      slug: "relazione-recupero-debiti",    complexity: "medium" },
    { id: 22, name: "Obiettivi_disciplinari_LG2010",  slug: "obiettivi-disciplinari-lg2010", complexity: "high" },
    { id: 23, name: "Obiettivi_disciplinari_DIPART",  slug: "obiettivi-disciplinari-dipart", complexity: "high" },
    { id: 24, name: "Programma_svolto",               slug: "programma-svolto",             complexity: "medium" },
    { id: 25, name: "Motivazione_voti",               slug: "motivazione-voti",             complexity: "medium" },
    { id: 26, name: "Cosa_sono",                      slug: "cosa-sono",                    complexity: "low" },
    { id: 27, name: "Legislazione",                   slug: "legislazione",                 complexity: "low" },
    { id: 28, name: "Glossario",                      slug: "glossario",                    complexity: "low" },
    { id: 29, name: "Verifiche_e_Recuperi",           slug: "verifiche-e-recuperi",         complexity: "low" },
    { id: 30, name: "Autorizzazione",                 slug: "autorizzazione",               complexity: "medium" },
];

function currentBranch() {
    try { return execSync("git rev-parse --abbrev-ref HEAD", { encoding: "utf8" }).trim(); }
    catch { return "unknown"; }
}

const BRANCH = currentBranch();
const OUT = path.join(__dirname, "..", "e2e-results", "coverage", BRANCH.replace(/[^\w-]/g, "_"));
fs.mkdirSync(OUT, { recursive: true });

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

const REPORT = {
    branch: BRANCH,
    ranAt: new Date().toISOString(),
    templates: {},
};

test.describe.serial("Coverage serio: tutti i template risdoc", () => {
    test.afterAll(async () => {
        fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(REPORT, null, 2));
        console.log("\n=== REPORT SUMMARY ===");
        console.log(`Branch: ${REPORT.branch}`);
        const stats = Object.values(REPORT.templates).reduce(
            (a, v) => ({ pass: a.pass + (v.status === "PASS"), partial: a.partial + (v.status === "PARTIAL"), fail: a.fail + (v.status === "FAIL") }),
            { pass: 0, partial: 0, fail: 0 }
        );
        console.log(`PASS: ${stats.pass}  PARTIAL: ${stats.partial}  FAIL: ${stats.fail}`);
        console.log(`Report salvato: ${path.join(OUT, "report.json")}`);
    });

    for (const tmpl of TEMPLATES) {
        test.skip(`[${tmpl.id}] ${tmpl.name} (${tmpl.complexity})`, async ({ page }) => {
            const errors = [];
            page.on("pageerror", e => errors.push({ type: "pageerror", msg: e.message }));
            page.on("console", m => { if (m.type() === "error") errors.push({ type: "console", msg: m.text().slice(0, 300) }); });
            const networkFailures = [];
            page.on("requestfailed", r => networkFailures.push(r.url()));

            await login(page);
            const resp = await page.goto(`/risdoc/view/${tmpl.id}`);
            const status = resp?.status();
            if (status !== 200) {
                REPORT.templates[tmpl.id] = {
                    name: tmpl.name, slug: tmpl.slug, complexity: tmpl.complexity,
                    status: "FAIL", httpStatus: status, errors,
                };
                return;
            }
            // Attendi rendering (Lit CDN / schema render / jQuery + MathJax)
            await page.waitForTimeout(4000);

            const diag = await page.evaluate(() => {
                const view = document.querySelector(".fm-risdoc-view");
                const toolbar = document.querySelector(".fm-risdoc-toolbar");
                const content = document.getElementById("fm-risdoc-content");
                const wc = document.querySelector("fm-risdoc-template");
                const contentText = (content?.innerText || "").trim();
                const headerTitle = document.querySelector(".header-title")?.textContent?.trim()
                                 || wc?.shadowRoot?.querySelector("fm-risdoc-section-header")?.shadowRoot?.querySelector(".header-title")?.textContent?.trim()
                                 || null;
                return {
                    viewExists: !!view,
                    toolbarExists: !!toolbar,
                    contentLen: contentText.length,
                    hasWebComponent: !!wc,
                    wcShadowRendered: !!(wc?.shadowRoot?.children?.length),
                    webComponentError: wc?.shadowRoot?.querySelector(".error")?.textContent?.trim() || null,
                    headerTitle,
                    hasHeader: !!document.querySelector(".fm-risdoc-view .header"),
                    hasPageContainer: !!document.querySelector(".page-container"),
                    fieldCount: document.querySelectorAll("input, select, textarea").length,
                    sectionCount: document.querySelectorAll(".section, .giudizio-item, fm-risdoc-giudizio-group").length,
                    bodyClass: document.body.className,
                };
            });

            // Screenshot (full page, pianola verticale)
            const screenshotPath = path.join(OUT, `${String(tmpl.id).padStart(2,"0")}-${tmpl.slug}.png`);
            try {
                await page.screenshot({ path: screenshotPath, fullPage: true });
            } catch {}

            // Test interattivo minimo: se esiste un <select>, prova a cambiare valore
            let interactionOk = null;
            const firstSelect = await page.locator("select").first();
            if (await firstSelect.count() > 0) {
                try {
                    const options = await firstSelect.evaluate(s => Array.from(s.options).map(o => o.value).filter(Boolean));
                    if (options.length > 0) {
                        await firstSelect.selectOption(options[0]);
                        await page.waitForTimeout(200);
                        interactionOk = true;
                    }
                } catch (e) { interactionOk = false; }
            }

            // Determina status finale
            let finalStatus = "FAIL";
            if (diag.viewExists && diag.toolbarExists) {
                const renderedOk = diag.contentLen > 50 || diag.wcShadowRendered;
                const noCriticalErrors = errors.filter(e => e.type === "pageerror").length === 0;
                finalStatus = renderedOk && noCriticalErrors ? "PASS" : "PARTIAL";
            }

            REPORT.templates[tmpl.id] = {
                name: tmpl.name,
                slug: tmpl.slug,
                complexity: tmpl.complexity,
                status: finalStatus,
                httpStatus: status,
                diag,
                interactionOk,
                networkFailures: networkFailures.length,
                errors: errors.slice(0, 10),
                screenshot: path.basename(screenshotPath),
            };

            console.log(
                `[${tmpl.id}] ${finalStatus.padEnd(7)} | ` +
                `len:${diag.contentLen} WC:${diag.wcShadowRendered ? "Y" : "N"} ` +
                `fields:${diag.fieldCount} err:${errors.length} net:${networkFailures.length}`
            );
        });
    }
});
