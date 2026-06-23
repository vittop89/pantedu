/**
 * Tab/Shift+Tab/Enter handlers per editor wysiwyg liste:
 *   - Tab dentro <li> (anche caret a inizio): indent → sub-list
 *   - Shift+Tab dentro <li>: outdent → esce della parent ol/ul
 *   - Enter su <li> vuoto: outdent (exit list)
 *   - Tab fuori da lista: insert "    " (4 spazi)
 *   - Conversione TEX preserva nesting → \begin{enumerate} > \begin{enumerate}
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
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
}

test("Tab dentro <li>: indent → sub-list nested attaccata al li precedente", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = '<ol class="fm-dsa-li-list" data-dsa-section="question"><li>A</li><li id="li-target">B</li></ol>';
        const li = field.querySelector("#li-target");
        // Caret all'inizio di B
        const range = document.createRange();
        range.setStart(li.firstChild, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__indentListItemForTest(li);

        return {
            html: field.innerHTML,
            structure: {
                outerLis: field.querySelectorAll(":scope > ol > li").length,
                hasNestedOl: !!field.querySelector("ol > li > ol"),
                nestedLisText: Array.from(field.querySelectorAll("ol > li > ol > li")).map((l) => l.textContent),
            },
        };
    });
    expect(r.structure.outerLis, "outer ol ora ha 1 li (A); B è nested").toBe(1);
    expect(r.structure.hasNestedOl, "nested ol creata").toBe(true);
    expect(r.structure.nestedLisText, "li B nella nested ol").toEqual(["B"]);
});

test("Tab senza previous-sibling: no-op (browser convention)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = '<ol class="fm-dsa-li-list" data-dsa-section="question"><li id="li-first">solo</li></ol>';
        const li = field.querySelector("#li-first");
        window.FM.__indentListItemForTest(li);
        return field.innerHTML;
    });
    expect(r, "html invariato (no previous sibling)").toContain('<li id="li-first">solo</li>');
    // Verifica struttura: nessun li annidato dentro un altro li
    const hasNestedOl = await page.evaluate(() => {
        return !!document.querySelector(".fm-editor-field li ol");
    });
    expect(hasNestedOl, "no nested ol creata").toBe(false);
});

test("Shift+Tab in li nested: outdent → li sibling del nonno-li", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = `<ol class="fm-dsa-li-list" data-dsa-section="question">
            <li>A
                <ol class="fm-dsa-li-list">
                    <li id="li-nested">B nested</li>
                </ol>
            </li>
            <li>C</li>
        </ol>`;
        const li = field.querySelector("#li-nested");
        window.FM.__outdentListItemForTest(li, field);

        // Atteso: ol top-level ha 3 li: A, B nested, C (B esce da nested)
        const topLis = Array.from(field.querySelectorAll(":scope > ol > li"));
        return {
            topLisCount: topLis.length,
            topLisOrder: topLis.map((l) => l.id || l.textContent.trim().slice(0, 5)),
            hasNestedOl: !!field.querySelector("ol > li > ol"),
        };
    });
    expect(r.topLisCount, "top-level ol ha 3 li (A, B, C)").toBe(3);
    expect(r.topLisOrder[1], "li outdented al posto giusto (sibling A)").toBe("li-nested");
    expect(r.hasNestedOl, "nested ol rimossa (era empty)").toBe(false);
});

test("Enter su <li> VUOTO: outdent + rimuovi il li (exit list)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = '<ol class="fm-dsa-li-list" data-dsa-section="question"><li>A</li><li id="li-empty"></li></ol>';
        const li = field.querySelector("#li-empty");
        // Caret in li vuoto
        const range = document.createRange();
        range.selectNodeContents(li);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        // Simula Enter su li vuoto
        window.FM.__outdentListItemForTest(li, field, true);

        return {
            html: field.innerHTML,
            topLisAfter: field.querySelectorAll("ol > li").length,
            hasEmptyLi: !!field.querySelector("ol > li:empty"),
        };
    });
    expect(r.topLisAfter, "ol ha 1 li (A); il li vuoto rimosso").toBe(1);
    expect(r.hasEmptyLi, "no empty li residuo").toBe(false);
    // Field ora ha la <ol> + un <div> sotto (per caret post-exit)
    expect(r.html, "div post-list creato per caret").toMatch(/<div>/);
});

test("Tab triggered via keydown: indent funziona end-to-end", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(async () => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = '<ol class="fm-dsa-li-list" data-dsa-section="question"><li>A</li><li id="li-target">B</li></ol>';
        const li = field.querySelector("#li-target");
        // Caret start of B
        const range = document.createRange();
        range.setStart(li.firstChild, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        // Dispatch Tab keydown
        const ev = new KeyboardEvent("keydown", { key: "Tab", bubbles: true, cancelable: true });
        field.dispatchEvent(ev);
        await new Promise((r) => setTimeout(r, 50));

        return {
            preventedDefault: ev.defaultPrevented,
            hasNestedOl: !!field.querySelector("ol > li > ol"),
        };
    });
    expect(r.preventedDefault, "Tab default prevenuto").toBe(true);
    expect(r.hasNestedOl, "nested ol creata via Tab keydown").toBe(true);
});

test("Conversione TEX preserva nesting dopo Tab indent", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const ta = document.createElement("textarea");
        ta.dataset.field = "quesito";
        document.body.appendChild(ta);
        // Setup HTML con lista nested 2 livelli (come dopo Tab indent)
        ta.value = `<ol class="fm-dsa-li-list" data-dsa-section="question"><li>A<ol class="fm-dsa-li-list"><li>nested-1</li><li>nested-2</li></ol></li><li>C</li></ol>`;
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const list = blocks.find((b) => b.type === "list");
        // Verifica struttura nested in blocks
        const firstItemBlocks = list?.items?.[0] || [];
        const nestedListInItem = firstItemBlocks.find((b) => b.type === "list");
        return {
            outerListItems: list?.items?.length || 0,
            firstItemHasNestedList: !!nestedListInItem,
            nestedListItems: nestedListInItem?.items?.length || 0,
        };
    });
    expect(r.outerListItems, "outer ol ha 2 items").toBe(2);
    expect(r.firstItemHasNestedList, "primo item contiene block list nested").toBe(true);
    expect(r.nestedListItems, "nested ha 2 items").toBe(2);
});
