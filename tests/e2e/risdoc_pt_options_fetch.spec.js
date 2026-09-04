/**
 * Phase 24.13 E2E — Verifica fetch options_source per sezioni pt_unified.
 *
 * Diagnostica il bug reportato: sezione "3. OBIETTIVI DISCIPLINARI"
 * (sub-items COMPETENZE/ABILITA/CONOSCENZE) non mostra options anche
 * dopo selezione classe/sezione/disciplina nell'header.
 *
 * Strategy: intercetta le requests HTTP `/risdoc/...` e verifica:
 *   - Quante request partono dopo dropdown change
 *   - Status code (200 vs 401 vs 404)
 *   - Response content (JSON con options o vuoto)
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe("risdoc PT options_source fetch", () => {
    test("piano-annuale: sezioni pt_unified fetch options dopo state complete", async ({ page }) => {
        test.setTimeout(60_000);

        // Raccolta network per diagnostica
        const risdocReqs = [];
        page.on("response", async (r) => {
            const url = r.url();
            if (url.includes("/risdoc/obiettivi_disciplinari") ||
                url.includes("/risdoc/competenze_") ||
                url.includes("/risdoc/competenze_DM2007") ||
                url.includes("/risdoc/competenze_PECUP")) {
                try {
                    const status = r.status();
                    const ct = r.headers()["content-type"] || "";
                    const body = ct.includes("json") ? await r.json().catch(() => null) : null;
                    risdocReqs.push({
                        url: url.replace(/https?:\/\/[^/]+/, ""),
                        status,
                        ct,
                        bodyLen: body ? (Array.isArray(body) ? body.length : Object.keys(body || {}).length) : null,
                    });
                } catch (_) {}
            }
        });

        const consoleLog = [];
        page.on("console", (msg) => {
            const txt = msg.text();
            if (txt.includes("[pt-section]") || txt.includes("[options-fetcher]") || txt.includes("[fm-risdoc")) {
                consoleLog.push(`[${msg.type()}] ${txt}`);
            }
        });

        await loginAdmin(page);

        // Trova ID template piano-annuale-docente via API
        const tmplList = await page.request.get("/api/risdoc/templates");
        const tmplJson = await tmplList.json().catch(() => ({}));
        const tmpls = tmplJson.templates || tmplJson.rows || [];
        const piano = tmpls.find((t) =>
            (t.schema_id === "piano-annuale-docente" || t.argomento?.toLowerCase().includes("piano"))
        );
        if (!piano) {
            test.skip(true, "piano-annuale-docente template non trovato in DB");
            return;
        }
        console.log("[e2e] template piano-annuale id:", piano.id);

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(1500); // attendi hydration + components

        // Simula selezione header state: classe, indirizzo, disciplina
        // Header component fa trigger su dropdown native sidebar.
        await page.evaluate(() => {
            const set = (id, v) => {
                const el = document.getElementById(id);
                if (!el) return;
                el.value = v;
                el.dispatchEvent(new Event("change", { bubbles: true }));
            };
            set("sel-iis", "sc");
            set("sel-cls", "2s");
            set("sel-mater", "MAT");
        });
        await page.waitForTimeout(3000); // attendi fetch options

        console.log("[e2e] risdoc network requests:", JSON.stringify(risdocReqs, null, 2));
        console.log("[e2e] pt-section console logs:");
        for (const l of consoleLog) console.log(l);

        // Assertions
        expect(risdocReqs.length,
            "almeno 1 fetch /risdoc/... atteso dopo state complete").toBeGreaterThan(0);

        const failed = risdocReqs.filter((r) => r.status >= 400);
        if (failed.length > 0) {
            console.error("[e2e] FAILED requests:", failed);
        }
        expect(failed.length,
            "nessuna request /risdoc/ dovrebbe fallire (401/404/500)").toBe(0);

        // Verifica via shadow-pierce: fm-risdoc-pt-editor + checkbox items
        const piercedCounts = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            let editors = 0, cbItems = 0;
            for (const el of walk(document)) {
                if (el.tagName?.toLowerCase() === "fm-risdoc-pt-editor") editors++;
                if (el.matches?.(".pt-checkbox-item")) cbItems++;
            }
            return { editors, cbItems };
        });
        console.log("[e2e] pierced counts:", JSON.stringify(piercedCounts));
        expect(piercedCounts.editors, "almeno 1 PT editor").toBeGreaterThan(0);
        expect(piercedCounts.cbItems, "almeno 1 checkbox item").toBeGreaterThan(0);
    });
});
