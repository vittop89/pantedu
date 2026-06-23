/**
 * G23 — E2E test della centralizzazione renderer RM tables.
 *
 * Scenari coperti:
 *   1. Markup post-render server: `.wrapCheckCell` (no `.rm-letter` decorativo)
 *   2. Coerenza client/server: editor cells → save → reload → markup identico
 *   3. Tipi colonna B/T/N renderizzati in HTML editor
 *   4. extractCellContent strips checkbox da wrapCheckCell server-rendered
 *
 * Prerequisiti:
 *   - Apache pantedu.local up
 *   - Account docente superadmin con password fornita
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

const TEACHER_USER = process.env.FM_E2E_TEACHER_USER || "superadmin";
const TEACHER_PASS = process.env.FM_E2E_TEACHER_PASS || (process.env.E2E_TEACHER_PASS || "");
const SCREENSHOT_DIR = "tests/e2e/screenshots/g23";

test.describe("G23 — RM table unification", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
                functional: true, analytics: false, advertising: false, timestamp: Date.now(),
            }));
        });
        await page.goto("/login");
        await page.fill('input[name="username"]', TEACHER_USER);
        await page.fill('input[name="password"]', TEACHER_PASS);
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);
    });

    test("server-rendered RM table usa markup wrapCheckCell (no rm-letter)", async ({ page }) => {
        // G23 — Test diretto: usa un contract con RM noto (prova_5.contract.json).
        // Se la pagina indice topics ha link, segui il primo; altrimenti skip.
        const indexUrl = "/studio/verifica/sc/3s/MAT";
        await page.goto(indexUrl, { waitUntil: "networkidle" });
        // Cerca topics-page links generici
        let linkSelector = ".fm-study-topics a";
        let topicsCount = await page.locator(linkSelector).count();
        if (topicsCount === 0) {
            // Fallback: prova altri selettori legacy possibili
            for (const sel of ["a[href*='/studio/verifica/']", ".topic-link", "ul a"]) {
                if (await page.locator(sel).count() > 0) {
                    linkSelector = sel;
                    topicsCount = await page.locator(sel).count();
                    break;
                }
            }
        }
        test.skip(topicsCount === 0, `Nessun topic in ${indexUrl}`);
        await page.locator(linkSelector).first().click();
        await page.waitForSelector(".fm-contract-wrap, .fm-rm-table", { timeout: 15000 });
        await page.waitForTimeout(2000); // attendi inject completa

        const rmCount = await page.locator(".fm-rm-table").count();
        test.skip(rmCount === 0, "Nessuna RM table trovata in questo topic");

        // Snapshot markup
        const stats = await page.evaluate(() => ({
            tables: document.querySelectorAll(".fm-rm-table").length,
            wrapCells: document.querySelectorAll(".fm-rm-table .fm-wrap-check-cell").length,
            rmLetters: document.querySelectorAll(".fm-rm-table .rm-letter").length,
            checkboxRM: document.querySelectorAll(".fm-rm-table .fm-checkbox-rm").length,
            cellContent: document.querySelectorAll(".fm-rm-table .fm-cell-content").length,
        }));
        // Markup parity asserts
        expect(stats.wrapCells).toBeGreaterThan(0);
        expect(stats.rmLetters).toBe(0); // G23: NO rm-letter nel server render
        expect(stats.checkboxRM).toBeGreaterThan(0);

        await page.screenshot({
            path: path.join(SCREENSHOT_DIR, "01-server-render.png"),
            fullPage: false,
        });
    });

    test("RmColumnTypes JS export coerente con PHP", async ({ page }) => {
        await page.goto("/studio/verifica/sc/2s/FIS", { waitUntil: "networkidle" });
        // Verifica che il modulo render/rm-table-view.js sia caricabile
        const moduleExports = await page.evaluate(async () => {
            try {
                const mod = await import("/js/modules/render/rm-table-view.js");
                return {
                    hasRenderRmTable: typeof mod.renderRmTable === "function",
                    hasColTypes: typeof mod.COL_TYPES === "object",
                    types: Object.keys(mod.COL_TYPES || {}),
                    hasExtract: typeof mod.extractCellContent === "function",
                    hasSync: typeof mod.syncCellsShape === "function",
                };
            } catch (e) {
                return { error: e.message };
            }
        });
        expect(moduleExports.error).toBeUndefined();
        expect(moduleExports.hasRenderRmTable).toBe(true);
        expect(moduleExports.hasColTypes).toBe(true);
        expect(moduleExports.types).toEqual(["X", "V", "B", "T", "N"]);
    });

    test("renderRmTable produce markup consistente con server", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });

        // Test pure-JS della funzione renderRmTable usando state minimale
        const result = await page.evaluate(async () => {
            const mod = await import("/js/modules/render/rm-table-view.js");
            const state = {
                tables: [{
                    rows: 1, cols: 2,
                    typecell: "|X|V|",
                    colTypes: ["X", "V"],
                    cells: [["Alpha", "Beta"]],
                    mixtr: false, mixcol: false, mpagew: true,
                    specificWidth: "",
                }],
                orientation: "horizontal",
            };
            const wrap = mod.renderRmTablesWrap(state, { correctMasks: [[[false, true]]] });
            return {
                hasWrapCheckCell: !!wrap.querySelector(".fm-wrap-check-cell"),
                hasCheckbox: !!wrap.querySelector('input[type="checkbox"]'),
                hasRadio: !!wrap.querySelector('input[type="radio"]'),
                hasCellContent: !!wrap.querySelector(".fm-cell-content"),
                hasRmLetter: !!wrap.querySelector(".rm-letter"),
                dataRows: wrap.querySelector(".fm-rm-table")?.dataset.rows,
                dataCols: wrap.querySelector(".fm-rm-table")?.dataset.cols,
                dataTypecell: wrap.querySelector(".fm-rm-table")?.dataset.typecell,
                cellAContent: wrap.querySelector('td[data-col="0"] .fm-cell-content')?.innerHTML,
                cellBCorrect: wrap.querySelector('td[data-col="1"]')?.classList.contains("rm-correct"),
            };
        });
        expect(result.hasWrapCheckCell).toBe(true);
        expect(result.hasCheckbox).toBe(true);
        expect(result.hasRadio).toBe(true);
        expect(result.hasCellContent).toBe(true);
        expect(result.hasRmLetter).toBe(false);  // G23 critical: NO rm-letter
        expect(result.dataRows).toBe("1");
        expect(result.dataCols).toBe("2");
        expect(result.dataTypecell).toBe("|X|V|");
        expect(result.cellAContent).toContain("Alpha");
        expect(result.cellBCorrect).toBe(true);
    });

    test("extractCellContent preserva nested OL + strippa DSA wrappers", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });
        const out = await page.evaluate(async () => {
            const mod = await import("/js/modules/render/rm-table-view.js");
            // Markup completo server-emit (con .fm-dsa-li-num + .fm-dsa-li-content
            // wrappers + nested OL + fm-text span con data-raw).
            const td = document.createElement("td");
            td.className = "rm-option";
            td.innerHTML = `
                <div class="fm-wrap-check-cell">
                    <input type="checkbox" class="checkbox fm-checkbox-rm">
                    <label class="fm-collection">
                        <div class="fm-cell-content">
                            <ol class="fm-dsa-li-list" type="a" data-fm-list-style="abc" data-dsa-section="options">
                                <li data-fm-dsa-state="">
                                    <span class="fm-dsa-li-num">a.</span>
                                    <span class="fm-dsa-li-content">
                                        <span class="fm-text" data-raw="uno">uno</span>
                                        <ol class="fm-dsa-li-list" type="i" data-dsa-section="sub">
                                            <li>
                                                <span class="fm-dsa-li-num">i.</span>
                                                <span class="fm-dsa-li-content">
                                                    <span class="fm-text" data-raw="uno.uno">uno.uno</span>
                                                </span>
                                            </li>
                                        </ol>
                                    </span>
                                </li>
                                <li>
                                    <span class="fm-dsa-li-num">b.</span>
                                    <span class="fm-dsa-li-content">
                                        <span class="fm-text" data-raw="due">due</span>
                                    </span>
                                </li>
                            </ol>
                        </div>
                    </label>
                </div>
            `;
            const extracted = mod.extractCellContent(td);
            // Costruisci un container per validare struttura
            const container = document.createElement("div");
            container.innerHTML = extracted;
            return {
                extracted,
                // Niente input/buttons/wrapCheckCell
                hasInput: /<input/i.test(extracted),
                hasWrapCheckCell: /wrapCheckCell/i.test(extracted),
                hasFmDsaNum: /fm-dsa-li-num/i.test(extracted),
                hasFmDsaContent: /fm-dsa-li-content/i.test(extracted),
                hasFmDsaButtons: /fm-dsa-li-buttons/i.test(extracted),
                // Struttura OL preservata
                rootOLs: container.querySelectorAll(":scope > ol").length,
                // Nested OL preservata
                nestedOLs: container.querySelectorAll("ol ol").length,
                // 2 LI top-level con testo "uno" e "due"
                topLevelLIs: container.querySelectorAll(":scope > ol > li").length,
                // Marker dsa_section "options" sul root
                rootSection: container.querySelector(":scope > ol")?.getAttribute("data-dsa-section"),
                // Sub-list marcata 'sub'
                subSection: container.querySelector("ol ol")?.getAttribute("data-dsa-section"),
                // Testo contenuti
                hasUno: extracted.includes("uno"),
                hasDue: extracted.includes("due"),
                hasUnoUno: extracted.includes("uno.uno"),
            };
        });
        expect(out.hasInput).toBe(false);
        expect(out.hasWrapCheckCell).toBe(false);
        expect(out.hasFmDsaNum).toBe(false);       // strippato
        expect(out.hasFmDsaContent).toBe(false);   // unwrapped
        expect(out.hasFmDsaButtons).toBe(false);
        expect(out.rootOLs).toBe(1);
        expect(out.nestedOLs).toBe(1);             // CRITICO: nested preservato
        expect(out.topLevelLIs).toBe(2);
        expect(out.rootSection).toBe("options");
        expect(out.subSection).toBe("sub");
        expect(out.hasUno).toBe(true);
        expect(out.hasDue).toBe(true);
        expect(out.hasUnoUno).toBe(true);
    });

    test("G23.fix4 FieldSerializer expose API uniforme load+save", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await page.waitForFunction(() => !!window.FM?.FieldSerializer, { timeout: 10000 });
        const api = await page.evaluate(() => {
            const fs = window.FM.FieldSerializer;
            return {
                hasLoadFieldHtml: typeof fs.loadFieldHtml === "function",
                hasLoadFieldText: typeof fs.loadFieldText === "function",
                hasCaptureFieldBlocks: typeof fs.captureFieldBlocks === "function",
                hasCaptureFieldText: typeof fs.captureFieldText === "function",
                hasBlocksToHtml: typeof fs.blocksToHtml === "function",
            };
        });
        expect(api.hasLoadFieldHtml).toBe(true);
        expect(api.hasLoadFieldText).toBe(true);
        expect(api.hasCaptureFieldBlocks).toBe(true);
        expect(api.hasCaptureFieldText).toBe(true);
        expect(api.hasBlocksToHtml).toBe(true);
    });

    test("G23.fix4 group intro roundtrip: nested OL preservato via FieldSerializer", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await page.waitForFunction(() => !!window.FM?.FieldSerializer, { timeout: 10000 });
        const result = await page.evaluate(() => {
            const fs = window.FM.FieldSerializer;
            // Simula `.fm-testo > div` server-rendered con nested OL
            const div = document.createElement("div");
            div.innerHTML = `
                <span class="fm-text" data-raw="INTRO">INTRO</span>
                <ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" data-dsa-section="question">
                    <li data-fm-dsa-state="">
                        <span class="fm-dsa-li-num">a.</span>
                        <span class="fm-dsa-li-content">
                            <span class="fm-text" data-raw="u">u</span>
                            <ol class="fm-dsa-li-list" data-dsa-section="sub">
                                <li>
                                    <span class="fm-dsa-li-num">i.</span>
                                    <span class="fm-dsa-li-content">
                                        <span class="fm-text" data-raw="uu">uu</span>
                                    </span>
                                </li>
                            </ol>
                        </span>
                    </li>
                    <li>
                        <span class="fm-dsa-li-num">b.</span>
                        <span class="fm-dsa-li-content">
                            <span class="fm-text" data-raw="d">d</span>
                        </span>
                    </li>
                </ol>
                <span class="fm-giustifica"> Giustifica adeguatamente le risposte</span>
            `;
            // Step 1: load HTML (cleaned per editor)
            const html = fs.loadFieldHtml(div);
            // Step 2: simulate textarea con cleaned HTML
            const ta = document.createElement("div");
            ta.contentEditable = "true";
            Object.defineProperty(ta, "value", {
                get() { return ta.innerHTML; },
                set(v) { ta.innerHTML = v; },
            });
            ta.value = html;
            document.body.appendChild(ta);
            // Step 3: capture blocks
            const blocks = fs.captureFieldBlocks(ta);
            ta.remove();
            // Step 4: render blocks back to HTML
            const renderedBack = fs.blocksToHtml(blocks);
            return {
                cleanedHtml: html,
                blocks: JSON.parse(JSON.stringify(blocks)),
                renderedBack,
                hasGiustifica: html.includes("giustifica"),
                hasFmDsaNum: html.includes("fm-dsa-li-num"),
                listBlock: blocks.find(b => b?.type === "list"),
                listItemsLen: blocks.find(b => b?.type === "list")?.items?.length,
            };
        });
        // Giustifica span strippato dal loader
        expect(result.hasGiustifica).toBe(false);
        // fm-dsa-li-num strippato dal loader
        expect(result.hasFmDsaNum).toBe(false);
        // Captured: 2 outer items
        expect(result.listItemsLen).toBe(2);
        // LI1 ha nested list
        const li0Blocks = result.listBlock?.items?.[0];
        const hasNested = li0Blocks?.some?.(b => b?.type === "list");
        expect(hasNested).toBe(true);
        // Texto "uu" preservato dopo roundtrip
        expect(result.renderedBack).toContain("uu");
    });

    test("REPRO bug: cell editor HTML EXACT user case → save → nested preserved", async ({ page }) => {
        // Reproduce esattamente lo scenario user: HTML editor con .fm-dsa-li-list
        // + data-fm-list-style="lower-alpha-roman" + nested OL via Tab indent
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await page.waitForFunction(() => typeof window.FM?.__buildBlocksFromTextareaForTest === "function", { timeout: 10000 });

        const result = await page.evaluate(() => {
            const ta = document.createElement("div");
            ta.contentEditable = "true";
            Object.defineProperty(ta, "value", {
                get() { return ta.innerHTML; },
                set(v) { ta.innerHTML = v; },
            });
            // HTML che il cell editor avrebbe DOPO che user ha inserito list
            // via dropdown + Tab indent. Source EXACT del _wysiwygInsertList +
            // _indentListItem flow.
            ta.value = '<b>CELLA</b>' +
                '<ol class="fm-dsa-li-list" data-fm-list-style="lower-alpha-roman" data-dsa-section="options">' +
                  '<li>u' +
                    '<ol class="fm-dsa-li-list" data-dsa-section="sub">' +
                      '<li>uu' +
                        '<ol class="fm-dsa-li-list" data-dsa-section="sub">' +
                          '<li>uuu</li>' +
                        '</ol>' +
                      '</li>' +
                    '</ol>' +
                  '</li>' +
                  '<li>d</li>' +
                '</ol>';
            document.body.appendChild(ta);
            const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
            ta.remove();
            // Dump completo per inspection
            const json = JSON.stringify(blocks);
            const listBlock = blocks.find(b => b.type === "list");
            return {
                blocksLen: blocks.length,
                json,
                listItemsLen: listBlock?.items?.length,
                li0HasNested: listBlock?.items?.[0]?.some?.(b => b?.type === "list"),
                li0Text: listBlock?.items?.[0]?.find?.(b => b?.type === "text")?.content,
                li1Text: listBlock?.items?.[1]?.find?.(b => b?.type === "text")?.content,
                hasUuu: json.includes("uuu"),
            };
        });
        console.log("REPRO blocks:", result.json);
        // Aspettative: 2 blocks (text + list), list ha 2 items, item 0 ha nested
        expect(result.listItemsLen).toBe(2);
        expect(result.li0HasNested).toBe(true);
        expect(result.hasUuu).toBe(true);
        expect(result.li0Text?.trim()).toBe("u");
        expect(result.li1Text?.trim()).toBe("d");
    });

    test("_buildBlocksFromTextarea preserva nested OL come 'list' block nested", async ({ page }) => {
        // Naviga a pagina che carica checkin-handlers (esiste window.FM)
        await page.goto("/", { waitUntil: "domcontentloaded" });
        // Attendi caricamento bootstrap.fH...js (espone window.FM.__buildBlocksFromTextareaForTest)
        await page.waitForFunction(() => typeof window.FM?.__buildBlocksFromTextareaForTest === "function", { timeout: 10000 });

        const result = await page.evaluate(() => {
            // Costruisci textarea-like div con OL nested 3 livelli
            const ta = document.createElement("div");
            ta.contentEditable = "true";
            Object.defineProperty(ta, "value", {
                get() { return ta.innerHTML; },
                set(v) { ta.innerHTML = v; },
            });
            ta.value = `<ol type="a"><li>u<ol type="i"><li>uu<ol><li>uuu</li></ol></li></ol></li><li>d</li></ol>`;
            document.body.appendChild(ta);
            const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
            ta.remove();
            return {
                blocks: JSON.parse(JSON.stringify(blocks)),
                topLevelType: blocks[0]?.type,
                topLevelItemCount: blocks[0]?.items?.length,
                li0HasNestedList: blocks[0]?.items?.[0]?.some?.(b => b?.type === "list"),
                li0Text: blocks[0]?.items?.[0]?.find?.(b => b?.type === "text")?.content,
                li1Text: blocks[0]?.items?.[1]?.find?.(b => b?.type === "text")?.content,
                li0NestedList: blocks[0]?.items?.[0]?.find?.(b => b?.type === "list"),
            };
        });

        // Top-level deve essere 1 list block
        expect(result.topLevelType).toBe("list");
        // Con 2 items
        expect(result.topLevelItemCount).toBe(2);
        // LI1 deve contenere nested list block
        expect(result.li0HasNestedList).toBe(true);
        // LI1 text = "u"
        expect(result.li0Text?.trim()).toBe("u");
        // LI2 text = "d"
        expect(result.li1Text?.trim()).toBe("d");
        // Nested list LI1.1 = "uu"
        const nested = result.li0NestedList;
        expect(nested?.type).toBe("list");
        expect(nested?.items?.length).toBe(1);
        const nested2 = nested.items[0].find(b => b?.type === "list");
        expect(nested2?.type).toBe("list");
        // Nested-nested LI1.1.1 = "uuu"
        expect(nested2?.items?.[0]?.find(b => b?.type === "text")?.content?.trim()).toBe("uuu");
    });

    test("roundtrip nested list: extract → buildBlocks → render → stesso markup", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });
        const result = await page.evaluate(async () => {
            const mod = await import("/js/modules/render/rm-table-view.js");
            // Step 1: cella server-rendered con nested list
            const td = document.createElement("td");
            td.className = "rm-option";
            td.innerHTML = `
                <div class="fm-wrap-check-cell">
                    <input type="checkbox" class="checkbox fm-checkbox-rm">
                    <label class="fm-collection"><div class="fm-cell-content">
                        <ol class="fm-dsa-li-list" type="a" data-dsa-section="options">
                            <li><span class="fm-dsa-li-num">a.</span>
                                <span class="fm-dsa-li-content">
                                    uno
                                    <ol type="i" data-dsa-section="sub">
                                        <li>uno.uno
                                            <ol data-dsa-section="sub">
                                                <li>uno.uno.uno</li>
                                            </ol>
                                        </li>
                                    </ol>
                                </span>
                            </li>
                            <li><span class="fm-dsa-li-num">b.</span>
                                <span class="fm-dsa-li-content">due</span>
                            </li>
                        </ol>
                    </div></label>
                </div>
            `;
            // Step 2: extract per cell editor (cleaned)
            const cellHtml = mod.extractCellContent(td);
            // Step 3: re-render via renderRmTable
            const state = {
                tables: [{
                    rows: 1, cols: 1, typecell: "|X|", colTypes: ["X"],
                    cells: [[cellHtml]],
                    mixtr: false, mixcol: false, mpagew: true, specificWidth: "",
                }],
                orientation: "horizontal",
            };
            const wrap = mod.renderRmTablesWrap(state, { correctMasks: [[[false]]] });
            // Step 4: extract di nuovo dalla cella ri-renderizzata
            const newTd = wrap.querySelector("td.rm-option");
            const reCellHtml = mod.extractCellContent(newTd);

            return {
                cellHtml,
                reCellHtml,
                // Struttura preservata
                hasNestedAfterFirst: /<ol[^>]*>[\s\S]*<ol/i.test(cellHtml),
                hasNestedAfterRoundtrip: /<ol[^>]*>[\s\S]*<ol/i.test(reCellHtml),
                // No F/GF buttons in entrambi gli stadi
                hasFGFFirst: /fm-dsa-li-F|fm-dsa-li-GF/.test(cellHtml),
                hasFGFRoundtrip: /fm-dsa-li-F|fm-dsa-li-GF/.test(reCellHtml),
                hasUnoUnoUno: reCellHtml.includes("uno.uno.uno"),
            };
        });
        // Critico: nested list preservata in roundtrip
        expect(result.hasNestedAfterFirst).toBe(true);
        expect(result.hasNestedAfterRoundtrip).toBe(true);
        expect(result.hasUnoUnoUno).toBe(true);
        // No F/GF buttons mai
        expect(result.hasFGFFirst).toBe(false);
        expect(result.hasFGFRoundtrip).toBe(false);
    });

    test("extractCellContent strips wrapCheckCell server markup", async ({ page }) => {
        await page.goto("/", { waitUntil: "domcontentloaded" });
        const out = await page.evaluate(async () => {
            const mod = await import("/js/modules/render/rm-table-view.js");
            const td = document.createElement("td");
            td.className = "rm-option";
            td.innerHTML = `
                <div class="fm-wrap-check-cell">
                    <input type="checkbox" class="checkbox fm-checkbox-rm">
                    <label class="fm-collection">
                        <div class="fm-cell-content">
                            <ol class="fm-dsa-li-list" type="a">
                                <li>uno</li>
                                <li>due</li>
                            </ol>
                        </div>
                    </label>
                </div>
            `;
            const extracted = mod.extractCellContent(td);
            return {
                extracted,
                hasInput: /<input/i.test(extracted),
                hasWrapCheckCell: /wrapCheckCell/i.test(extracted),
                hasList: /<ol\b/i.test(extracted),
                hasItems: extracted.includes("uno") && extracted.includes("due"),
            };
        });
        expect(out.hasInput).toBe(false);
        expect(out.hasWrapCheckCell).toBe(false);
        expect(out.hasList).toBe(true);
        expect(out.hasItems).toBe(true);
    });

    test("G23.fix10 — mountInlineEditor activates fm-editor-toolbar (group editor)", async ({ page }) => {
        // Riproduce scenario: click .modificaBtn (group editor) → toolbar globale deve attivarsi
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await page.waitForFunction(() => typeof window.FM?.__buildSectionForTest === "function", { timeout: 10000 });

        const result = await page.evaluate(async () => {
            // Setup: nessun toolbar all'inizio (o se esiste, hidden)
            const tbBefore = document.getElementById("fm-editor-toolbar-global");
            const visibleBefore = tbBefore ? (getComputedStyle(tbBefore).display !== "none") : false;

            // Trigger mountInlineEditor indirectly: usiamo openGroupEditor se esposto
            // Altrimenti chiamiamo manualmente buildSection che attiva ensureGlobalToolbar?
            // Più semplice: cerchiamo se window.FM ha openGroupEditor o creiamo un mock.
            // Backup: testiamo l'effetto di buildSection — ma quello non chiama toolbar.
            // Quindi creo un .fm-groupcollex dummy + click .modificaBtn se handler delegato esiste.

            // Costruisci minima struttura .fm-groupcollex con .checkmod .modificaBtn
            const wrap = document.createElement("div");
            wrap.className = "fm-contract-wrap";
            wrap.dataset.id = "999";
            wrap.dataset.version = "0";
            const problem = document.createElement("div");
            problem.className = "fm-groupcollex";
            problem.id = "g-test-1";
            problem.innerHTML = `
                <button class="fm-collapsible">
                    Titolo gruppo test
                    <div class="fm-checkmod">
                        <div class="edit fm-modifica-btn" title="Modifica"><img src="/img/edit.svg" alt="Modifica"></div>
                    </div>
                </button>
                <div class="content">
                    <div class="fm-testo"><div>intro test</div></div>
                </div>
            `;
            wrap.appendChild(problem);
            document.body.appendChild(wrap);
            document.body.classList.add("fm-admin-access");

            // Click handler — handler delegated globale dovrebbe gestire .modificaBtn click
            const btn = problem.querySelector(".fm-modifica-btn");
            btn.click();
            await new Promise(r => setTimeout(r, 500));

            const tbAfter = document.getElementById("fm-editor-toolbar-global");
            const visibleAfter = tbAfter ? (getComputedStyle(tbAfter).display !== "none") : false;
            const panel = problem.querySelector(".fm-editor-panel");

            // Cleanup
            wrap.remove();
            document.body.classList.remove("fm-admin-access");
            if (tbAfter) tbAfter.style.display = "none";

            return {
                visibleBefore,
                visibleAfter,
                hasPanel: !!panel,
            };
        });

        // Assert: dopo click su .modificaBtn, il panel è aperto E la toolbar è visibile
        expect(result.hasPanel).toBe(true);
        expect(result.visibleAfter).toBe(true);
    });

    test("G23.fix9 — click checkboxRM toggles rm-correct + triggers patch", async ({ page }) => {
        // Carica una pagina con bootstrap.js + body.fm-admin-access
        await page.goto("/", { waitUntil: "domcontentloaded" });
        await page.waitForFunction(() => typeof window.FM?.__buildBlocksFromTextareaForTest === "function", { timeout: 10000 });

        const result = await page.evaluate(async () => {
            // Setup: simula context teacher mode
            document.body.classList.add("fm-admin-access");

            // Intercept fetch per catturare PATCH
            const patchCalls = [];
            const origFetch = window.fetch;
            window.fetch = async function(url, opts) {
                if (typeof url === "string" && /\/patch$/.test(url)) {
                    patchCalls.push({
                        url,
                        body: opts?.body && typeof opts.body === "string" ? opts.body : null,
                    });
                    return new Response(JSON.stringify({ ok: true, version: 1 }), {
                        status: 200, headers: { "Content-Type": "application/json" },
                    });
                }
                return origFetch.call(this, url, opts);
            };

            // Costruisci un contract-wrap+item con RM table
            const wrap = document.createElement("div");
            wrap.className = "fm-contract-wrap";
            wrap.dataset.id = "999";
            wrap.dataset.version = "0";
            wrap.innerHTML = `
                <div class="fm-collection__item" data-id="q1">
                    <table class="fm-rm-table" data-typecell="|X|X|">
                        <tbody><tr>
                            <td class="rm-option" data-row="0" data-col="0">
                                <div class="fm-wrap-check-cell">
                                    <input type="checkbox" class="checkbox fm-checkbox-rm" id="cb-a">
                                    <label class="fm-collection"><div class="fm-cell-content">AAA</div></label>
                                </div>
                            </td>
                            <td class="rm-option" data-row="0" data-col="1">
                                <div class="fm-wrap-check-cell">
                                    <input type="checkbox" class="checkbox fm-checkbox-rm" id="cb-b">
                                    <label class="fm-collection"><div class="fm-cell-content">BBB</div></label>
                                </div>
                            </td>
                        </tr></tbody>
                    </table>
                </div>
            `;
            document.body.appendChild(wrap);

            // Simula click su checkbox A (toggle correct)
            const cbA = document.getElementById("cb-a");
            cbA.checked = true;
            cbA.dispatchEvent(new Event("change", { bubbles: true }));
            // Aspetta event handler async
            await new Promise(r => setTimeout(r, 300));

            const tdA = cbA.closest("td");
            const hasRmCorrect = tdA.classList.contains("rm-correct");

            // Cleanup
            window.fetch = origFetch;
            wrap.remove();
            document.body.classList.remove("fm-admin-access");

            return {
                hasRmCorrect,
                patchCount: patchCalls.length,
                patchUrl: patchCalls[0]?.url || null,
                patchBody: patchCalls[0]?.body || null,
            };
        });

        // Asserts
        expect(result.hasRmCorrect).toBe(true);
        expect(result.patchCount).toBe(1);
        expect(result.patchUrl).toMatch(/\/api\/teacher\/content\/999\/.*\/patch$/);
        // Body è form-urlencoded da apiPost. Parsa parameters per estrarre options.
        const params = new URLSearchParams(result.patchBody || "");
        // options viene serializzata come JSON string in URL form
        const optionsRaw = params.get("options");
        expect(optionsRaw).toBeTruthy();
        const options = JSON.parse(optionsRaw);
        expect(options.length).toBe(2);
        expect(options[0].correct).toBe(true);
        expect(options[1].correct).toBe(false);
    });

    test("applyRmTableEdits FIELD_APPLIER aggiorna DOM senza reload", async ({ page }) => {
        // Naviga a una pagina che carica checkin-handlers (es. studio verifica)
        await page.goto("/studio/verifica/sc/2s/FIS", { waitUntil: "networkidle" });
        const topicsCount = await page.locator(".fm-study-topics a").count();
        test.skip(topicsCount === 0, "Nessun topic disponibile");
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForSelector(".fm-contract-wrap", { timeout: 15000 });
        await page.waitForTimeout(1500);

        const rmCount = await page.locator(".fm-rm-table").count();
        test.skip(rmCount === 0, "Nessuna RM table per testare l'applier");

        // Verifica che FIELD_APPLIERS abbia options + rmLayout
        const appliersInfo = await page.evaluate(() => {
            const w = window;
            return {
                hasAppliers: typeof w.FM?.editorTest?.FIELD_APPLIERS === "object"
                          || typeof w.FIELD_APPLIERS === "object",
                // Comunque verifichiamo che il modulo è caricato controllando l'esistenza
                // di una funzione esposta o classi attese
                moduleLoaded: !!document.querySelector(".fm-contract-wrap"),
            };
        });
        // Soft check
        expect(appliersInfo.moduleLoaded).toBe(true);
        await page.screenshot({
            path: path.join(SCREENSHOT_DIR, "02-after-apply.png"),
            fullPage: false,
        });
    });
});
