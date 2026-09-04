/**
 * Phase 24.18 — E2E pt-table cell popover.
 *
 * Verifica:
 *   - click ⚙ sulla cella apre popover
 *   - +col/-col/+row/-row NON chiudono il popover (persiste post-update)
 *   - tipo "Select" mostra sezione opzioni con +add/×rm
 *   - tipo "Input" mostra kind buttons + placeholder input
 *   - Chiudi chiude
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function pierceFirst(page, selector) {
    return await page.evaluate((sel) => {
        function* walk(root) {
            const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
            let n;
            while ((n = tw.nextNode())) {
                if (n.shadowRoot) yield* walk(n.shadowRoot);
                yield n;
            }
        }
        for (const el of walk(document)) {
            if (el.matches?.(sel)) { el.click(); return true; }
        }
        return false;
    }, selector);
}

async function pierceCount(page, selector) {
    return await page.evaluate((sel) => {
        function* walk(root) {
            const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
            let n;
            while ((n = tw.nextNode())) {
                if (n.shadowRoot) yield* walk(n.shadowRoot);
                yield n;
            }
        }
        let count = 0;
        for (const el of walk(document)) if (el.matches?.(sel)) count++;
        return count;
    }, selector);
}

async function clickTextMatches(page, selector, text) {
    return await page.evaluate(([sel, t]) => {
        function* walk(root) {
            const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
            let n;
            while ((n = tw.nextNode())) {
                if (n.shadowRoot) yield* walk(n.shadowRoot);
                yield n;
            }
        }
        for (const el of walk(document)) {
            if (el.matches?.(sel) && (el.textContent || "").includes(t)) {
                el.click();
                return true;
            }
        }
        return false;
    }, [selector, text]);
}

test.describe("risdoc PT table cell popover", () => {
    test("merge +/- non chiude pop + select/input config visibili", async ({ page }) => {
        test.setTimeout(60_000);

        const errors = [];
        page.on("console", (msg) => {
            if (msg.type() === "error") errors.push(msg.text());
        });
        page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));

        await loginAdmin(page);
        const tmplJson = await page.request.get("/api/risdoc/templates").then(r => r.json()).catch(() => ({}));
        const piano = (tmplJson.templates || []).find((t) =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(3500);

        // Verifica ptTable presenti
        const tableCount = await pierceCount(page, '[data-pt-type="ptTable"]');
        expect(tableCount, "almeno 1 ptTable").toBeGreaterThan(0);

        // Tabelle default sono vuote → aggiungi una riga via toolbar
        const cfgCount = await pierceCount(page, ".pt-table-cell-cfg");
        if (cfgCount === 0) {
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
                    // "↓ sotto" = inserisci riga sotto/in fondo (ex "+ riga")
                    if (el.matches?.(".pt-table-btn") && (el.textContent || "").includes("sotto")) {
                        el.click();
                        return;
                    }
                }
            });
            await page.waitForTimeout(400);
        }

        // Click primo ⚙ cell
        expect(await pierceFirst(page, ".pt-table-cell-cfg"), "click cfg").toBeTruthy();
        await page.waitForTimeout(300);

        let popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover aperto").toBe(1);

        // Click + col → popover DEVE restare
        expect(await clickTextMatches(page, ".pt-table-cell-pop-row button", "+ col"), "+ col click").toBeTruthy();
        await page.waitForTimeout(300);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo + col").toBe(1);

        // Click - col
        expect(await clickTextMatches(page, ".pt-table-cell-pop-row button", "− col"), "- col click").toBeTruthy();
        await page.waitForTimeout(300);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo - col").toBe(1);

        // Switch to Select type
        expect(await clickTextMatches(page, ".pt-table-cell-pop-type", "Select"), "Select type").toBeTruthy();
        await page.waitForTimeout(800); // attesa tiptap dispatch + re-render
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo select type").toBe(1);

        // Verifica active è ora Select (non Testo)
        const activeTypeText = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-pop-type.active")) return el.textContent;
            }
            return "(none)";
        });
        expect(activeTypeText, "active type = Select").toBe("Select");

        // Verifica sezione "Opzioni del menù" visibile
        const optsSection = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-pop-h") && (el.textContent || "").includes("Opzioni del menù")) {
                    return true;
                }
            }
            return false;
        });
        expect(optsSection, "sezione Opzioni visibile").toBeTruthy();

        // + aggiungi opzione
        expect(await pierceFirst(page, ".pt-table-cell-pop-add"), "add btn").toBeTruthy();
        await page.waitForTimeout(300);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo add").toBe(1);

        // × rm una
        await pierceFirst(page, ".pt-table-cell-pop-rm");
        await page.waitForTimeout(300);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo rm").toBe(1);

        // Switch to Input type
        expect(await clickTextMatches(page, ".pt-table-cell-pop-type", "Input"), "Input type").toBeTruthy();
        await page.waitForTimeout(300);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover resta aperto dopo input type").toBe(1);

        // Sezione "Tipo input" deve esserci
        const inputSection = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-pop-h") && (el.textContent || "").includes("Tipo input")) {
                    return true;
                }
            }
            return false;
        });
        expect(inputSection, "sezione Tipo input").toBeTruthy();

        // Phase 24.20 — click su "📄 JSON" deve mostrare dropdown con catalogo
        // (non dropdown vuoto). Torna a Select prima per avere srcRow visibile.
        await clickTextMatches(page, ".pt-table-cell-pop-type", "Select");
        await page.waitForTimeout(500);

        expect(await clickTextMatches(page, ".pt-table-cell-pop-type", "JSON"),
               "click JSON src button").toBeTruthy();
        await page.waitForTimeout(1000); // attesa fetch catalogo

        const srcPathCount = await pierceCount(page, ".pt-table-cell-pop-section select.pt-table-cell-pop-input");
        expect(srcPathCount, "dropdown path visibile dopo JSON click").toBeGreaterThan(0);

        const optionsInPathDropdown = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.(".pt-table-cell-pop-section select.pt-table-cell-pop-input")) {
                    return el.options.length;
                }
            }
            return 0;
        });
        expect(optionsInPathDropdown, "dropdown popolato dal catalogo").toBeGreaterThan(1);

        // Phase 24.21 — seleziona un path dal dropdown e verifica che dopo
        // chiusura il <select> della cella venga popolato con le options
        // fetchate da quel JSON.
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
                if (el.matches?.(".pt-table-cell-pop-section select.pt-table-cell-pop-input")) {
                    // Seleziona prima opzione reale (dopo il placeholder vuoto "— file —")
                    const real = Array.from(el.options).find((o) => o.value !== "");
                    if (real) {
                        el.value = real.value;
                        el.dispatchEvent(new Event("change", { bubbles: true }));
                    }
                    return;
                }
            }
        });
        await page.waitForTimeout(1200); // update + fetch JSON

        // Chiudi
        expect(await pierceFirst(page, ".pt-table-cell-pop-close"), "close").toBeTruthy();
        await page.waitForTimeout(500);
        popCount = await pierceCount(page, ".pt-table-cell-pop");
        expect(popCount, "popover chiuso dopo Chiudi").toBe(0);

        // Cell select popolato dal JSON fetched
        const cellSelectOptCount = await page.evaluate(() => {
            function* walk(root) {
                const tw = document.createTreeWalker(root, NodeFilter.SHOW_ELEMENT);
                let n;
                while ((n = tw.nextNode())) {
                    if (n.shadowRoot) yield* walk(n.shadowRoot);
                    yield n;
                }
            }
            for (const el of walk(document)) {
                if (el.matches?.("select.pt-table-cell-select")) {
                    return el.options.length;
                }
            }
            return 0;
        });
        expect(cellSelectOptCount, "cell select popolato dopo JSON source")
            .toBeGreaterThan(1); // > 1 = più di placeholder vuoto

        expect(errors, `errori console:\n${errors.join("\n")}`).toHaveLength(0);
        console.log("[test] table cell popover OK");
    });
});
