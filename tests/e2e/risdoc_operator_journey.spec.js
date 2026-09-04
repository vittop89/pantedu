// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * E2E Operator Journey — flusso docente completo.
 * Single-test con step sequenziali: ogni step fa screenshot + report.
 */

const { test } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const BRANCH = (() => {
    try { return execSync("git rev-parse --abbrev-ref HEAD", { encoding: "utf8" }).trim(); }
    catch { return "unknown"; }
})();
const OUT = path.join(__dirname, "..", "e2e-results", "journey", BRANCH.replace(/[^\w-]/g, "_"));
fs.mkdirSync(OUT, { recursive: true });

test.setTimeout(120000);

test.skip("Operator journey risdoc", async ({ browser }) => {
    const REPORT = { branch: BRANCH, ranAt: new Date().toISOString(), steps: [] };
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    page.on("pageerror", e => REPORT.steps.push({ n: REPORT.steps.length, ev: "pageerror", msg: e.message }));

    const snap = async (name) => {
        const p = path.join(OUT, `${String(REPORT.steps.length).padStart(2,"0")}-${name}.png`);
        try { await page.screenshot({ path: p, fullPage: true }); } catch {}
        return path.basename(p);
    };
    const step = (name, data = {}) => {
        const e = { n: REPORT.steps.length, name, ts: new Date().toISOString(), ...data };
        REPORT.steps.push(e);
        console.log(`[${e.n}] ${name}`, data.ok === false ? "❌" : "✓", data.note || "");
        return e;
    };
    const dismissModals = async () => {
        await page.evaluate(() => {
            document.querySelectorAll("#fm-modal-overlay, .fm-modal-overlay").forEach(m => m.style.display = "none");
            // accept cookie
            const btn = document.querySelector("#cookie-accept-all-btn, [data-cookie='accept']");
            btn?.click();
        });
    };

    // ── STEP 1: LOGIN ──────────────────────────────────
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary:true,functional:true,analytics:false,marketing:false,date:new Date().toISOString()
        }));
    });
    await page.goto("/login");
    await snap("01-login-form");
    step("login-form-opened");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(1500);
    await dismissModals();
    await snap("02-post-login-home");
    step("login-ok", { url: page.url() });

    // ── STEP 2: NAV DIRETTA ai template risdoc via API ─────
    // Skippo il click sulla sidebar perché l'ambiente di test può avere
    // modal overlay che intercettano. Faccio nav diretta che simula
    // comunque il risultato del click (tramite fm-router SPA).
    await dismissModals();
    const tmplList = await page.request.get("/api/risdoc/templates").then(r => r.json());
    const templates = tmplList.templates || [];
    step("templates-api-listed", { count: templates.length });
    await snap("03-home-ready");

    // ── STEP 3: per ogni template apri la vista + test render ─
    for (const tmpl of templates) {
        const resp = await page.goto(`/risdoc/view/${tmpl.id}`);
        const httpStatus = resp?.status();
        await page.waitForTimeout(3500);
        await dismissModals();

        const state = await page.evaluate(() => {
            const view = document.querySelector(".fm-risdoc-view");
            const tb = document.querySelector(".fm-risdoc-toolbar");
            const content = document.getElementById("fm-risdoc-content");
            const wc = document.querySelector("fm-risdoc-template");
            const wcShadow = wc?.shadowRoot;
            const hasError = wcShadow?.querySelector(".error")?.textContent?.trim();
            return {
                viewExists: !!view,
                toolbarExists: !!tb,
                contentLen: (content?.innerText || "").trim().length,
                fieldCount: document.querySelectorAll("input, select, textarea").length,
                sectionCount: document.querySelectorAll(".section, .giudizio-item, fm-risdoc-giudizio-group").length,
                hasWC: !!wc,
                wcShadowRendered: !!(wcShadow?.children?.length),
                wcError: hasError || null,
                bodyClass: document.body.className,
                badge: document.querySelector(".fm-risdoc-badge")?.textContent?.trim(),
            };
        });
        const screenshot = await snap(`tmpl-${String(tmpl.id).padStart(2,"0")}-${tmpl.argomento}`.replace(/[^\w-]/g, "_"));
        const ok = httpStatus === 200 && state.viewExists && state.toolbarExists &&
                   (state.contentLen > 50 || state.wcShadowRendered) && !state.wcError;
        step(`template-${tmpl.id}-${tmpl.argomento}`, { ok, httpStatus, screenshot, ...state });
    }

    // ── STEP 4: interazione Motivazione_voti (voto 8 + autocompile) ─
    await page.goto("/risdoc/view/25");
    await page.waitForTimeout(3500);
    await dismissModals();

    const interact = await page.evaluate(() => {
        // Try light DOM first (Plan A)
        let el = document.querySelector("#gradeSelector");
        if (el) {
            el.value = "8";
            el.dispatchEvent(new Event("change", { bubbles: true }));
            return { scope: "light" };
        }
        // WC (Plan B)
        const wc = document.querySelector("fm-risdoc-template");
        const gs = wc?.shadowRoot?.querySelector("fm-risdoc-grade-selector");
        const sel = gs?.shadowRoot?.querySelector("select");
        if (sel) {
            sel.value = "8";
            sel.dispatchEvent(new Event("change", { bubbles: true, composed: true }));
            return { scope: "wc" };
        }
        return { scope: "none" };
    });
    await page.waitForTimeout(1000);
    await snap("interact-voto-8-selected");

    const autofill = await page.evaluate(() => {
        const result = {};
        // Light:
        document.querySelectorAll("select.risp_giud").forEach(s => result[s.name || "?"] = s.value);
        // WC:
        const groups = document.querySelector("fm-risdoc-template")?.shadowRoot?.querySelectorAll("fm-risdoc-giudizio-group") || [];
        groups.forEach(g => g.shadowRoot?.querySelectorAll("fm-risdoc-giudizio-item").forEach(it => {
            result[it.section?.name || "?"] = it.value;
        }));
        return result;
    });
    const filledCount = Object.values(autofill).filter(v => v && v !== "").length;
    step("interact-voto-8-autocompile", {
        ok: filledCount >= 6,
        scope: interact.scope,
        filledCount,
        sample: Object.fromEntries(Object.entries(autofill).slice(0, 4)),
    });

    // ── STEP 5: save compilazione ─────────────
    const csrfRes = await page.request.get("/auth/csrf");
    const { token: csrf } = await csrfRes.json();
    const saveRes = await page.request.post("/api/risdoc/templates/25/compilations", {
        form: {
            _csrf: csrf,
            compilation_key: "journey_test_" + BRANCH.slice(0, 10),
            label: `Journey test (${BRANCH}) — ${new Date().toISOString().slice(0,10)}`,
            classe: "3", sezione: "A", indirizzo: "LICS", disciplina: "MAT",
            data: JSON.stringify({ test: "journey", filledCount, autofill }),
        },
    });
    const saveJson = await saveRes.json().catch(() => ({}));
    step("save-compilation", { ok: saveJson.ok === true, httpStatus: saveRes.status(), compilationId: saveJson.id });

    // ── STEP 6: list + show compilation ─────────
    const listJson = await (await page.request.get("/api/risdoc/templates/25/compilations")).json();
    step("compilations-list", { ok: listJson.ok && listJson.count > 0, count: listJson.count });

    const cleanup = async () => {
        for (const c of (listJson.compilations || [])) {
            if (c.label?.includes("Journey test")) {
                await page.request.post(`/api/risdoc/compilations/${c.id}/delete`, { form: { _csrf: csrf } });
            }
        }
    };
    await cleanup();

    // ── FINALIZE ────────────────────────────────
    fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(REPORT, null, 2));
    const fails = REPORT.steps.filter(s => s.ok === false).length;
    const passes = REPORT.steps.filter(s => s.ok === true).length;
    console.log(`\n=== JOURNEY FINAL ===`);
    console.log(`Branch: ${BRANCH}`);
    console.log(`Steps: ${REPORT.steps.length}  |  OK: ${passes}  |  FAIL: ${fails}`);
    console.log(`Output: ${OUT}`);
    console.log(`Report: ${path.join(OUT, "report.json")}`);

    await page.close();
});
