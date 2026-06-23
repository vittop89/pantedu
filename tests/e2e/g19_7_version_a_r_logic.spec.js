/**
 * Phase G19.7 — Version A / R / both: contract dell'utente.
 *
 * User explicit:
 *   "il fatto di avere la versione A o la versione B o entrambe come file
 *    dipende dal fatto di aver spuntato o meno i class="checkboxA" o/e
 *    i class="checkboxB" (se non ci sono class="checkboxA" spuntati,
 *    i file prodotti devono essere solo di tipo B"
 *
 * Tre scenari testati:
 *   1. Solo `.checkboxA` checked  → 4 varianti A_* (A_SOL/A_NOR/A_DSA/A_DIS)
 *   2. Solo `.checkboxR` checked  → 4 varianti B_* (B_SOL/B_NOR/B_DSA/B_DIS)
 *   3. Entrambi A + R checked     → 8 varianti A/B × {SOL,NOR,DSA,DIS}
 *
 * Inoltre verifica naming legacy G19.7:
 *   `{materia}-{slug}-{ver}-{variant}[-stampe]_{datetime}.tex`
 *   - ver token: `_` per A, `rec` per B (Recupero)
 */
const { test, expect } = require("@playwright/test");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await page.click('button[type="submit"]');
    await page.waitForFunction(() => !location.pathname.startsWith("/login"), { timeout: 15_000 });
    await page.waitForLoadState("domcontentloaded");
}

async function setupSelection(page, { checkA, checkR, expandCollapsible = true }) {
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);
    const firstProblem = page.locator(".fm-groupcollex").first();
    if (checkA) {
        await firstProblem.locator("input.checkboxA").first().click({ force: true });
    }
    if (checkR) {
        // .checkboxR è alias moderno di .checkboxB. Click su entrambi i selettori.
        const r = firstProblem.locator("input.checkboxR, input.checkboxB").first();
        await r.click({ force: true });
    }
    if (expandCollapsible) {
        const collapsible = firstProblem.locator(".fm-collapsible").first();
        if (await collapsible.count()) await collapsible.click({ force: true });
        await page.waitForTimeout(300);
    }
    // Spunta .fm-checkbox-ain/.checkboxRin del primo .fm-collection__item per rispettare il filtro item-level
    const labelsAR = firstProblem.locator(".fm-collection__item .labcheckIN");
    const lblCount = await labelsAR.count();
    // Tipicamente 2 labels per item (A + R); cliccare quelli appropriati
    if (lblCount >= 1 && checkA) await labelsAR.nth(0).click({ force: true });
    if (lblCount >= 2 && checkR) await labelsAR.nth(1).click({ force: true });
    await page.waitForTimeout(150);
}

async function fillInfoVer(page, opts = {}) {
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    const fill = async (id, val) => {
        const el = page.locator(`#${id}`);
        if (await el.count()) {
            await el.fill(val);
            await el.dispatchEvent("change");
        }
    };
    await fill("verTitle",  opts.title || "VERIFICA G19.7 TEST");
    await fill("anno",      "2025-26");
    await fill("sezione",   "B");
    await fill("nPrint",    String(opts.nPrint    ?? "10"));
    await fill("nPrintDSA", String(opts.nPrintDSA ?? "0"));
    await fill("nPrintDIS", String(opts.nPrintDIS ?? "0"));
    if (opts.dsa) {
        const el = page.locator("#DSA");
        if ((await el.count()) && !(await el.isChecked())) await el.click({ force: true });
    }
    await page.keyboard.press("Escape").catch(() => {});
    await page.waitForTimeout(200);
}

async function clickGeneraAndGetBatch(page) {
    // Debug: stato pre-genera per capire cosa il client manderà al server
    const debug = await page.evaluate(() => ({
        cbA_checked: !!document.querySelector(".fm-groupcollex input.checkboxA")?.checked,
        cbR_checked: !!document.querySelector(".fm-groupcollex input.checkboxR, .fm-groupcollex input.checkboxB")?.checked,
        cbAin_count: document.querySelectorAll(".fm-groupcollex input.fm-checkbox-ain:checked").length,
        cbRin_count: document.querySelectorAll(".fm-groupcollex input.fm-checkbox-rin:checked, .fm-groupcollex input.fm-checkbox-bin:checked").length,
        collexItems: document.querySelectorAll(".fm-groupcollex .fm-collection__item").length,
    }));
    console.log("[G19.7 debug pre-genera]", JSON.stringify(debug));
    const respPromise = page.waitForResponse(
        r => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 30_000 },
    );
    // G19.22 — click programmatico (Playwright force-click su elementi rilocati
    // in topbar a volte non innesca il click event reale; eval garantisce dispatch).
    await page.evaluate(() => document.querySelector('#fm-topbar [data-fm-action="genera"]')?.click());
    const resp = await respPromise;
    if (resp.status() !== 200) {
        const errBody = await resp.text();
        console.log(`[G19.7 ERROR ${resp.status()}]`, errBody.substring(0, 500));
    }
    expect(resp.status()).toBe(200);
    expect(resp.url()).toMatch(/save-tex-batch/);
    return await resp.json();
}

async function cleanup(page, docs) {
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    for (const d of docs) {
        await page.request.post(`/api/verifica/${d.id}/delete`, {
            data: {},
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
    }
}

test("G19.7 — solo .checkboxA → 4 varianti A_* (no B/R)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await setupSelection(page, { checkA: true, checkR: false });
    // 4 varianti per side richiede DSA flag + nPrintDSA + nPrintDIS > 0.
    await fillInfoVer(page, {
        title: "G19.7 ONLY A",
        nPrint: "10", nPrintDSA: "2", nPrintDIS: "1", dsa: true,
    });
    const body = await clickGeneraAndGetBatch(page);
    expect(body.ok).toBe(true);
    expect(body.docs.length, "solo A → 4 varianti").toBe(4);
    const variants = body.docs.map(d => d.variant).sort();
    expect(variants).toEqual(["A_DIS", "A_DSA", "A_NOR", "A_SOL"]);
    // Filename pattern G19.7: ver token = `_` per A
    for (const d of body.docs) {
        expect(d.tex_filename, `${d.variant}: token \`_\``).toMatch(/-_-(SOL|NOR|DSA|DIS)/);
        expect(d.tex_filename).toMatch(/_\d{14}\.tex$/);  // datetime YmdHis
    }
    await cleanup(page, body.docs);
});

test("G19.7 — solo .checkboxR → 4 varianti B_* (Recupero)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await setupSelection(page, { checkA: false, checkR: true });
    await fillInfoVer(page, {
        title: "G19.7 ONLY R",
        nPrint: "5", nPrintDSA: "2", nPrintDIS: "1", dsa: true,
    });
    const body = await clickGeneraAndGetBatch(page);
    expect(body.ok).toBe(true);
    expect(body.docs.length, "solo R → 4 varianti").toBe(4);
    const variants = body.docs.map(d => d.variant).sort();
    expect(variants).toEqual(["B_DIS", "B_DSA", "B_NOR", "B_SOL"]);
    // Filename pattern G19.7: ver token = `rec` per B
    for (const d of body.docs) {
        expect(d.tex_filename, `${d.variant}: token \`rec\``).toMatch(/-rec-(SOL|NOR|DSA|DIS)/);
        expect(d.tex_filename).toMatch(/_\d{14}\.tex$/);
    }
    await cleanup(page, body.docs);
});

test("G19.7 — entrambi A + R → 8 varianti A/B × {SOL,NOR,DSA,DIS}", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await setupSelection(page, { checkA: true, checkR: true });
    await fillInfoVer(page, { title: "G19.7 BOTH AR", nPrint: "20", nPrintDSA: "2", nPrintDIS: "1", dsa: true });
    const body = await clickGeneraAndGetBatch(page);
    expect(body.ok).toBe(true);
    expect(body.docs.length, "A+R → 8 varianti").toBe(8);
    const variants = body.docs.map(d => d.variant).sort();
    expect(variants).toEqual([
        "A_DIS", "A_DSA", "A_NOR", "A_SOL",
        "B_DIS", "B_DSA", "B_NOR", "B_SOL",
    ]);
    // Naming legacy G19.7: A → `_`, B → `rec`
    const aFiles = body.docs.filter(d => /^A_/.test(d.variant));
    const bFiles = body.docs.filter(d => /^B_/.test(d.variant));
    aFiles.forEach(d => expect(d.tex_filename).toMatch(/-_-/));
    bFiles.forEach(d => expect(d.tex_filename).toMatch(/-rec-/));
    await cleanup(page, body.docs);
});
