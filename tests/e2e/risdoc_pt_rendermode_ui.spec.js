// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Phase 24.24 — verifica flow completo: click UI renderMode button →
 * pt-change → template save → TeX generato via /tex endpoint.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");
const AdmZip = require("adm-zip");

test.describe("renderMode UI click → TeX output", () => {
    test.skip("click 'Solo spuntati' propaga a pt saved + TeX ha itemize", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tmplJson.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(3000);

        // Assicura che esista almeno un checkboxGroup visibile
        const cbGroupCount = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            let c = 0;
            for (const el of walk(document)) if (el.matches?.(".pt-checkbox-group")) c++;
            return c;
        });
        expect(cbGroupCount, "almeno 1 checkboxGroup").toBeGreaterThan(0);

        // Spunta il primo item del primo checkbox group (serve almeno 1 x per "checked-only")
        await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-checkbox-group .pt-checkbox-item input[type='checkbox']")) {
                    el.checked = true;
                    el.dispatchEvent(new Event("change", { bubbles: true }));
                    return;
                }
            }
        });
        await page.waitForTimeout(400);

        // Click "• Solo spuntati" sul PRIMO checkbox mode-bar
        const clicked = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-checkbox-mode-btn") && (el.textContent || "").includes("Solo spuntati")
                    && !(el.textContent || "").includes("inline")) {
                    el.click();
                    return true;
                }
            }
            return false;
        });
        expect(clicked, "button 'Solo spuntati' trovato e cliccato").toBeTruthy();
        await page.waitForTimeout(500); // emit debounced 150ms + event propagation

        // Verifica che il PT AST saved (in fields) contiene renderMode
        const savedInfo = await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            const fields = wc?._values?.fields || {};
            const out = { names: [], cbGroupsWithMode: [] };
            for (const [name, val] of Object.entries(fields)) {
                if (!Array.isArray(val)) continue;
                out.names.push(name);
                for (const block of val) {
                    if (block?._type === "checkboxGroup" && block.renderMode) {
                        out.cbGroupsWithMode.push({ field: name, mode: block.renderMode });
                    }
                }
            }
            return out;
        });
        console.log("[test] saved info:", JSON.stringify(savedInfo));
        expect(savedInfo.cbGroupsWithMode.length,
            "almeno 1 checkboxGroup con renderMode nel fields saved").toBeGreaterThan(0);

        // Save compilation + fetch tex
        const csrfR = await page.request.get("/auth/csrf");
        const csrf = (await csrfR.json()).token;
        const currentValues = await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            return wc?._values || { fields: {}, state: {} };
        });
        const save = await page.request.post(`/api/risdoc/templates/${piano.id}/compilations`, {
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "Accept": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            data: new URLSearchParams({
                _csrf: csrf,
                compilation_key: "e2e_ui_rm_" + Date.now(),
                label: "UI renderMode",
                data: JSON.stringify(currentValues),
            }).toString(),
        });
        expect(save.ok(), `save ${save.status()}`).toBeTruthy();
        const sj = await save.json();
        const compId = sj.id;
        expect(compId, "compilation id nel save response").toBeTruthy();

        const texRes = await page.request.get(
            `/api/risdoc/templates/${piano.id}/tex?compilation_id=${compId}`
        );
        const tex = await texRes.text();

        // Cerca il segnale che "checked-only" è stato applicato: presenza di
        // almeno un \item X SENZA \xcheckbox/\checkbox prima (dal checkboxGroup
        // marked checked-only).
        const checkedOnlyHit = /(^|\n)\s*\\item\s+[^\\]/.test(tex);
        const hasCheckbox = /\\x?checkbox\{/.test(tex);
        console.log("[test] tex has \\item bullet:", checkedOnlyHit,
                    " \\checkbox:", hasCheckbox);
        expect(checkedOnlyHit, "TeX contiene \\item bullet (checked-only applicato)").toBeTruthy();
    });

    test.skip("export ZIP: profilo_classe PT AST con renderMode applicato al .tex", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tmplJson.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        // Costruisci un form_state con profilo_classe PT AST checkboxGroup
        // renderMode=checked-only. Il field name matcha sectionMap in ExportController.
        const profiloPt = [
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "Prefisso inline. ", marks: [] },
            ]},
            { _type: "checkboxGroup", renderMode: "checked-only",
              items: [
                  { state: "x", label: "MARKER_CHECKED_ONLY_A" },
                  { state: "_", label: "MARKER_UNCHECKED_B" },
                  { state: "x", label: "MARKER_CHECKED_ONLY_C" },
              ]},
        ];

        const csrfR = await page.request.get("/auth/csrf");
        const csrf = (await csrfR.json()).token;

        const body = new URLSearchParams({
            _csrf: csrf,
            mode: "zip",
            form_state: JSON.stringify({
                fields: { profilo_classe: profiloPt },
                state: { indirizzo: "sc", classe: "2s", disciplina: "MAT" },
            }),
        });
        const exp = await page.request.post(`/api/risdoc/templates/${piano.id}/export`, {
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest",
                "Accept": "application/json",
            },
            data: body.toString(),
        });
        expect(exp.ok(), `export ${exp.status()}`).toBeTruthy();
        const j = await exp.json();

        // Scarica ZIP, estrai il doc .tex (non main.tex) per verificare override
        const zipRes = await page.request.get(j.url);
        const buf = await zipRes.body();
        const zip = new AdmZip(buf);
        const docEntry = zip.getEntries().find((e) =>
            e.entryName.endsWith(".tex") && !e.entryName.endsWith("main.tex")
            && !e.entryName.includes("texCommon/")
        );
        expect(docEntry, "doc .tex nel ZIP").toBeTruthy();
        const docTex = docEntry.getData().toString("utf8");

        console.log("[test] doc.tex has MARKER_CHECKED_ONLY_A:",
            docTex.includes("MARKER_CHECKED_ONLY_A"));
        // Print sectionbox{OSSERVAZIONI} content
        const match = docTex.match(/\\begin\{sectionbox\}\{OSSERVAZIONI\}([\s\S]*?)\\end\{sectionbox\}/);
        console.log("[test] OSSERVAZIONI content:", match ? match[1].slice(0, 500) : "(not found)");

        // Normalizza escape TeX \_ → _ per match semplice
        const docTexN = docTex.replace(/\\_/g, "_");
        // Il renderMode checked-only deve produrre \item bullet con i labels x,
        // NO labels unchecked.
        expect(docTexN, "MARKER_CHECKED_ONLY_A presente").toContain("MARKER_CHECKED_ONLY_A");
        expect(docTexN, "MARKER_CHECKED_ONLY_C presente").toContain("MARKER_CHECKED_ONLY_C");
        expect(docTexN, "MARKER_UNCHECKED_B assente (checked-only)").not.toContain("MARKER_UNCHECKED_B");
        expect(docTexN, "itemize presente per checked-only").toMatch(/\\item\s+MARKER_CHECKED_ONLY_A/);
        expect(docTexN, "no \\checkbox per i MARKER").not.toMatch(/\\x?checkbox\{MARKER/);
    });
});
