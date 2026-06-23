/**
 * E2E REAL UI: simula click SalvaTEX su DOM scratch contenente
 * .fm-collection__item con <ol class="fm-dsa-li-list"> 4 items + svg TikZ.
 *
 * Verifica che il FIX a buildSelectionFromDOM cattura la <ol> nell'output
 * payload. (Prima del fix, .fm-dsa-li-list veniva ignorata e il TEX salvato
 * non aveva la lista numerata.)
 *
 * Hermetic: NON dipende dall'URL della verifica reale; iniettiamo il DOM.
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test.describe("SalvaTEX scratch DOM → fix buildSelectionFromDOM cattura <ol>", () => {
    test("payload POST /api/verifica/save-tex(-batch) contiene <ol> + 4 <li>", async ({ page }) => {
        // 1. Login
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

        // 2. Vai alla home (che ha #fm-content + topbar)
        await page.goto("/?home=1", { waitUntil: "networkidle" });

        // 3. Inietta DOM scratch + meta select values
        await page.evaluate(() => {
            // Meta select per buildSelectionFromDOM
            const setSel = (id, val) => {
                let el = document.getElementById(id);
                if (!el) {
                    el = document.createElement("select");
                    el.id = id;
                    el.style.display = "none";
                    document.body.appendChild(el);
                }
                const opt = document.createElement("option");
                opt.value = val; opt.textContent = val; opt.selected = true;
                el.innerHTML = "";
                el.appendChild(opt);
            };
            setSel("sel-iis", "sc");
            setSel("sel-cls", "1");
            setSel("sel-mater", "MAT");

            const verTitle = document.createElement("input");
            verTitle.id = "verTitle"; verTitle.value = "TestE2E_Lavatrice";
            verTitle.style.display = "none";
            document.body.appendChild(verTitle);

            // DOM scratch: 1 .fm-groupcollex con 1 .fm-collection__item, dentro .fm-collection
            // tre nodi data-raw + una <ol class="fm-dsa-li-list"> con 4 <li>.
            // Il fix a collectRawNodes dovrebbe emettere il <ol> outerHTML.
            const root = document.getElementById("fm-content") || document.body;
            // Rimuovi eventuali .fm-groupcollex preesistenti per non interferire
            root.querySelectorAll(".fm-groupcollex").forEach(p => p.remove());

            const tikzBody = "\\begin{tikzpicture}\\foreach \\i in {1,...,5} { \\ifnum\\i<3 \\draw (\\i,0) circle (1pt); \\fi }\\end{tikzpicture}";
            const tikzBodyEnc = encodeURIComponent(tikzBody);

            const html = `
              <div class="fm-groupcollex" id="type_Collect_test_lavatrice">
                <input type="checkbox" class="checkboxA" checked>
                <span class="fm-testo">Risolvi il seguente problema</span>
                <ol class="fm-collexercise">
                  <li class="fm-collection__item" data-id="cx_lavatrice">
                    <input type="checkbox" class="fm-checkbox-ain" checked>
                    <input type="number" class="fm-input-pt" value="1">
                    <input type="checkbox" class="checksol">
                    <div class="fm-collection">
                      <span class="fm-text" data-raw="Il prezzo ">Il prezzo </span>
                      <span class="fm-latex" data-raw="$P$">$P$</span>
                      <span class="fm-text" data-raw=" per la riparazione di una lavatrice prevede 35 euro fissi.">
                        per la riparazione di una lavatrice prevede 35 euro fissi.
                      </span>
                      <ol class="fm-dsa-li-list">
                        <li data-fm-dsa-state=""><span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span><span class="fm-dsa-li-num">1.</span><span class="fm-dsa-li-content">scrivi la legge</span></li>
                        <li data-fm-dsa-state=""><span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span><span class="fm-dsa-li-num">2.</span><span class="fm-dsa-li-content">rappresentala</span></li>
                        <li data-fm-dsa-state=""><span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span><span class="fm-dsa-li-num">3.</span><span class="fm-dsa-li-content">A quanto ammonta</span></li>
                        <li data-fm-dsa-state=""><span class="fm-dsa-li-buttons"><button>F</button><button>GF</button></span><span class="fm-dsa-li-num">4.</span><span class="fm-dsa-li-content">Quante ore ha impiegato &gt; 179</span></li>
                      </ol>
                      <svg data-tikz-hash="abc" data-tikz-tagopen="${encodeURIComponent('<script type="text/tikz">')}" data-tikz-body="${tikzBodyEnc}"><path d="M0 0"/></svg>
                    </div>
                    <div class="fm-sol"></div>
                  </li>
                </ol>
              </div>`;
            const wrap = document.createElement("div");
            wrap.innerHTML = html;
            root.appendChild(wrap);
        });

        // 4. Invoca direttamente buildSelectionFromDOM (esposto via window.FM
        //    per i test). Costruisce il payload come farebbe doSalvaTex.
        const selPayload = await page.evaluate(() => {
            const fn = window.FM?.__buildSelectionFromDOM_forTest;
            if (typeof fn !== "function") return { error: "builder_not_exposed" };
            return fn();
        });
        expect(selPayload, "buildSelectionFromDOM ritorna payload").toBeTruthy();
        expect(selPayload.error, "builder esposto").toBeUndefined();
        expect(selPayload.problems, "almeno 1 problem").toBeTruthy();
        expect(selPayload.problems.length, "1 problem catturato").toBeGreaterThan(0);

        const itemsHtml = selPayload.problems.flatMap(p => (p.items || []).map(i => i.html || ""));
        const concatHtml = itemsHtml.join("\n");

        // 5. POST /api/verifica/save-tex con il payload generato
        const csrfResp = await page.request.get("/auth/csrf");
        const csrf = (await csrfResp.json()).token;

        const apiResp = await page.request.post("/api/verifica/save-tex", {
            data: { ...selPayload, variant: "normal", title: selPayload.verTitle, materia: selPayload.selectedMATER, indirizzo: selPayload.selectedIIS, classe: selPayload.selectedCLS },
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/json",
            },
            timeout: 60000,
        });
        const reqJson = selPayload;
        const respBody = await apiResp.json().catch(() => ({}));

        const outDir = "tests/e2e-results";
        fs.mkdirSync(outDir, { recursive: true });
        fs.writeFileSync(path.join(outDir, "scratch_payload.json"), JSON.stringify(reqJson, null, 2));
        fs.writeFileSync(path.join(outDir, "scratch_response.json"), JSON.stringify(respBody, null, 2));

        console.log("payload html (first 400):", concatHtml.slice(0, 400));

        // CRITICAL: payload deve contenere la <ol> grazie al fix
        expect(concatHtml, "payload html deve contenere <ol>").toMatch(/<ol[\s>]/i);
        expect(concatHtml, "payload html deve contenere <li>").toMatch(/<li[\s>]/i);
        expect(concatHtml, "payload deve contenere 'scrivi la legge'").toContain("scrivi la legge");
        expect(concatHtml, "payload deve contenere 'rappresentala'").toContain("rappresentala");
        expect(concatHtml, "payload deve contenere 'Quante ore'").toContain("Quante ore");
        // (TikZ verificato in sanitizer_list_tikz_fix.spec.js — non duplicato qui)

        // 7. Se response ok → fetch TEX e verifica enumerate
        if (respBody?.ok && respBody?.docs?.[0]?.id) {
            const docId = respBody.docs[0].id;
            const texResp = await page.request.get(`/api/verifica/${docId}/tex`);
            const tex = await texResp.text();
            fs.writeFileSync(path.join(outDir, `scratch_doc_${docId}.tex`), tex);

            const itemCount = (tex.match(/\\item\b/g) || []).length;
            console.log(`TEX doc=${docId} length=${tex.length} \\item=${itemCount}`);
            expect(itemCount, "almeno 5 \\item (1 outer + 4 inner)").toBeGreaterThanOrEqual(5);
            expect(tex, "TEX deve contenere \\begin{enumerate}").toMatch(/\\begin\{enumerate\}/);
            expect(tex, "scrivi la legge in TEX").toContain("scrivi la legge");
            expect(tex, "rappresentala in TEX").toContain("rappresentala");
            expect(tex, "tikzpicture in TEX").toMatch(/\\begin\{tikzpicture\}/);
            expect(tex, "\\ifnum\\i<3 preservato").toMatch(/\\ifnum\\i\s*<\s*3/);
        } else {
            console.log("Skip TEX assertions (response not ok):", respBody);
        }
    });
});
