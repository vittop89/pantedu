/**
 * Phase 24.18 — diagnostica deep: verifica render VISIBILE (DOM piercing shadow)
 * + popover JSON fetch + table cell pop per Piano annuale docente.
 */
const { test } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

function walkAllSnippet() {
    return `function* walkAll(root) {
        const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
        let n;
        while ((n = tw.nextNode())) {
            if (n.shadowRoot) yield* walkAll(n.shadowRoot);
            yield n;
        }
    }`;
}

test.describe("deep diag", () => {
    test("piano-annuale render + popover + table", async ({ page }) => {
        test.setTimeout(90_000);

        const log = [];
        page.on("console", (msg) => log.push(`[${msg.type()}] ${msg.text()}`));
        page.on("pageerror", (err) => log.push(`[pageerror] ${err.message}`));

        await loginAdmin(page);
        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json()).catch(() => ({}));
        const piano = (tmplJson.templates || []).find((t) =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale") ||
            String(t.code || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }
        console.log("[diag] template id:", piano.id);

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(4000);

        // Simula dropdown state
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
        await page.waitForTimeout(3000);

        // Rapporto visibilità per sezioni
        const report = await page.evaluate(() => {
             
            function* walkAll(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walkAll(n.shadowRoot);
                    yield n;
                }
            }
            const sections = [];
            for (const el of walkAll(document)) {
                if (el.tagName?.toLowerCase() === "fm-risdoc-pt-section") {
                    const title = el.section?.title || "?";
                    const editors = [];
                    const subWalk = walkAll(el.shadowRoot || el);
                    let editor = null;
                    for (const se of subWalk) {
                        if (se.tagName?.toLowerCase() === "fm-risdoc-pt-editor") { editor = se; break; }
                    }
                    let cbItems = 0;
                    let ptSelects = 0;
                    let ptTables = 0;
                    let checkboxInputs = 0;
                    if (editor?.shadowRoot) {
                        for (const e2 of walkAll(editor.shadowRoot)) {
                            if (e2.matches?.(".pt-checkbox-item")) cbItems++;
                            if (e2.matches?.('[data-pt-type="ptSelect"]')) ptSelects++;
                            if (e2.matches?.('[data-pt-type="ptTable"], [data-pt-type="table"]')) ptTables++;
                            if (e2.matches?.(".pt-checkbox-item input[type='checkbox']")) checkboxInputs++;
                        }
                    }
                    sections.push({ title, cbItems, ptSelects, ptTables, checkboxInputs, hasEditor: !!editor });
                }
            }
            return sections;
        });
        console.log("[diag] SECTIONS:", JSON.stringify(report, null, 2));

        // Cerca info su pt-table cell popover
        const tableInfo = await page.evaluate(() => {
             
            function* walkAll(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walkAll(n.shadowRoot);
                    yield n;
                }
            }
            const tables = [];
            for (const el of walkAll(document)) {
                if (el.matches?.('[data-pt-type="ptTable"]') || el.matches?.('[data-pt-type="table"]')) {
                    tables.push({
                        tag: el.tagName,
                        type: el.getAttribute("data-pt-type"),
                        hasPopover: !!el.querySelector?.(".pt-table-cell-pop"),
                        cellCount: el.querySelectorAll?.("td, th").length || 0,
                    });
                }
            }
            return tables;
        });
        console.log("[diag] TABLES:", JSON.stringify(tableInfo, null, 2));

        // Console logs relevant
        console.log("[diag] console sez 2/3 options load:");
        for (const l of log) {
            if (l.includes("ASSE") || l.includes("OBIETTIVI") || l.includes("options") || l.includes("[pt-")) {
                console.log("  " + l);
            }
        }
    });
});
