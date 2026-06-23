// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Audit DOM profondo: controlla che ogni template abbia i default caricati.
 * - Checkbox group count (opzioni attese dalla tex extraction)
 * - Textarea value default (da schema `default`)
 * - Dynamic-table default_rows rendering
 * - Section-header select.field status (class/sezione/ecc.)
 *
 * Reporta una matrice completa per ogni template con PASS/FAIL/NOTE.
 */

const { test } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const BRANCH = (() => {
    try { return execSync("git rev-parse --abbrev-ref HEAD", { encoding: "utf8" }).trim(); }
    catch { return "unknown"; }
})();
const OUT = path.join(__dirname, "..", "e2e-results", "defaults-audit", BRANCH.replace(/[^\w-]/g, "_"));
fs.mkdirSync(OUT, { recursive: true });

/** Atteso: numero minimo di option per checkbox-group named in ciascun template. */
const EXPECT = {
    16: {
        checkbox_options: {
            livelli_ingresso: 3,
            metodologie_didattiche: 21,
            strumenti_didattici: 15,
            spazi_didattici: 16,
            prove_strutturate: 9,
            prove_semistrutturate: 8,
            prove_non_strutturate: 8,
            prove_traduzione: 4,
            criteri_valutazione_finale: 7,
            recupero_curricolare: 7,
            recupero_extracurricolare: 7,
            valorizzazione_eccellenze: 6,
        },
        checkbox_options_min: {
            competenze_3_1: 1,      // JSON-sourced (da LSc_2_mat.json)
            abilita_3_1: 1,
            conoscenze_3_1: 1,
            competenze_3_2_min: 1,  // da obiettivi_dipartimento_minimi
            abilita_3_2_min: 1,
            conoscenze_3_2_min: 1,
            competenze_base_dm2007: 1,  // 2.1 — da competenze_DM2007.json
            competenze_pecup: 1,        // 2.2 — da competenze_PECUP.json
        },
        dynamic_table_default_rows: {
            studenti_table: 5,          // 5 labeled rows (TOTALE, DIV.ABILI, DSA+PDP, BES TOT, BES+PDP)
            testIngresso_table: 3,      // 3 fasce livello
            uda_table: 3,               // >=3 UDA groups dal conoscenze JSON (LSc_2_mat ha 14 gruppi)
        },
    },
    17: {
        checkbox_options: { obiettivi_ptof: 6 },
    },
    18: {},
    19: {
        checkbox_options: {
            metodologie_didattiche: 10,
            strumenti_didattici: 12,
            recupero_curricolare: 8,
            recupero_extracurricolare: 6,
            potenziamento: 6,
        },
        textarea_default: { educazione_civica: "Nessuna." },
    },
    20: {
        checkbox_options: { carenze_riscontrate: 6, consigli_recupero: 7 },
    },
    21: {
        checkbox_options: {
            metodologie_didattiche: 21,
            strumenti_didattici: 15,
            spazi_didattici: 16,
            abilita_specifiche: 8,
        },
        textarea_default: {
            periodo: "dal 01/09/2023 al 30/06/2024",
            risultati_specifici: /Particolari difficoltà/,
        },
    },
    22: {}, 23: {}, 24: {}, 25: {}, 26: {}, 27: {}, 28: {}, 29: {},
    30: {},
};

test.setTimeout(180000);

test.skip("risdoc deep defaults audit", async ({ browser }) => {
    const REPORT = { branch: BRANCH, ranAt: new Date().toISOString(), templates: {} };
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });

    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString()
        }));
    });

    // login
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
    await page.waitForTimeout(1000);

    const tmplList = await page.request.get("/api/risdoc/templates").then(r => r.json());
    const templates = tmplList.templates || [];
    console.log(`Found ${templates.length} templates`);

    for (const tmpl of templates) {
        const tid = tmpl.id;
        const exp = EXPECT[tid] || {};
        console.log(`\n=== ${tid} — ${tmpl.argomento} ===`);

        await page.goto(`/risdoc/view/${tid}`);
        await page.waitForTimeout(1500);

        // Simula selezione materia dal sidebar → triggera JSON fetch dei checkbox-group
        await page.evaluate(() => {
            const m = document.getElementById("sel-mater");
            if (m && !m.value) { m.value = "MAT"; m.dispatchEvent(new Event("change", { bubbles: true })); }
            const c = document.getElementById("sel-cls");
            if (c && !c.value) { c.value = "2s"; c.dispatchEvent(new Event("change", { bubbles: true })); }
            const i = document.getElementById("sel-iis");
            if (i && !i.value) { i.value = "sc"; i.dispatchEvent(new Event("change", { bubbles: true })); }
        });
        await page.waitForTimeout(3000);  // attesa fetch JSON dinamici (options_source)

        // Clean any stored compilation by bypassing (we want to see defaults only)
        // (Read-only check: no clean needed — just inspect what renders)

        const inspection = await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            if (!wc?.shadowRoot) return { error: "WC not rendered" };

            const sr = wc.shadowRoot;
            const res = {
                wcError: sr.querySelector(".error")?.textContent?.trim() || null,
                values: {},
                checkboxGroups: {},
                textareas: {},
                infoFields: {},
                sectionHeaderSelects: [],
                dynamicTables: {},
                totalShadowChildren: sr.children.length,
            };

            // Report loaded compilation values (from WC internal state)
            res.values = wc._values || null;

            // checkbox-group components
            sr.querySelectorAll("fm-risdoc-checkbox-group").forEach(cg => {
                const name = cg.section?.name || "?";
                const count = cg.shadowRoot?.querySelectorAll('input[type="checkbox"]').length || 0;
                const labels = Array.from(cg.shadowRoot?.querySelectorAll('.item label') || [])
                    .map(l => l.textContent.trim()).slice(0, 2);
                res.checkboxGroups[name] = { count, firstLabels: labels };
            });

            // nota-textarea / text-section textareas
            sr.querySelectorAll("fm-risdoc-nota-textarea, fm-risdoc-text-section").forEach(t => {
                const name = t.section?.name || "?";
                const ta = t.shadowRoot?.querySelector("textarea");
                res.textareas[name] = {
                    value: ta?.value ?? "",
                    default_in_schema: t.section?.default ?? null,
                };
            });

            // info-field
            sr.querySelectorAll("fm-risdoc-info-field").forEach(f => {
                const name = f.section?.name || "?";
                const input = f.shadowRoot?.querySelector("input, select");
                res.infoFields[name] = {
                    value: input?.value ?? "",
                    default_in_schema: f.section?.default ?? null,
                };
            });

            // section-header selectors
            sr.querySelectorAll("fm-risdoc-section-header").forEach(h => {
                const opts = Array.from(h.shadowRoot?.querySelectorAll("select.field") || [])
                    .map(sel => ({
                        name: sel.name,
                        value: sel.value,
                        optionCount: sel.options.length,
                    }));
                res.sectionHeaderSelects.push(...opts);
            });

            // dynamic-table (include uda-mode via _udaData)
            sr.querySelectorAll("fm-risdoc-dynamic-table").forEach(dt => {
                const name = dt.section?.name || "?";
                const udaCount = Array.isArray(dt._udaData) ? dt._udaData.length : 0;
                const rowCount = udaCount > 0 ? udaCount : (dt.rows?.length ?? 0);
                const firstRowCells = udaCount > 0
                    ? [`N_uda=${dt._udaData[0].N_uda}`, `moduli=${dt._udaData[0].moduli?.length ?? 0}`]
                    : ((dt.rows?.[0] && Object.keys(dt.rows[0]).map(k => `${k}=${dt.rows[0][k]}`)) || []);
                res.dynamicTables[name] = { rowCount, firstRowCells, mode: udaCount > 0 ? "uda" : "normal" };
            });

            return res;
        });

        // take screenshot
        const screenshot = path.join(OUT, `tmpl-${String(tid).padStart(2,"0")}.png`);
        await page.screenshot({ path: screenshot, fullPage: true });

        // ASSESS
        const assessment = { passes: [], fails: [] };

        if (inspection.error) {
            assessment.fails.push(`WC_error: ${inspection.error}`);
        }

        for (const [gname, expCount] of Object.entries(exp.checkbox_options || {})) {
            const got = inspection.checkboxGroups?.[gname];
            if (!got) {
                assessment.fails.push(`CB_MISSING ${gname}`);
            } else if (got.count !== expCount) {
                assessment.fails.push(`CB_COUNT ${gname}: expected ${expCount}, got ${got.count}`);
            } else {
                assessment.passes.push(`CB_OK ${gname}=${got.count}`);
            }
        }

        // Minimum counts (es. JSON-sourced lists — numero variabile)
        for (const [gname, minCount] of Object.entries(exp.checkbox_options_min || {})) {
            const got = inspection.checkboxGroups?.[gname];
            if (!got) {
                assessment.fails.push(`CB_MIN_MISSING ${gname}`);
            } else if (got.count < minCount) {
                assessment.fails.push(`CB_MIN_TOO_FEW ${gname}: min ${minCount}, got ${got.count}`);
            } else {
                assessment.passes.push(`CB_MIN_OK ${gname}>=${got.count}`);
            }
        }

        for (const [fname, expVal] of Object.entries(exp.textarea_default || {})) {
            const got = inspection.textareas?.[fname];
            if (!got) {
                assessment.fails.push(`TA_MISSING ${fname}`);
            } else if (expVal instanceof RegExp ? !expVal.test(got.value) : got.value !== expVal) {
                assessment.fails.push(`TA_VAL ${fname}: got="${got.value.slice(0,50)}"`);
            } else {
                assessment.passes.push(`TA_OK ${fname}`);
            }
        }

        for (const [tname, expRows] of Object.entries(exp.dynamic_table_default_rows || {})) {
            const got = inspection.dynamicTables?.[tname];
            if (!got) {
                assessment.fails.push(`DT_MISSING ${tname}`);
            } else if (got.rowCount < expRows) {
                assessment.fails.push(`DT_ROWS ${tname}: expected ${expRows}, got ${got.rowCount}`);
            } else {
                assessment.passes.push(`DT_OK ${tname}=${got.rowCount}`);
            }
        }

        // Section-header selectors: verify curriculum-driven options (3+) per classe/indirizzo/disciplina.
        // FAIL se un select dropdown (non input text) ha <3 opzioni → segno che /curriculum
        // non ha popolato il WC.
        const sels = inspection.sectionHeaderSelects || [];
        const curriculumKeys = ["classe", "indirizzo", "disciplina"];
        const emptyCurriculum = sels.filter(s => curriculumKeys.includes(s.name) && s.optionCount < 3);
        if (emptyCurriculum.length) {
            assessment.fails.push(`HEADER_NO_OPTIONS ${emptyCurriculum.map(s => `${s.name}(${s.optionCount})`).join(",")}`);
        } else if (sels.length) {
            assessment.passes.push(`HEADER_OPTIONS_OK ${sels.length} selectors`);
        }

        REPORT.templates[tid] = {
            argomento: tmpl.argomento,
            inspection,
            assessment,
            screenshot: path.basename(screenshot),
        };
        console.log(`  PASS: ${assessment.passes.length}  FAIL: ${assessment.fails.length}`);
        if (assessment.fails.length) {
            for (const f of assessment.fails) console.log(`    ✗ ${f}`);
        }
    }

    fs.writeFileSync(path.join(OUT, "audit.json"), JSON.stringify(REPORT, null, 2));

    const totalFails = Object.values(REPORT.templates).reduce((acc, t) => acc + t.assessment.fails.length, 0);
    const totalPasses = Object.values(REPORT.templates).reduce((acc, t) => acc + t.assessment.passes.length, 0);

    console.log(`\n========================================`);
    console.log(`TOTAL PASS: ${totalPasses}  |  TOTAL FAIL: ${totalFails}`);
    console.log(`Report: ${path.join(OUT, "audit.json")}`);

    await page.close();
});
