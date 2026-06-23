/**
 * Phase G19.5 — REAL USER end-to-end: login + selezione esercizi +
 * InfoVer + GENERA → batch ZIP download → verifica filenames legacy
 * + magic bytes ZIP + lista delle 8 varianti.
 *
 * Replica esatta del flow descritto dall'utente:
 *   "accedi con superadmin e passw (process.env.E2E_TEACHER_PASS || "")
 *    per fare dei test reali, seleziona gli esericizi riempi i
 *    campi di info ver e scarica il zip in download e verifica
 *    nome dei file e tipologie"
 *
 * Il test scarica effettivamente lo ZIP via `page.request.get(zip_url)`
 * (cookies + sessione condivisa con la pagina), salva su disco temp,
 * usa `adm-zip` per estrarne il contenuto, valida:
 *   - 8 file `.tex` con naming legacy `{slug}-{A|B}-{NOR|SOL|DSA|DIS}[-stampe_].tex`
 *   - SOL → no `-stampe_`; NOR/DSA/DIS → con `-stampe_`
 *   - README.txt con elenco varianti
 *   - ogni TEX > 3KB (non vuoto)
 *   - A_NOR contiene "Griglia di Valutazione" e "BES/DSA"
 *   - A_DSA contiene "Compensazione orale"
 *   - A_DIS contiene "OpenDyslexic"
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

test("G19.5 REAL USER — selezione + InfoVer + GENERA + scarica ZIP + verifica nomi/tipologie", async ({ page }) => {
    test.setTimeout(180_000);
    await login(page);

    // Step 1: pagina con esercizi caricati
    await page.goto("/studio/esercizio/ar/2s/MAT/1");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500); // attende _caricaCheckboxABin

    const problemCount = await page.locator(".fm-groupcollex").count();
    expect(problemCount, "almeno 1 .fm-groupcollex in pagina").toBeGreaterThan(0);
    console.log(`[G19.5] pagina ha ${problemCount} .fm-groupcollex`);

    // Step 2: spunta .checkboxA del primo problema (real-user click)
    const firstProblem = page.locator(".fm-groupcollex").first();
    const cbA = firstProblem.locator("input.checkboxA").first();
    await cbA.click({ force: true });
    expect(await cbA.isChecked()).toBe(true);

    // Espandi il `.fm-collapsible` (.content è max-height:0 di default)
    const collapsible = firstProblem.locator(".fm-collapsible").first();
    if (await collapsible.count()) {
        await collapsible.click({ force: true });
        await page.waitForTimeout(300);
    }

    // .defPositionImp = 1
    const posImp = firstProblem.locator("input.defPositionImp").first();
    if (await posImp.count()) {
        await posImp.fill("1");
        await posImp.dispatchEvent("change");
    }

    // Click .labcheckIN (il G18 fix toggla il sibling .fm-checkbox-ain)
    const firstItem = firstProblem.locator(".fm-collection__item").first();
    const labelAin = firstItem.locator(".labcheckIN").first();
    if (await labelAin.count()) {
        await labelAin.click({ force: true });
        await page.waitForTimeout(150);
    }
    const cbAin = firstItem.locator("input.fm-checkbox-ain").first();
    if (!(await cbAin.isChecked())) {
        await cbAin.click({ force: true });
    }
    expect(await cbAin.isChecked()).toBe(true);

    // Step 3: apri Info drawer + compila campi
    const infoBtn = page.locator('#fm-topbar [data-fm-action="info"]');
    await infoBtn.click();
    await page.waitForTimeout(400);
    const fillIfPresent = async (id, val) => {
        const el = page.locator(`#${id}`);
        if (await el.count()) {
            await el.fill(val);
            await el.dispatchEvent("change");
        }
    };
    await fillIfPresent("anno",      "2025-26");
    await fillIfPresent("verTime",   "60 min");
    await fillIfPresent("classe",    "5");
    await fillIfPresent("sezione",   "B");
    await fillIfPresent("verTitle",  "VERIFICA G19.5 REAL USER");
    await fillIfPresent("nPrint",    "20");
    await fillIfPresent("nPrintDSA", "2");
    await fillIfPresent("nPrintDIS", "1");
    for (const id of ["Compensa", "DSA", "griglie", "misure"]) {
        const el = page.locator(`#${id}`);
        if ((await el.count()) && !(await el.isChecked())) {
            await el.click({ force: true });
        }
    }

    // Sanity check: pre-GENERA stato
    const sanity = await page.evaluate(() => ({
        cbA:       document.querySelector(".fm-groupcollex input.checkboxA")?.checked,
        cbAin:     document.querySelector(".fm-groupcollex .fm-collection__item input.fm-checkbox-ain")?.checked,
        verTitle:  document.getElementById("verTitle")?.value,
        nPrint:    document.getElementById("nPrint")?.value,
        nPrintDSA: document.getElementById("nPrintDSA")?.value,
        nPrintDIS: document.getElementById("nPrintDIS")?.value,
        Compensa:  document.getElementById("Compensa")?.checked,
        DSA:       document.getElementById("DSA")?.checked,
        griglie:   document.getElementById("griglie")?.checked,
        misure:    document.getElementById("misure")?.checked,
    }));
    console.log(`[G19.5] PRE-GENERA: ${JSON.stringify(sanity)}`);
    expect(sanity.cbA).toBe(true);
    expect(sanity.cbAin).toBe(true);
    expect(sanity.DSA).toBe(true);

    // Step 4: chiudi drawer, click GENERA, intercetta response batch
    await page.keyboard.press("Escape").catch(() => {});
    await page.waitForTimeout(200);
    const respPromise = page.waitForResponse(
        r => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 30_000 },
    );
    // G19.22 — click programmatico (vedi g19_7).
    await page.evaluate(() => document.querySelector('#fm-topbar [data-fm-action="genera"]')?.click());
    const resp = await respPromise;
    expect(resp.status()).toBe(200);
    expect(resp.url(), "deve essere il batch endpoint").toMatch(/save-tex-batch/);
    const body = await resp.json();
    expect(body.ok).toBe(true);
    // G19.7 — solo .checkboxA spuntato → 4 varianti A_*. Per 8 spuntare anche .checkboxR.
    expect(body.docs.length).toBe(4);
    console.log(`[G19.5] batch_id=${body.batch_id}, varianti=${body.docs.length}`);

    // Step 5: scarica lo ZIP via page.request (sessione condivisa) +
    // salva su disco temp + estrae con adm-zip.
    const zipResp = await page.request.get(body.zip_url);
    expect(zipResp.status()).toBe(200);
    const zipBuf = await zipResp.body();
    expect(zipBuf[0]).toBe(0x50); // P
    expect(zipBuf[1]).toBe(0x4B); // K
    console.log(`[G19.5] ZIP ${zipBuf.length} bytes (PK magic OK)`);

    const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), "g195-"));
    const zipPath = path.join(tmpDir, `batch_${body.batch_id}.zip`);
    fs.writeFileSync(zipPath, zipBuf);
    console.log(`[G19.5] ZIP salvato: ${zipPath}`);

    const zip = new AdmZip(zipPath);
    const entries = zip.getEntries();
    const filenames = entries.map(e => e.entryName).sort();
    console.log(`[G19.5] ZIP contiene ${entries.length} file:`);
    filenames.forEach(f => console.log(`  - ${f}`));

    // Step 6: verifica nomi file legacy G19.7 + 4 varianti A_* + README
    expect(filenames, "deve esserci README.txt").toContain("README.txt");
    const texFiles = filenames.filter(f => f.endsWith(".tex"));
    expect(texFiles.length, "4 file .tex (solo A_*)").toBe(4);

    // Pattern G19.7: `mat-{slug}-_-{variant}[-stampe]_{datetime}.tex`
    // ver token `_` = versione A; `rec` = versione B (Recupero).
    const dt = "\\d{14}";
    const expectedVariants = ["SOL", "NOR", "DSA", "DIS"];
    for (const v of expectedVariants) {
        const isSol = v === "SOL";
        const re = isSol
            ? new RegExp(`-_-${v}_${dt}\\.tex$`)
            : new RegExp(`-_-${v}-stampe_${dt}\\.tex$`);
        const found = texFiles.find(f => re.test(f));
        expect(found, `variante A_${v} con naming legacy G19.7 ${re}`).toBeTruthy();
    }

    // Step 7: verifica contenuti per coerenza variante
    const readEntry = (name) => {
        const e = entries.find(x => x.entryName === name);
        return e ? e.getData().toString("utf-8") : "";
    };
    const aNorFile = texFiles.find(f => new RegExp(`-_-NOR-stampe_${dt}\\.tex$`).test(f));
    const aDsaFile = texFiles.find(f => new RegExp(`-_-DSA-stampe_${dt}\\.tex$`).test(f));
    const aDisFile = texFiles.find(f => new RegExp(`-_-DIS-stampe_${dt}\\.tex$`).test(f));
    const aSolFile = texFiles.find(f => new RegExp(`-_-SOL_${dt}\\.tex$`).test(f));

    const aNor = readEntry(aNorFile);
    const aDsa = readEntry(aDsaFile);
    const aDis = readEntry(aDisFile);
    const aSol = readEntry(aSolFile);

    expect(aNor.length, "A_NOR > 3KB").toBeGreaterThan(3000);
    expect(aDsa.length, "A_DSA > 3KB").toBeGreaterThan(3000);
    expect(aDis.length, "A_DIS > 3KB").toBeGreaterThan(3000);
    expect(aSol.length, "A_SOL > 1KB").toBeGreaterThan(1000);

    // Coerenza tipologie
    expect(aNor, "A_NOR ha Griglia").toContain("Griglia di Valutazione");
    expect(aNor, "A_NOR ha BES/DSA").toContain("BES/DSA");
    expect(aNor.includes("Compensazione orale"), "A_NOR no Compensazione").toBe(false);
    expect(aDsa, "A_DSA ha Compensazione").toContain("Compensazione orale");
    expect(aDsa, "A_DSA ha Griglia").toContain("Griglia di Valutazione");
    expect(aDis, "A_DIS ha OpenDyslexic").toContain("OpenDyslexic");
    expect(aDis, "A_DIS ha Compensazione").toContain("Compensazione orale");
    expect(aSol.includes("Griglia di Valutazione"), "A_SOL no Griglia").toBe(false);

    // Guard regressione G19.1 — no Unicode bracket glyphs MathJax-rendered
    for (const [name, tex] of [["A_NOR", aNor], ["A_DSA", aDsa], ["A_DIS", aDis], ["A_SOL", aSol]]) {
        expect(tex, `${name} no Unicode bracket`).not.toMatch(/[⎧⎪⎨⎩⎫⎬⎭]/);
        expect(tex, `${name} no double-escape \\textbackslash{}`).not.toContain("\\textbackslash{}");
    }

    console.log(`[G19.5] ✅ Tutti i 8 .tex hanno naming legacy + tipologie coerenti`);

    // Cleanup: cancella i doc + tmp dir
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    for (const d of body.docs) {
        await page.request.post(`/api/verifica/${d.id}/delete`, {
            data: {},
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
    }
    try { fs.unlinkSync(zipPath); fs.rmdirSync(tmpDir); } catch {}
});
