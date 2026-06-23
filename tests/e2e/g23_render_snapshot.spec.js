/**
 * G23 — Screenshot snapshot del renderer RM client per validazione visiva.
 *
 * Test sintetico: usa `renderRmTablesWrap()` direttamente in browser per
 * generare uno screenshot di tutti i 5 tipi colonna (X/V/B/T/N) in una
 * tabella di esempio. Output: `tests/e2e/screenshots/g23/rm-types-grid.png`.
 */
const { test, expect } = require("@playwright/test");
const path = require("path");

test("G23 — snapshot visual dei tipi colonna RM (X/V/B/T/N)", async ({ page }) => {
    // Pagina vuota con CSS dell'app caricato
    await page.goto("/");
    await page.setViewportSize({ width: 1200, height: 600 });

    // Inietta il modulo e renderizza una tabella demo
    const result = await page.evaluate(async () => {
        const mod = await import("/js/modules/render/rm-table-view.js");
        const state = {
            tables: [{
                rows: 2, cols: 5,
                typecell: "|X|V|B|T|N|",
                colTypes: ["X", "V", "B", "T", "N"],
                cells: [
                    ["Cella X1", "Cella V1", "Cella B1", "Cella T1", "Cella N1"],
                    ["<b>X2</b> bold", "<i>V2</i> italic", "B2", "T2", "N2"],
                ],
                mixtr: false, mixcol: false, mpagew: true, specificWidth: "",
            }],
            orientation: "horizontal",
        };
        const wrap = mod.renderRmTablesWrap(state, {
            correctMasks: [[[false, true, false, false, false], [true, false, false, false, false]]],
        });
        // Style minimal per visibility (no app CSS dipendency)
        wrap.style.background = "#fff";
        wrap.style.padding = "20px";
        wrap.style.fontFamily = "system-ui, sans-serif";
        wrap.style.fontSize = "14px";
        // Pulisci la pagina e mostra solo wrap
        document.body.innerHTML = "";
        document.body.style.margin = "0";
        document.body.style.padding = "0";
        document.body.style.background = "#f5f5f5";
        document.body.appendChild(wrap);

        // Stats per assert
        return {
            wrapCheckCells: wrap.querySelectorAll(".fm-wrap-check-cell").length,
            checkbox: wrap.querySelectorAll('input[type="checkbox"]').length,
            radio:    wrap.querySelectorAll('input[type="radio"]').length,
            button:   wrap.querySelectorAll('button.fm-rm-btn').length,
            textIn:   wrap.querySelectorAll('input.fm-rm-text').length,
            numIn:    wrap.querySelectorAll('input.fm-rm-num').length,
            rmCorrect: wrap.querySelectorAll('.rm-correct').length,
            rmLetters: wrap.querySelectorAll('.rm-letter').length,
        };
    });

    // 2 righe × 5 col = 10 wrapCheckCell totali
    expect(result.wrapCheckCells).toBe(10);
    // X (col 0): 2 checkbox, V (col 1): 2 radio, B (col 2): 2 button, T (col 3): 2 text, N (col 4): 2 number
    expect(result.checkbox).toBe(2);
    expect(result.radio).toBe(2);
    expect(result.button).toBe(2);
    expect(result.textIn).toBe(2);
    expect(result.numIn).toBe(2);
    // 2 celle correct (col V row 0 + col X row 1)
    expect(result.rmCorrect).toBe(2);
    // G23 critical: NO rm-letter
    expect(result.rmLetters).toBe(0);

    await page.screenshot({
        path: path.join("tests/e2e/screenshots/g23", "rm-types-XVBTN-grid.png"),
        fullPage: false,
    });
});

test("G23 — snapshot legacy markup → extracted content", async ({ page }) => {
    await page.goto("/");
    await page.setViewportSize({ width: 1000, height: 400 });

    const html = await page.evaluate(async () => {
        const mod = await import("/js/modules/render/rm-table-view.js");
        // Simula 3 markup diversi side-by-side
        const container = document.createElement("div");
        container.style.cssText = "display:grid;grid-template-columns:1fr 1fr 1fr;gap:20px;padding:20px;background:#f5f5f5;font-family:system-ui";

        // 1. Server moderno (.wrapCheckCell)
        const c1 = document.createElement("div");
        c1.innerHTML = `<h3>Server moderno</h3><table border="1" style="background:#fff"><tr><td class="rm-option">
            <div class="fm-wrap-check-cell">
                <input type="checkbox">
                <label class="fm-collection"><div class="fm-cell-content">
                    <ol type="a"><li>uno</li><li>due</li></ol>
                </div></label>
            </div>
        </td></tr></table>`;
        const td1 = c1.querySelector("td");
        const ex1 = mod.extractCellContent(td1);
        c1.innerHTML += `<pre style="font:11px monospace;background:#fffacc;padding:4px;white-space:pre-wrap">extracted:\n${ex1.substring(0, 100)}</pre>`;

        // 2. Client legacy (.rm-letter)
        const c2 = document.createElement("div");
        c2.innerHTML = `<h3>Client legacy</h3><table border="1" style="background:#fff"><tr><td class="rm-option">
            <span class="rm-letter">a.</span>
            <label class="rm-pick-choice"><input type="checkbox"></label>
            Contenuto cella
        </td></tr></table>`;
        const td2 = c2.querySelector("td");
        const ex2 = mod.extractCellContent(td2);
        c2.innerHTML += `<pre style="font:11px monospace;background:#fffacc;padding:4px">extracted:\n${ex2}</pre>`;

        // 3. Plain
        const c3 = document.createElement("div");
        c3.innerHTML = `<h3>Plain</h3><table border="1" style="background:#fff"><tr><td class="rm-option">Solo testo</td></tr></table>`;
        const td3 = c3.querySelector("td");
        const ex3 = mod.extractCellContent(td3);
        c3.innerHTML += `<pre style="font:11px monospace;background:#fffacc;padding:4px">extracted:\n${ex3}</pre>`;

        container.appendChild(c1);
        container.appendChild(c2);
        container.appendChild(c3);
        document.body.innerHTML = "";
        document.body.style.margin = "0";
        document.body.appendChild(container);

        return { ex1, ex2, ex3 };
    });

    // Assert: extractCellContent strippa input + wrap, preserva content
    expect(html.ex1).not.toContain("<input");
    expect(html.ex1).not.toContain("wrapCheckCell");
    expect(html.ex1).toContain("<ol");
    expect(html.ex1).toContain("uno");
    expect(html.ex1).toContain("due");

    expect(html.ex2).not.toContain("<input");
    expect(html.ex2).not.toContain("rm-letter");
    expect(html.ex2).toContain("Contenuto cella");

    expect(html.ex3).toBe("Solo testo");

    await page.screenshot({
        path: path.join("tests/e2e/screenshots/g23", "rm-extract-3-markups.png"),
        fullPage: false,
    });
});
