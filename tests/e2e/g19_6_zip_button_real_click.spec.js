/**
 * Phase G19.6 — REAL CLICK del topbar ZIP button (no API direct).
 *
 *  Bug riportato dall'utente: dopo aver fatto la selezione + InfoVer
 *  + click sul pulsante ZIP della topbar, il file scaricato in
 *  `/Downloads/verifica_138.zip` conteneva SOLO 2 entries:
 *    - `verifica_138.tex`  (single-doc id-only naming)
 *    - `README.txt`
 *
 *  Atteso: 9 entries (8 .tex con naming legacy `-{A|B}-{NOR|SOL|DSA|DIS}.tex`
 *  + README) come risultato del batch ZIP.
 *
 *  Causa: `doZip()` in topbar-modern.js chiamava sempre `/api/verifica/save-tex`
 *  (single mode) invece di `/api/verifica/save-tex-batch` quando le print
 *  counts > 0. G19.6 fix: stessa logica di `doGenera` — auto-detect batch
 *  da `nPrint + nPrintDSA + nPrintDIS > 0`.
 *
 *  Questo test esegue il flow ESATTO dell'utente:
 *    1. Login
 *    2. Naviga a pagina con esercizi
 *    3. Click `.checkboxA`, espandi `.fm-collapsible`, click `.labcheckIN`
 *    4. Apri Info drawer + fill `nPrint=20`, `nPrintDSA=2`, `nPrintDIS=1`,
 *       Compensa+DSA+griglie+misure checked
 *    5. Click su `[data-fm-action="zip"]` (TOPBAR ZIP BUTTON, non GENERA)
 *    6. Intercetta:
 *       - POST `/api/verifica/save-tex-batch` (NON save-tex single)
 *       - GET  `/api/verifica/batch/{id}/zip` (NON /api/verifica/{id}/zip)
 *    7. Salva ZIP, estrae con adm-zip, valida 8 .tex + README
 */
const { test, expect } = require("@playwright/test");
const path = require("path");
const fs = require("fs");
const os = require("os");
const AdmZip = require("adm-zip");

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

test("G19.6 REAL CLICK ZIP topbar btn — batch mode auto-detect (8 file, NON 1)", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Selezione: checkboxA + collapsible + checkboxAin
    const firstProblem = page.locator(".fm-groupcollex").first();
    await firstProblem.locator("input.checkboxA").first().click({ force: true });
    const collapsible = firstProblem.locator(".fm-collapsible").first();
    if (await collapsible.count()) {
        await collapsible.click({ force: true });
        await page.waitForTimeout(300);
    }
    const labelAin = firstProblem.locator(".fm-collection__item .labcheckIN").first();
    await labelAin.click({ force: true });
    await page.waitForTimeout(150);
    const cbAin = firstProblem.locator(".fm-collection__item input.fm-checkbox-ain").first();
    if (!(await cbAin.isChecked())) await cbAin.click({ force: true });
    expect(await cbAin.isChecked()).toBe(true);

    // Apri Info drawer + fill counts (questi triggerano batch mode)
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    const fill = async (id, val) => {
        const el = page.locator(`#${id}`);
        if (await el.count()) {
            await el.fill(val);
            await el.dispatchEvent("change");
        }
    };
    await fill("verTitle",  "VERIFICA G19.6 ZIP TOPBAR");
    await fill("anno",      "2025-26");
    await fill("sezione",   "B");
    await fill("nPrint",    "20");
    await fill("nPrintDSA", "2");
    await fill("nPrintDIS", "1");
    for (const id of ["Compensa", "DSA", "griglie", "misure"]) {
        const el = page.locator(`#${id}`);
        if ((await el.count()) && !(await el.isChecked())) {
            await el.click({ force: true });
        }
    }

    // Sanity check
    const sanity = await page.evaluate(() => ({
        cbA: document.querySelector(".fm-groupcollex input.checkboxA")?.checked,
        cbAin: document.querySelector(".fm-groupcollex .fm-collection__item input.fm-checkbox-ain")?.checked,
        nPrint: document.getElementById("nPrint")?.value,
        nPrintDSA: document.getElementById("nPrintDSA")?.value,
        nPrintDIS: document.getElementById("nPrintDIS")?.value,
        DSA: document.getElementById("DSA")?.checked,
    }));
    console.log(`[G19.6] sanity: ${JSON.stringify(sanity)}`);
    expect(sanity.cbA && sanity.cbAin).toBe(true);
    expect(parseInt(sanity.nPrint) + parseInt(sanity.nPrintDSA) + parseInt(sanity.nPrintDIS))
        .toBeGreaterThan(0);

    // Chiudi drawer per esporre la topbar
    await page.keyboard.press("Escape").catch(() => {});
    await page.waitForTimeout(200);

    // Capture: save-tex-batch (POST) + page.on("download") quando l'anchor
    // `download` attribute triggera il save-as. Anchor click in Playwright
    // emette un Download event (non un Request normale), quindi usiamo
    // `page.waitForEvent("download")`.
    const savePromise = page.waitForResponse(
        r => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 30_000 },
    );
    const downloadPromise = page.waitForEvent("download", { timeout: 15_000 }).catch(() => null);

    // CLICK REALE sul TOPBAR ZIP BUTTON
    const zipBtn = page.locator('#fm-topbar [data-fm-action="zip"]');
    await expect(zipBtn).toBeVisible({ timeout: 5_000 });
    await zipBtn.click({ force: true });

    // 1. Verifica POST save: deve essere save-tex-BATCH
    const saveResp = await savePromise;
    expect(saveResp.status()).toBe(200);
    expect(saveResp.url(), "ZIP topbar btn deve invocare /save-tex-batch quando counts > 0")
        .toMatch(/save-tex-batch/);
    const saveBody = await saveResp.json();
    expect(saveBody.ok).toBe(true);
    // G19.7 — solo .checkboxA spuntato → 4 varianti A_*. Per 8 spuntare anche .checkboxR.
    expect(saveBody.docs.length).toBe(4);
    console.log(`[G19.6] save batch_id=${saveBody.batch_id}, docs=${saveBody.docs.length}`);

    // 2. Verifica download triggered con URL = batch endpoint (NON single).
    const download = await downloadPromise;
    expect(download, "download event triggered (anchor.click)").not.toBeNull();
    const downloadUrl = download.url();
    console.log(`[G19.6] download URL: ${downloadUrl}`);
    console.log(`[G19.6] download suggestedFilename: ${download.suggestedFilename()}`);
    expect(downloadUrl, "URL deve essere /api/verifica/batch/{id}/zip (NON /api/verifica/{id}/zip)")
        .toMatch(/\/api\/verifica\/batch\/[A-Z0-9]+\/zip$/);
    expect(download.suggestedFilename()).toMatch(/^batch_[A-Z0-9]+\.zip$/);

    // 3. Salva su disk + estrae per verifica content
    const tmpDir0 = fs.mkdtempSync(path.join(os.tmpdir(), "g196dl-"));
    const downloadPath = path.join(tmpDir0, download.suggestedFilename());
    await download.saveAs(downloadPath);
    const zipBuf = fs.readFileSync(downloadPath);
    const zip = new AdmZip(downloadPath);
    const entries = zip.getEntries();
    const filenames = entries.map(e => e.entryName).sort();
    console.log(`[G19.6] ZIP contiene ${entries.length} file:`);
    filenames.forEach(f => console.log(`  - ${f}`));

    // G19.7 — solo .checkboxA → 4 varianti A_* + README = 5 entries.
    // **Assertion principale**: 5 entries (4 .tex + README), NON 2 (single mode bug).
    expect(entries.length, `ZIP deve contenere 5 file (4 .tex + README), NON 2 (single mode bug)`)
        .toBe(5);
    expect(filenames).toContain("README.txt");
    const texFiles = filenames.filter(f => f.endsWith(".tex"));
    expect(texFiles.length, "4 file .tex (solo A_*)").toBe(4);

    // Naming legacy G19.7: `mat-{slug}-_-{variant}[-stampe]_{datetime}.tex`
    const dt = "\\d{14}";
    for (const v of ["SOL", "NOR", "DSA", "DIS"]) {
        const isSol = v === "SOL";
        const re = isSol
            ? new RegExp(`-_-${v}_${dt}\\.tex$`)
            : new RegExp(`-_-${v}-stampe_${dt}\\.tex$`);
        const found = texFiles.find(f => re.test(f));
        expect(found, `variante A_${v} con naming legacy G19.7`).toBeTruthy();
    }

    // Cleanup
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    for (const d of saveBody.docs) {
        await page.request.post(`/api/verifica/${d.id}/delete`, {
            data: {},
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
    }
    try { fs.unlinkSync(downloadPath); fs.rmdirSync(tmpDir0); } catch {}
});

test("G19.6 — shift+click sul ZIP btn forza single mode (escape hatch)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500);

    // Setup minimo selezione
    const firstProblem = page.locator(".fm-groupcollex").first();
    await firstProblem.locator("input.checkboxA").first().click({ force: true });
    const collapsible = firstProblem.locator(".fm-collapsible").first();
    if (await collapsible.count()) await collapsible.click({ force: true });
    await page.waitForTimeout(300);
    const labelAin = firstProblem.locator(".fm-collection__item .labcheckIN").first();
    await labelAin.click({ force: true });
    const cbAin = firstProblem.locator(".fm-collection__item input.fm-checkbox-ain").first();
    if (!(await cbAin.isChecked())) await cbAin.click({ force: true });

    // Apri Info drawer + fill counts > 0 (per provare che shift override)
    await page.locator('#fm-topbar [data-fm-action="info"]').click();
    await page.waitForTimeout(400);
    const fill = async (id, val) => {
        const el = page.locator(`#${id}`);
        if (await el.count()) await el.fill(val);
    };
    await fill("verTitle",  "VERIFICA G19.6 SHIFT");
    await fill("nPrint",    "10");
    await fill("nPrintDSA", "0");
    await fill("nPrintDIS", "0");
    await page.keyboard.press("Escape").catch(() => {});
    await page.waitForTimeout(200);

    const savePromise = page.waitForResponse(
        r => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 30_000 },
    );

    // shift+click sul btn ZIP forza single mode
    const zipBtn = page.locator('#fm-topbar [data-fm-action="zip"]');
    await zipBtn.click({ force: true, modifiers: ["Shift"] });
    const saveResp = await savePromise;
    // shift forza save-tex (single), no -batch
    expect(saveResp.url()).toMatch(/save-tex(?!-batch)/);
    const body = await saveResp.json();
    expect(body.ok).toBe(true);
    expect(body.doc).toBeTruthy();
    console.log(`[G19.6 shift] forced single mode → doc.id=${body.doc.id}`);

    // Cleanup
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    await page.request.post(`/api/verifica/${body.doc.id}/delete`, {
        data: {},
        headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
    });
});
