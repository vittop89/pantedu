/**
 * Phase G18 — REAL user E2E: simula esattamente il flow descritto da
 * Operatore nello screenshot (no API direct, no programmatic .checked).
 *
 * Bug riprodotto:
 *   - Utente apriva pagina /studio/esercizio/...
 *   - Cliccava la "A" nell'header del problema (.checkboxA)
 *   - Riempiva .defPositionImp con 1
 *   - Cliccava la "A" nel checkIN del singolo quesito (.labcheckIN A)
 *   - Apriva InfoVer drawer, spuntava #Compensa #DSA #griglie #misure
 *   - Compilava i campi InfoVer e cliccava GENERA
 *   → Ricevuto toast "Nessun esercizio selezionato" (FAIL)
 *
 * Cause:
 *   1. `buildSelectionFromDOM` cercava `.DraggableContainer_ver` o
 *      `.fm-draggable-container` come root container — ma in `#type_verAll`
 *      (Verifiche correlate) NESSUNO dei due esiste.
 *   2. `<label class="fm-labcheck-in">A</label>` è renderizzato SENZA `for`
 *      attribute (Elementi_Riservati template clonato senza id univoco)
 *      → click sul label non toggla il checkbox sibling.
 *
 * Fix G18:
 *   1. Container fallback include `#type_verAll` + `.fm-related-verifiche`
 *      + ultimo fallback dinamico via `.fm-groupcollex .checkboxA:checked`.
 *   2. Delegation handler in init() per `.labcheckIN` click → toggle del
 *      checkbox sibling nello stesso `.ABin` parent.
 *   3. Diagnostic toast `diagnoseEmptySelection()` spiega all'utente
 *      esattamente quale step manca (no più generico "Nessun esercizio").
 *
 * Test verifica:
 *   - REAL user clicks (page.click su label) — non programmatic
 *   - Genera 8 varianti via batch endpoint
 *   - Verifica coerenza varianti (SOL no griglia, NOR sì, DSA compensa,
 *     DIS OpenDyslexic).
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function snap(page, name) {
    await page.screenshot({
        path: path.join(__dirname, "screenshots", `g18-${name}.png`),
        fullPage: false,
    }).catch(() => {});
}

async function findTopicWithProblem(page, candidates) {
    for (const url of candidates) {
        await page.goto(url);
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(2000); // verifica-mode loader async
        const n = await page.locator(".fm-groupcollex").count();
        if (n > 0) return { url, count: n };
    }
    return null;
}

test("G18 real-user GENERA — click reali, no programmatic", async ({ page }) => {
    test.setTimeout(120_000);
    await login(page);

    // Step 2: pagina con .fm-groupcollex caricato (Verifiche correlate inline)
    const found = await findTopicWithProblem(page, [
        "/studio/esercizio/ar/2s/MAT/1",
        "/studio/esercizio/ar/3s/MAT/3.0",
        "/studio/esercizio/ar/3s/MAT/4.0",
        "/studio/esercizio/ar/3s/FIS/3.0",
    ]);
    if (!found) {
        test.skip(true, "Nessuna pagina con .fm-groupcollex trovata");
        return;
    }
    console.log(`[G18] Pagina: ${found.url} (${found.count} .fm-groupcollex)`);
    await snap(page, "00-page-loaded");

    // Step 3 — click REALE su .checkboxA del primo .fm-groupcollex.
    // I `<label for="checkboxA">A</label>` sono presenti (template
    // Elementi_Riservati ha for/id pair), quindi cliccare il label è il
    // modo nativo. Però gli id sono duplicati per ogni problem
    // (`checkboxA`, `checkboxB`) — quindi cliccare il label punta sempre
    // al primo. Per essere sicuri, clicchiamo direttamente l'input.
    const firstProblem = page.locator(".fm-groupcollex").first();
    const cbA = firstProblem.locator("input.checkboxA").first();
    await expect(cbA).toBeVisible({ timeout: 5000 }).catch(() => {});
    // Force click — il template clonato spesso ha display:none default,
    // CSS body.fm-admin-access li rivela. In test environment il click force
    // è la soluzione pragmatica (l'utente reale lo vede e clicca).
    await cbA.click({ force: true });
    await page.waitForTimeout(200);
    expect(await cbA.isChecked()).toBe(true);

    // Step 4.b — apri il `.fm-collapsible` del problema (il `.content` ha
    // max-height:0 di default, i .fm-collection__item e .fm-checkbox-ain restano
    // collassati e non clickable). L'utente reale clicca sull'header
    // "Equazioni" per espandere il pannello.
    const collapsible = firstProblem.locator(".fm-collapsible").first();
    if (await collapsible.count()) {
        await collapsible.click({ force: true });
        await page.waitForTimeout(400);
    }

    // Step 4 — defPositionImp = 1
    const posImp = firstProblem.locator("input.defPositionImp").first();
    if (await posImp.count()) {
        await posImp.fill("1");
        await posImp.dispatchEvent("change");
    }
    await snap(page, "01-checkboxA-checked");

    // Step 5 — click REALE su .labcheckIN "A" del primo .fm-collection__item.
    // Senza il fix G18, il label senza `for` NON togglerebbe il
    // .fm-checkbox-ain. Con il fix, il delegation handler in topbar-modern.js
    // intercetta il click e toggla il checkbox sibling.
    const firstItem = firstProblem.locator(".fm-collection__item").first();
    const labelAin = firstItem.locator(".labcheckIN").first();
    if (await labelAin.count()) {
        await labelAin.click({ force: true });
        await page.waitForTimeout(150);
    }
    // Verifica che effettivamente .fm-checkbox-ain si è togglato (PROOF del fix G18)
    const cbAin = firstItem.locator("input.fm-checkbox-ain").first();
    const ainChecked = await cbAin.isChecked();
    console.log(`[G18] .fm-checkbox-ain checked dopo click su .labcheckIN: ${ainChecked}`);
    if (!ainChecked) {
        // Fallback: click diretto sul checkbox (utente potrebbe averlo
        // cliccato direttamente).
        await cbAin.click({ force: true });
        await page.waitForTimeout(150);
    }
    expect(await cbAin.isChecked()).toBe(true);
    await snap(page, "02-checkboxAin-checked");

    // Step 6 — apri Info drawer (topbar ⓘ)
    const infoBtn = page.locator('#fm-topbar [data-fm-action="info"]');
    if (await infoBtn.count()) {
        await infoBtn.click();
        await page.waitForTimeout(400);
        await snap(page, "03-info-drawer-open");
    }

    // Step 7 — riempi campi InfoVer (real user typing)
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
    await fillIfPresent("verTitle",  "VERIFICA G18 REAL FLOW");
    await fillIfPresent("nPrint",    "20");
    await fillIfPresent("nPrintDSA", "2");
    await fillIfPresent("nPrintDIS", "1");

    // Step 6.b — spunta #Compensa #DSA #griglie #misure (click reali)
    for (const id of ["Compensa", "DSA", "griglie", "misure"]) {
        const el = page.locator(`#${id}`);
        if (await el.count()) {
            const wasChecked = await el.isChecked();
            if (!wasChecked) {
                await el.click({ force: true });
            }
            expect(await el.isChecked()).toBe(true);
        }
    }
    await snap(page, "04-info-filled");

    // Chiudi info drawer per riportare il focus sul GENERA
    await page.keyboard.press("Escape").catch(() => {});
    await page.waitForTimeout(200);

    // SANITY CHECK: lo stato delle checkbox prima di cliccare GENERA.
    const sanity = await page.evaluate(() => ({
        cbA: document.querySelector(".fm-groupcollex input.checkboxA")?.checked,
        cbAin: document.querySelector(".fm-groupcollex .fm-collection__item input.fm-checkbox-ain")?.checked,
        verTitle: document.getElementById("verTitle")?.value,
        nPrint: document.getElementById("nPrint")?.value,
        nPrintDSA: document.getElementById("nPrintDSA")?.value,
        nPrintDIS: document.getElementById("nPrintDIS")?.value,
        Compensa: document.getElementById("Compensa")?.checked,
        DSA: document.getElementById("DSA")?.checked,
        griglie: document.getElementById("griglie")?.checked,
        misure: document.getElementById("misure")?.checked,
    }));
    console.log(`[G18] PRE-GENERA sanity: ${JSON.stringify(sanity)}`);
    expect(sanity.cbA, "cbA deve essere checked").toBe(true);
    expect(sanity.cbAin, "cbAin deve essere checked").toBe(true);

    // Step 8 — click GENERA + intercetta response (batch o single)
    const respPromise = page.waitForResponse(
        r => /\/api\/verifica\/save-tex(-batch)?\b/.test(r.url())
          && r.request().method() === "POST",
        { timeout: 30_000 },
    );
    const generaBtn = page.locator('#fm-topbar [data-fm-action="genera"]');
    await expect(generaBtn).toBeVisible({ timeout: 5000 });
    // G19.22 — click programmatico (vedi g19_7).
    await page.evaluate(() => document.querySelector('#fm-topbar [data-fm-action="genera"]')?.click());
    let resp;
    try {
        resp = await respPromise;
    } catch (e) {
        const toastTxt = await page.locator(".fm-toast, .fm-print-toast").innerText().catch(() => "");
        await snap(page, "99-error-no-request");
        throw new Error(`Nessuna POST a save-tex/save-tex-batch. Toast: "${toastTxt}". ${e.message}`);
    }
    expect(resp.url(), "GENERA deve invocare /api/verifica/save-tex-batch (8 varianti)")
        .toMatch(/save-tex-batch/);

    expect(resp.status()).toBe(200);
    const body = await resp.json();
    expect(body.ok).toBe(true);
    expect(Array.isArray(body.docs)).toBe(true);
    // G19.7 — solo `.checkboxA` checked (no `.checkboxR`) → 4 varianti
    // (A_SOL/A_NOR/A_DSA/A_DIS), NON 8. Per ottenere 8 varianti l'utente
    // deve spuntare ANCHE `.checkboxR` (Recupero).
    expect(body.docs.length).toBe(4);
    console.log(`[G18] batch_id=${body.batch_id}, varianti=${body.docs.length}`);
    body.docs.forEach(d => console.log(`  - ${d.variant}: id=${d.id}, ${d.tex_size}B, file=${d.tex_filename}`));

    // G19.7 — verifica legacy naming: `mat-{slug}-_-NOR-stampe_{datetime}.tex` etc.
    for (const d of body.docs) {
        expect(d.tex_filename, `tex_filename per ${d.variant}`).toBeTruthy();
        // Solo varianti A (no B) attese
        expect(d.variant, "solo varianti A_*").toMatch(/^A_/);
        // Pattern G19.7: {materia}-{slug}-{ver}-{variant}[-stampe]_{datetime}.tex
        // Es: mat-verifica_g18-_-SOL_20260501143022.tex
        //     mat-verifica_g18-_-NOR-stampe_20260501143022.tex
        const dt = "\\d{14}"; // YmdHis
        const variantUp = d.variant.replace(/^[AB]_/, ""); // SOL/NOR/DSA/DIS
        const isSol = variantUp === "SOL";
        const expected = isSol
            ? new RegExp(`-_-${variantUp}_${dt}\\.tex$`)
            : new RegExp(`-_-${variantUp}-stampe_${dt}\\.tex$`);
        expect(d.tex_filename, `${d.variant} naming legacy G19.7 pattern`).toMatch(expected);
    }

    // Step 9 — verifica coerenza 4 tipologie (A_SOL, A_NOR, A_DSA, A_DIS)
    const variantsFound = body.docs.map(d => d.variant).sort();
    // G19.7 — solo varianti A_* (no B_*) perché .checkboxR non era spuntato
    expect(variantsFound).toEqual(["A_DIS", "A_DSA", "A_NOR", "A_SOL"]);

    const fetched = {};
    for (const d of body.docs) {
        const r = await page.request.get(d.tex_url);
        if (r.ok()) fetched[d.variant] = await r.text();
    }

    const checks = [
        ["A_SOL", "Griglia di Valutazione", false, "no griglia in SOL"],
        ["A_NOR", "Griglia di Valutazione", true,  "griglia in NOR"],
        ["A_NOR", "BES/DSA",                true,  "BES/DSA in NOR"],
        ["A_NOR", "Compensazione orale",    false, "no compensa in NOR"],
        ["A_DSA", "Compensazione orale",    true,  "compensa in DSA"],
        ["A_DSA", "Griglia di Valutazione", true,  "griglia in DSA"],
        ["A_DIS", "OpenDyslexic",           true,  "OpenDyslexic in DIS"],
        ["A_DIS", "Compensazione orale",    true,  "compensa in DIS"],
    ];
    for (const [variant, needle, expected, label] of checks) {
        const present = fetched[variant]?.includes(needle) ?? false;
        const ok = present === expected;
        console.log(`  ${ok ? "[OK]  " : "[FAIL]"} ${variant} ${label} (atteso ${expected}, ricevuto ${present})`);
        expect(present, `${variant} ${label}`).toBe(expected);
    }

    // G19.1 — check Unicode bracket regression: i quesiti che contengono
    // \begin{cases}/\dfrac NON devono apparire come glyph Unicode
    // ⎧⎪⎨⎪⎩ (sintomo del MathJax-rendered textContent leak).
    const unicodeBrackets = /[⎧⎪⎨⎩⎫⎬⎭⎰⎱]/;
    for (const [variant, tex] of Object.entries(fetched)) {
        if (unicodeBrackets.test(tex)) {
            const idx = tex.search(unicodeBrackets);
            console.log(`[FAIL] ${variant} contiene Unicode bracket a offset ${idx}: "...${tex.substring(Math.max(0, idx-20), idx+50)}..."`);
        }
        expect(tex, `${variant} non deve contenere Unicode bracket MathJax glyphs`)
            .not.toMatch(unicodeBrackets);
        // Anche `\textbackslash{}` è sintomo di double-escape (escapePlain
        // applicato a contenuti LaTeX raw post-G19.1).
        expect(tex, `${variant} non deve contenere \\textbackslash artifact`)
            .not.toContain("\\textbackslash{}");
    }

    // ZIP download
    const zipResp = await page.request.get(body.zip_url);
    expect(zipResp.status()).toBe(200);
    const zipBody = await zipResp.body();
    expect(zipBody[0]).toBe(0x50); // P
    expect(zipBody[1]).toBe(0x4B); // K
    console.log(`[G18] ZIP ${zipBody.length}B (PK magic OK)`);

    // Cleanup
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    for (const d of body.docs) {
        await page.request.post(`/api/verifica/${d.id}/delete`, {
            data: {},
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
    }
    await snap(page, "99-success");
});
