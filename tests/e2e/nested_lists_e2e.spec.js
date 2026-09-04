/**
 * E2E nested lists: <ol class="fm-dsa-li-list"> con dentro <ul class="fm-dsa-li-list">.
 * Verifica:
 *   - dom-block-extractor cattura SOLO outer (filter closest)
 *   - L'outerHTML include inner intatta
 *   - Sanitizer converte annidamento corretto: enumerate > itemize > \item
 *   - TEX finale ha balance \begin/\end coerente
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Liste annidate (ol > ul > li): TEX ha enumerate > itemize bilanciato", async ({ page }) => {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.locator("button[type=submit]").first().click(),
    ]);
    await page.goto("/?home=1", { waitUntil: "networkidle" });

    await page.evaluate(() => {
        const setSel = (id, val) => {
            let el = document.getElementById(id);
            if (!el) { el = document.createElement("select"); el.id = id; el.style.display = "none"; document.body.appendChild(el); }
            const opt = document.createElement("option"); opt.value = val; opt.selected = true;
            el.innerHTML = ""; el.appendChild(opt);
        };
        setSel("sel-iis", "sc"); setSel("sel-cls", "1"); setSel("sel-mater", "MAT");
        const t = document.createElement("input");
        t.id = "verTitle"; t.value = "TestNested"; t.style.display = "none";
        document.body.appendChild(t);

        const root = document.getElementById("fm-content") || document.body;
        root.querySelectorAll(".fm-groupcollex").forEach(p => p.remove());

        // Lista annidata: outer ordered → li con inner unordered
        const html = `
          <div class="fm-groupcollex" id="type_Collect_nested">
            <input type="checkbox" class="checkboxA" checked>
            <span class="fm-testo">Risolvi</span>
            <ol class="fm-collexercise">
              <li class="fm-collection__item" data-id="cx_nested">
                <input type="checkbox" class="fm-checkbox-ain" checked>
                <input type="number" class="fm-input-pt" value="2">
                <input type="checkbox" class="checksol" checked>
                <div class="fm-collection">
                  <span class="fm-text" data-raw="Considera i punti">Considera i punti</span>
                  <ol class="fm-dsa-li-list" data-dsa-section="question">
                    <li>
                      <span class="fm-text" data-raw="punto Q1 con sub-punti:">punto Q1 con sub-punti:</span>
                      <ul class="fm-dsa-li-list" data-dsa-section="question">
                        <li><span class="fm-dsa-li-content"><span class="fm-text" data-raw="alfa">alfa</span></span></li>
                        <li><span class="fm-dsa-li-content"><span class="fm-text" data-raw="beta">beta</span></span></li>
                      </ul>
                    </li>
                    <li><span class="fm-dsa-li-content"><span class="fm-text" data-raw="punto Q2">punto Q2</span></span></li>
                  </ol>
                </div>
                <div class="fm-sol">
                  <span class="fm-text" data-raw="Soluzione passi:">Soluzione passi:</span>
                  <ol class="fm-dsa-li-list" data-dsa-section="solution">
                    <li><span class="fm-text" data-raw="step S1">step S1</span></li>
                    <li><span class="fm-text" data-raw="step S2">step S2</span></li>
                  </ol>
                </div>
              </li>
            </ol>
          </div>`;
        const wrap = document.createElement("div");
        wrap.innerHTML = html;
        root.appendChild(wrap);
    });

    // Build payload via builder esposto
    const payload = await page.evaluate(() => window.FM?.__buildSelectionFromDOM_forTest?.());
    expect(payload, "builder ritorna payload").toBeTruthy();

    const concatHtml = (payload.problems || []).flatMap(p => (p.items || []).map(i => i.html || "")).join("\n");
    const concatSol = (payload.problems || []).flatMap(p => (p.items || []).map(i => i.solution || "")).join("\n");

    // Outer + inner ol entrambi inclusi nell'outerHTML del problem
    expect(concatHtml, "outer ol presente").toMatch(/<ol[^>]*class="fm-dsa-li-list"/);
    expect(concatHtml, "inner ul presente NESTED").toMatch(/<ul[^>]*class="fm-dsa-li-list"/);
    expect(concatHtml, "alfa nel sub-list").toContain("alfa");
    expect(concatHtml, "beta nel sub-list").toContain("beta");
    expect(concatHtml, "punto Q1").toContain("punto Q1");
    expect(concatHtml, "punto Q2").toContain("punto Q2");
    expect(concatSol, "solution lista catturata").toMatch(/<ol[^>]*data-dsa-section="solution"/);

    // POST → TEX
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);
    const apiResp = await page.request.post("/api/verifica/save-tex", {
        data: { ...payload, variant: "normal", title: payload.verTitle, materia: payload.selectedMATER, indirizzo: payload.selectedIIS, classe: payload.selectedCLS, options: { includeSolutions: true } },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf, "Accept": "application/json" },
        timeout: 60000,
    });
    expect(apiResp.status()).toBe(200);
    const docId = (await apiResp.json()).doc.id;

    const tex = await page.request.get(`/api/verifica/${docId}/tex`).then(r => r.text());
    fs.mkdirSync("tests/e2e-results", { recursive: true });
    fs.writeFileSync(path.join("tests/e2e-results", `nested_doc_${docId}.tex`), tex);

    // Asserzioni TEX nested
    const beginEnums = (tex.match(/\\begin\{enumerate\}/g) || []).length;
    const endEnums = (tex.match(/\\end\{enumerate\}/g) || []).length;
    const beginItems = (tex.match(/\\begin\{itemize\}/g) || []).length;
    const endItems = (tex.match(/\\end\{itemize\}/g) || []).length;
    console.log(`TEX doc=${docId} length=${tex.length}`);
    console.log(`enumerate: ${beginEnums}/${endEnums} | itemize: ${beginItems}/${endItems}`);

    expect(beginEnums, "enumerate balance").toBe(endEnums);
    expect(beginItems, "itemize balance").toBe(endItems);
    // outer enumerate (Collect A/B/C) + nested enumerate question + solution = 3 enumerate
    expect(beginEnums, "almeno 2 enumerate (outer + question nested)").toBeGreaterThanOrEqual(2);
    // itemize = la sub-list ul nested
    expect(beginItems, "almeno 1 itemize (sub-list ul)").toBeGreaterThanOrEqual(1);

    // Contenuto preservato
    expect(tex, "alfa nel TEX").toContain("alfa");
    expect(tex, "beta nel TEX").toContain("beta");
    expect(tex, "step S1 in solution").toContain("step S1");

    console.log(`✓ Nested lists E2E passed (doc ${docId})`);
});
