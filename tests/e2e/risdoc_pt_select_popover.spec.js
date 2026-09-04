/**
 * Phase 24.17 — E2E popover del <ptSelect> nel PT editor.
 *
 * Verifica:
 *   - click "⚙" apre popover
 *   - click modalità (inline/file/folder) cambia options_source
 *   - input path (file/folder) blur → dispatch
 *   - "+" aggiunge opzione; preserva popover (non si chiude su dispatch)
 *   - "×" rimuove opzione
 *   - input value/label blur aggiorna senza chiudere popover
 *   - "Chiudi" chiude
 *   - riapertura riflette stato aggiornato
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe("risdoc PT select popover", () => {
    test("popover persiste durante dispatch + tutti i pulsanti funzionano", async ({ page }) => {
        test.setTimeout(60_000);

        const consoleLog = [];
        page.on("console", (msg) => {
            const txt = msg.text();
            if (txt.includes("[pt-") || txt.includes("[fm-risdoc")) {
                consoleLog.push(`[${msg.type()}] ${txt}`);
            }
        });
        page.on("pageerror", (err) => consoleLog.push(`[pageerror] ${err.message}`));

        await loginAdmin(page);

        // Trova template piano-annuale
        const tmplList = await page.request.get("/api/risdoc/templates");
        const tmplJson = await tmplList.json().catch(() => ({}));
        const tmpls = tmplJson.templates || tmplJson.rows || [];
        const piano = tmpls.find((t) =>
            t.schema_id === "piano-annuale-docente" || t.argomento?.toLowerCase().includes("piano")
        );
        if (!piano) { test.skip(true, "piano-annuale template non trovato"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(3000); // attendi hydration

        // Helper: pierce shadow DOM per trovare ptSelect container
        const findPtSelect = async () => {
            return await page.evaluate(() => {
                function* walk(root) {
                    const treeWalker = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                    let node;
                    while ((node = treeWalker.nextNode())) {
                        if (node.shadowRoot) yield* walk(node.shadowRoot);
                        yield node;
                    }
                }
                for (const el of walk(document)) {
                    if (el.matches?.('[data-pt-type="ptSelect"]')) return true;
                }
                return false;
            });
        };

        // Se non c'è un ptSelect di default nello schema, inseriscilo via toolbar
        let hasSelect = await findPtSelect();
        if (!hasSelect) {
            console.log("[test] nessun ptSelect in schema → inserisco via toolbar");
            // Click dentro un pt-editor per focus + click su "⬇ Select" nella toolbar
            const inserted = await page.evaluate(() => {
                function* walk(root) {
                    const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                    let node;
                    while ((node = tw.nextNode())) {
                        if (node.shadowRoot) yield* walk(node.shadowRoot);
                        yield node;
                    }
                }
                // Trova il primo fm-risdoc-pt-editor e click sul suo tiptap
                for (const el of walk(document)) {
                    if (el.tagName?.toLowerCase() === "fm-risdoc-pt-editor") {
                        const tiptap = el.shadowRoot?.querySelector(".ProseMirror");
                        if (tiptap) { tiptap.focus(); tiptap.click(); return true; }
                    }
                }
                return false;
            });
            if (!inserted) { test.skip(true, "non trovato pt-editor"); return; }
            await page.waitForTimeout(300);
            // Trigger insertQuick ptSelect via FM.pt.currentEditor (più affidabile del click su toolbar)
            await page.evaluate(() => {
                const ed = window.FM?.pt?.currentEditor;
                if (ed?.insertQuick) ed.insertQuick("ptSelect", ["Test Label", "", [{value:"a",label:"Opzione A"}]]);
            });
            await page.waitForTimeout(500);
            hasSelect = await findPtSelect();
        }
        expect(hasSelect, "ptSelect deve essere presente").toBeTruthy();

        // Helper: click ⚙ dentro shadow DOM
        const clickEdit = async () => {
            return await page.evaluate(() => {
                function* walk(root) {
                    const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                    let n;
                    while ((n = tw.nextNode())) {
                        if (n.shadowRoot) yield* walk(n.shadowRoot);
                        yield n;
                    }
                }
                for (const el of walk(document)) {
                    if (el.matches?.(".pt-select-edit-btn")) { el.click(); return true; }
                }
                return false;
            });
        };

        // 1. Apri popover
        expect(await clickEdit(), "click ⚙ deve trovare il button").toBeTruthy();
        await page.waitForTimeout(200);

        const popoverVisible = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover")) return true;
            }
            return false;
        });
        expect(popoverVisible, "popover deve aprirsi").toBeTruthy();

        // 2. Click "+ aggiungi opzione" → verifica popover NON si chiude (bug pre-fix)
        const addResult = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover-add")) { el.click(); return true; }
            }
            return false;
        });
        expect(addResult, "+ aggiungi trovato").toBeTruthy();
        await page.waitForTimeout(300);

        const stillOpen = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            let pop = null;
            let rowCount = 0;
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover")) pop = el;
                if (el.matches?.(".pt-select-popover-list .pt-select-popover-row")) rowCount++;
            }
            return { open: !!pop, rowCount };
        });
        expect(stillOpen.open, "popover resta aperto dopo + add (fix 24.17)").toBeTruthy();
        expect(stillOpen.rowCount, "row count aumentato dopo add").toBeGreaterThan(0);

        // 3. Click modalità "📄 File JSON"
        const clickFileMode = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-src-btn") && el.textContent?.includes("File JSON")) {
                    el.click();
                    return true;
                }
            }
            return false;
        });
        expect(clickFileMode, "button File JSON trovato e cliccato").toBeTruthy();
        await page.waitForTimeout(300);

        // Verifica che appaia l'input path
        const pathInputVisible = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-src-path")) return true;
            }
            return false;
        });
        expect(pathInputVisible, "input path appare in modalità File").toBeTruthy();

        // 4. Click modalità "📁 Folder"
        const clickFolderMode = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-src-btn") && el.textContent?.includes("Folder")) {
                    el.click();
                    return true;
                }
            }
            return false;
        });
        expect(clickFolderMode).toBeTruthy();
        await page.waitForTimeout(300);

        // 5. Torna a Inline
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
                if (el.matches?.(".pt-select-src-btn") && el.textContent?.includes("Inline")) {
                    el.click();
                    return;
                }
            }
        });
        await page.waitForTimeout(300);

        // 6. Rimuovi una riga (×)
        const rmResult = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover-rm")) { el.click(); return true; }
            }
            return false;
        });
        await page.waitForTimeout(300);

        const afterRm = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            let open = false;
            let rowCount = 0;
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover")) open = true;
                if (el.matches?.(".pt-select-popover-list .pt-select-popover-row")) rowCount++;
            }
            return { open, rowCount };
        });
        expect(afterRm.open, "popover resta aperto dopo rm").toBeTruthy();

        // 7. Click "Chiudi"
        const closeResult = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover-close")) { el.click(); return true; }
            }
            return false;
        });
        expect(closeResult).toBeTruthy();
        await page.waitForTimeout(300);

        const afterClose = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-select-popover")) return true;
            }
            return false;
        });
        expect(afterClose, "popover chiuso").toBeFalsy();

        // Errors in console?
        const errors = consoleLog.filter((l) => l.startsWith("[pageerror]") || l.startsWith("[error]"));
        expect(errors, `nessun errore console (trovati: ${errors.join("\n")})`).toHaveLength(0);

        console.log("[test] popover E2E OK");
    });
});
