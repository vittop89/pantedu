/**
 * Feature toolbar editor:
 *  1. SOL "..." → inserisce <span class="dots">
 *  2. DSA → inserisce <span class="fm-add-text-dsa">
 *  3. 🔗 → inserisce <a href="..."> ELEMENT (no testo)
 *  4. Bold/italic/underline preservati post buildBlocks → toHtml
 *  5. Sanitizer: <a>, <b>, <i>, <u>, span.dots → \href / \textbf / \textit / \underline / \underline
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

test("Bold/italic/underline preservati in _buildBlocksFromTextarea → _toHtml", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const ta = document.createElement("div");
        ta.contentEditable = "true";
        ta.dataset.field = "quesito";
        ta.innerHTML = 'Testo con <b>grassetto</b> e <i>corsivo</i> e <u>sottolineato</u> e <a href="https://x.it">link</a>';
        Object.defineProperty(ta, "value", {
            get() { return this.innerHTML; },
        });
        document.body.appendChild(ta);
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const html = window.FM.__toHtmlForTest(blocks);
        return { blocks, html };
    });
    // Block.content deve contenere il markup HTML inline (non strippato)
    const textBlock = r.blocks.find((b) => b.type === "text");
    expect(textBlock?.content, "<b> preservato in content").toContain("<b>grassetto</b>");
    expect(textBlock?.content, "<i> preservato").toContain("<i>corsivo</i>");
    expect(textBlock?.content, "<u> preservato").toContain("<u>sottolineato</u>");
    expect(textBlock?.content, "<a> preservato").toContain("<a href=");
    // _toHtml mostra HTML rendered (NON escapato come testo)
    expect(r.html, "_toHtml emette <b> rendered").toMatch(/<b>grassetto<\/b>/);
});

test("Find/replace dialog si apre con flag regex/case/word/insel", async ({ page }) => {
    await login(page);
    const flags = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "ciao Ciao CIAO";
        field.focus();
        if (typeof window.FM?.__openFindReplaceDialogForTest !== "function") return { error: "not exposed" };
        window.FM.__openFindReplaceDialogForTest({ _focusedTextarea: field });
        const dlg = document.getElementById("fm-findreplace-dialog");
        return {
            hasDialog: !!dlg,
            optBtns: Array.from(dlg?.querySelectorAll(".fm-fr-opt") || []).map((b) => b.dataset.opt),
            hasFindInp: !!dlg?.querySelector(".fm-fr-find"),
            hasReplaceInp: !!dlg?.querySelector(".fm-fr-replace"),
            hasPrevBtn: !!dlg?.querySelector(".fm-fr-prev"),
            hasNextBtn: !!dlg?.querySelector(".fm-fr-next"),
        };
    });
    expect(flags.hasDialog).toBe(true);
    expect(flags.optBtns).toEqual(expect.arrayContaining(["case", "word", "regex", "insel"]));
    expect(flags.hasFindInp).toBe(true);
    expect(flags.hasReplaceInp).toBe(true);
    expect(flags.hasPrevBtn).toBe(true);
    expect(flags.hasNextBtn).toBe(true);
});

test("Find dialog pre-fill con selezione corrente del field", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "test selezionato";
        field.focus();
        // Seleziona "selezionato"
        const range = document.createRange();
        range.setStart(field.firstChild, 5);
        range.setEnd(field.firstChild, 16);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        window.FM.__openFindReplaceDialogForTest({ _focusedTextarea: field });
        const findInp = document.querySelector("#fm-findreplace-dialog .fm-fr-find");
        return findInp?.value || "";
    });
    expect(r).toBe("selezionato");
});
