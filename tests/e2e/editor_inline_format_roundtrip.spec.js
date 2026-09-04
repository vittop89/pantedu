/**
 * Roundtrip Bold/Italic/Underline:
 *   1. Inserisce <b>/<i>/<u> via _wrapAsElement (Bold button)
 *   2. Verifica innerHTML contiene il tag
 *   3. _buildBlocksFromTextarea(ta) → block.content deve PRESERVARE i tag
 *   4. _toHtml(blocks) → HTML deve renderizzare bold/italic/underline visibile
 *
 * Trace il bug riportato: "post-save reload, formattazione persa".
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

test("Bold <b>: innerHTML→block.content→_toHtml preserva tag", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");

        // Step 1: simula click Bold → inserisce <b>X</b>
        ta.innerHTML = "Testo <b>grassetto</b> normale";

        // Step 2: _buildBlocksFromTextarea
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);

        // Step 3: _toHtml roundtrip
        const html = window.FM.__toHtmlForTest(blocks);

        return {
            innerHTML: ta.innerHTML,
            blocks,
            html,
            blockContent: blocks[0]?.content || "",
        };
    });
    expect(r.innerHTML, "innerHTML deve avere <b>").toContain("<b>grassetto</b>");
    expect(r.blockContent, "block.content deve PRESERVARE <b>").toContain("<b>grassetto</b>");
    expect(r.html, "_toHtml output deve emettere <b> raw").toContain("<b>grassetto</b>");
    // _toHtml NON deve escape (`&lt;b&gt;` solo in data-raw attr)
    const visiblePart = r.html.replace(/data-raw="[^"]*"/g, "");
    expect(visiblePart).not.toContain("&lt;b&gt;");
});

test("Italic <i>: roundtrip preserva tag", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "Testo <i>corsivo</i>";
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const html = window.FM.__toHtmlForTest(blocks);
        return { blocks, html, blockContent: blocks[0]?.content || "" };
    });
    expect(r.blockContent).toContain("<i>corsivo</i>");
    expect(r.html).toContain("<i>corsivo</i>");
});

test("Underline <u>: roundtrip preserva tag", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<u>sotto</u>";
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const html = window.FM.__toHtmlForTest(blocks);
        return { blocks, html, blockContent: blocks[0]?.content || "" };
    });
    expect(r.blockContent).toContain("<u>sotto</u>");
    expect(r.html).toContain("<u>sotto</u>");
});

test("Mix B+I+U: tutti tag preservati post-roundtrip", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "A <b>b</b> e <i>c</i> e <u>d</u> end";
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const html = window.FM.__toHtmlForTest(blocks);
        return { blocks, html, blockContent: blocks[0]?.content || "" };
    });
    expect(r.blockContent).toContain("<b>b</b>");
    expect(r.blockContent).toContain("<i>c</i>");
    expect(r.blockContent).toContain("<u>d</u>");
    expect(r.html).toContain("<b>b</b>");
    expect(r.html).toContain("<i>c</i>");
    expect(r.html).toContain("<u>d</u>");
});

test("wrapSnippet('<b>','</b>') con selezione → wrap in <b> visibile", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        ta.innerHTML = "Testo grassetto qui";
        // Seleziona "grassetto"
        const textNode = ta.firstChild;
        const range = document.createRange();
        range.setStart(textNode, 6);
        range.setEnd(textNode, 15); // "grassetto"
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        window.FM.__wrapSnippetForTest(panel, "<b>", "</b>");
        return { innerHTML: ta.innerHTML };
    });
    expect(r.innerHTML, "selezione wrappata in <b>").toContain("<b>grassetto</b>");
});

test("wrapSnippet collapsed → typed char STAYS dentro <b> (ZWS placeholder)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        ta.innerHTML = "abc";
        const textNode = ta.firstChild;
        const range = document.createRange();
        range.setStart(textNode, 3);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        window.FM.__wrapSnippetForTest(panel, "<b>", "</b>");
        // Simula tipo "X" all'interno (caret è dopo ZWS dentro <b>)
        const sel2 = window.getSelection();
        const r2 = sel2.getRangeAt(0);
        r2.insertNode(document.createTextNode("X"));

        // Verifica: <b> esiste e contiene X (NON FUORI)
        const bEl = ta.querySelector("b");
        const xInsideB = bEl && bEl.textContent.includes("X");

        // Verifica strip ZWS via _buildBlocksFromTextarea
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const cleanContent = blocks[0]?.content || "";
        return { xInsideB, cleanContent, hasZws: /​/.test(cleanContent) };
    });
    expect(r.xInsideB, "X deve restare DENTRO <b>").toBe(true);
    expect(r.cleanContent, "block.content deve avere <b>X</b> pulito").toContain("<b>X</b>");
    expect(r.hasZws, "ZWS deve essere strippato a save time").toBe(false);
});

test("Reload pipeline: ContractRenderer .fm-collection → _extractRawWithTikz preserva <b>/<i>/<u>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        // Simula HTML server-rendered come ContractRenderer emette dopo
        // save+reload: <span class="fm-text" data-raw="..."> con tag inline raw.
        const collex = document.createElement("div");
        collex.className = "fm-collection";
        collex.innerHTML = '<span class="fm-text" data-raw="aaa &lt;b&gt;X&lt;/b&gt; bbb &lt;i&gt;Y&lt;/i&gt; ccc &lt;u&gt;Z&lt;/u&gt; end">aaa <b>X</b> bbb <i>Y</i> ccc <u>Z</u> end</span>';
        document.body.appendChild(collex);
        const raw = window.FM.__extractRawWithTikzForTest(collex);
        return { raw };
    });
    expect(r.raw, "extract preserva <b>").toContain("<b>X</b>");
    expect(r.raw, "extract preserva <i>").toContain("<i>Y</i>");
    expect(r.raw, "extract preserva <u>").toContain("<u>Z</u>");
});

test("Helper _updateInlineFormatBtnState: rileva ancestor <b>/<i>/<u>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "pre <b>X</b> <i>Y</i> <u>Z</u> end";
        window.__fmFocusedTA = ta;

        const bBtn = document.createElement("button"); bBtn.className = "tt-b";
        const iBtn = document.createElement("button"); iBtn.className = "tt-i";
        const uBtn = document.createElement("button"); uBtn.className = "tt-u";

        const check = (selector) => {
            const el = ta.querySelector(selector);
            const range = document.createRange();
            range.setStart(el.firstChild, 0); range.collapse(true);
            const sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(range);
            // chiama helper interno esposto via window per test
            window.FM.__updateInlineFormatBtnStateForTest(bBtn, iBtn, uBtn);
            return {
                b: bBtn.classList.contains("fm-fmtbtn-active"),
                i: iBtn.classList.contains("fm-fmtbtn-active"),
                u: uBtn.classList.contains("fm-fmtbtn-active"),
            };
        };
        return { inB: check("b"), inI: check("i"), inU: check("u") };
    });
    expect(r.inB.b, "caret in <b> → b active").toBe(true);
    expect(r.inB.i).toBe(false);
    expect(r.inI.i, "caret in <i> → i active").toBe(true);
    expect(r.inI.b, "caret in <i> → b NON active").toBe(false);
    expect(r.inU.u, "caret in <u> → u active").toBe(true);
});

test("Enter → <div> wrappers: tag inline DENTRO div preservati", async ({ page }) => {
    // Bug riprodotto dall'utente: in editor "aaa"(b)\n"bbb"(i)\n"ccc"(u)
    // dopo Enter Chrome crea <div><b>aaa</b></div><div><i>bbb</i></div>...
    // Prima del fix: textContent del div perdeva <i>/<u>, solo <b> al top-level
    // sopravviveva.
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        // Simula esattamente come Chrome rappresenta Enter in contenteditable
        ta.innerHTML = '<b>aaa</b><div><i>bbb</i></div><div><u>ccc</u></div><div><b><i>iii</i></b></div>';
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const content = blocks[0]?.content || "";
        return { content, blocks };
    });
    expect(r.content, "<b>aaa</b> top-level preservato").toContain("<b>aaa</b>");
    expect(r.content, "<i>bbb</i> dentro div preservato").toContain("<i>bbb</i>");
    expect(r.content, "<u>ccc</u> dentro div preservato").toContain("<u>ccc</u>");
    expect(r.content, "<b><i>iii</i></b> nested preservato").toMatch(/<b>\s*<i>iii<\/i>\s*<\/b>/);
});

test("Enter → <p> wrappers (Firefox-style): tag inline preservati", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = '<p><b>X</b></p><p><i>Y</i></p>';
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        return { content: blocks[0]?.content || "" };
    });
    expect(r.content).toContain("<b>X</b>");
    expect(r.content).toContain("<i>Y</i>");
});

test("Toggle B: selezione FULL su <b> → unwrap (remove formato)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "pre <b>BOLD</b> post";
        window.__fmFocusedTA = ta;

        // Seleziona INTERAMENTE "BOLD" (dentro <b>)
        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, 0);
        range.setEnd(bEl.firstChild, 4);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");
        return { html: ta.innerHTML.replace(/​/g, "[ZWS]") };
    });
    expect(r.html, "<b> rimosso").not.toContain("<b>");
    expect(r.html, "testo BOLD preservato come plain").toContain("BOLD");
});

test("Toggle B: selezione PARZIALE dentro <b> → split", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b>aaaXXXbbb</b>";
        window.__fmFocusedTA = ta;

        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, 3); // "aaa|XXXbbb"
        range.setEnd(bEl.firstChild, 6);   // "aaaXXX|bbb"
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");
        return { html: ta.innerHTML };
    });
    expect(r.html, "split: <b>aaa</b>XXX<b>bbb</b>").toMatch(/<b>aaa<\/b>\s*XXX\s*<b>bbb<\/b>/);
});

test("Toggle B: caret IN MEZZO a <b>aaa|bbb</b> → split, aaa resta bold", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b>aaabbb</b>";
        window.__fmFocusedTA = ta;

        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, 3); // caret tra aaa|bbb
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");

        // Simula typing "X" al caret
        const sel2 = window.getSelection();
        const r2 = sel2.getRangeAt(0);
        r2.insertNode(document.createTextNode("X"));
        return { html: ta.innerHTML.replace(/​/g, "") };
    });
    // Atteso: <b>aaa</b>X<b>bbb</b> — "aaa" e "bbb" restano bold, X NON bold
    expect(r.html, "aaa resta bold").toMatch(/<b>aaa<\/b>/);
    expect(r.html, "bbb resta bold").toMatch(/<b>bbb<\/b>/);
    expect(r.html, "X fuori dai <b>").toMatch(/<\/b>X<b>/);
});

test("Toggle B: caret a FINE di <b>aaa|</b> → exit, typing fuori non-bold", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b>aaa</b>";
        window.__fmFocusedTA = ta;

        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, 3); // caret a fine "aaa"
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");

        const sel2 = window.getSelection();
        const r2 = sel2.getRangeAt(0);
        r2.insertNode(document.createTextNode("Y"));
        return { html: ta.innerHTML.replace(/​/g, "") };
    });
    // Atteso: <b>aaa</b>Y — aaa resta bold, Y fuori
    expect(r.html, "aaa resta bold").toContain("<b>aaa</b>");
    expect(r.html, "Y fuori dal <b>").toMatch(/<\/b>Y/);
    expect(r.html, "Y NON dentro <b>").not.toMatch(/<b>[^<]*Y/);
});

test("Toggle B: caret a INIZIO di <b>|aaa</b> → exit prima, typing fuori non-bold", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b>aaa</b>";
        window.__fmFocusedTA = ta;

        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, 0); // caret a inizio
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");
        return { html: ta.innerHTML.replace(/​/g, "") };
    });
    // Atteso: ZWS + <b>aaa</b> (caret pre-aaa, fuori da <b>)
    expect(r.html, "aaa resta bold").toContain("<b>aaa</b>");
});

test("Wrap selezione: selezione PRESERVATA su content del nuovo <b>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "hello world";
        window.__fmFocusedTA = ta;

        const range = document.createRange();
        range.setStart(ta.firstChild, 0);
        range.setEnd(ta.firstChild, 5); // "hello"
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");
        // Selezione dovrebbe essere ancora attiva e coprire "hello" dentro <b>
        const sel2 = window.getSelection();
        const selectedText = sel2.toString();
        // Chain: applica anche italic
        window.FM.__toggleInlineFormatForTest(panel, "i");
        return { html: ta.innerHTML, selectedText };
    });
    expect(r.selectedText, "selezione preservata = 'hello'").toBe("hello");
    expect(r.html, "chain bold→italic: <b><i>hello</i></b>").toMatch(/<b><i>hello<\/i><\/b>/);
});

test("Toggle B: selezione SENZA <b> → applica wrap", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "plain text";
        window.__fmFocusedTA = ta;

        const range = document.createRange();
        range.setStart(ta.firstChild, 0);
        range.setEnd(ta.firstChild, 5); // "plain"
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "b");
        return { html: ta.innerHTML };
    });
    expect(r.html, "wrap applicato: <b>plain</b>").toContain("<b>plain</b>");
});

test("Toggle I/U: alias strong/em riconosciuti come b/i", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        // <em>Y</em>: toggle i deve riconoscerlo come italic (alias)
        ta.innerHTML = "<em>Y</em>";
        window.__fmFocusedTA = ta;
        const em = ta.querySelector("em");
        const range = document.createRange();
        range.setStart(em.firstChild, 0);
        range.setEnd(em.firstChild, 1);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();
        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "i");
        return { html: ta.innerHTML };
    });
    expect(r.html, "<em> alias di <i>: rimosso").not.toContain("<em>");
    expect(r.html).toContain("Y");
});

test("Copy: selezione di testo DENTRO <u>ccc</u> include <u> wrapper", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "pre <u>ccc</u> post";
        window.__fmFocusedTA = ta;

        // Seleziona "ccc" (interno del text node dentro <u>)
        const uEl = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(uEl.firstChild, 0);
        range.setEnd(uEl.firstChild, 3);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        // Trigger copy via clipboard event
        let copiedHtml = "";
        const original = window.getSelection;
        const ev = new Event("copy", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: {
                _data: {},
                setData(type, val) { this._data[type] = val; },
                getData(type) { return this._data[type]; },
            },
            writable: false,
        });
        ta.dispatchEvent(ev);
        copiedHtml = ev.clipboardData.getData("text/html") || "";
        return { copiedHtml };
    });
    expect(r.copiedHtml, "copy include <u>ccc</u>").toContain("<u>ccc</u>");
});

test("Paste: strip commenti HTML (StartFragment/EndFragment) + tag vuoti", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "before |";
        window.__fmFocusedTA = ta;
        // Posiziona caret a fine
        const range = document.createRange();
        range.selectNodeContents(ta);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        // Simula paste con HTML "sporco" da Office clipboard
        const dirty = '<!--StartFragment--><u>ccc</u><b>​</b><!--EndFragment-->';
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: {
                getData(type) { return type === "text/html" ? dirty : "ccc"; },
            },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML.replace(/​/g, "") };
    });
    expect(r.html, "no commenti HTML").not.toMatch(/<!--/);
    expect(r.html, "no <b> vuoti orfani").not.toMatch(/<b>\s*<\/b>/);
    expect(r.html, "<u>ccc</u> preservato").toContain("<u>ccc</u>");
});

test("_buildBlocksFromTextarea: strip tag inline vuoti residuali", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "aaa <b></b> bbb <i>  </i> ccc";
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        return { content: blocks[0]?.content || "" };
    });
    expect(r.content, "<b></b> rimosso").not.toMatch(/<b>\s*<\/b>/);
    expect(r.content, "<i></i> rimosso").not.toMatch(/<i>\s*<\/i>/);
    expect(r.content).toContain("aaa");
    expect(r.content).toContain("bbb");
    expect(r.content).toContain("ccc");
});

test("Paste: singleton <div>g</div> → unwrap (no newline indesiderata)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "aaadf";
        window.__fmFocusedTA = ta;
        const range = document.createRange();
        range.selectNodeContents(ta);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        // Browser-style clipboard HTML per singolo char
        const dirty = '<!--StartFragment--><div>g</div><!--EndFragment-->';
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: { getData: (t) => t === "text/html" ? dirty : "g" },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML };
    });
    expect(r.html, "no <div> wrapper").not.toContain("<div>");
    expect(r.html, "aaadfg inline").toBe("aaadfg");
});

test("Paste: multi-line <div>a</div><div>b</div> → inline + <br> separator", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "pre";
        window.__fmFocusedTA = ta;
        const range = document.createRange();
        range.selectNodeContents(ta);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        const dirty = '<div>line1</div><div>line2</div>';
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: { getData: (t) => t === "text/html" ? dirty : "line1\nline2" },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML };
    });
    expect(r.html, "div convertiti, <br> tra le righe").toMatch(/line1<br>line2/);
    expect(r.html, "no <div> wrapper").not.toContain("<div>");
});

test("Paste: <div><i>ccc</i><u>ddd</u></div> con multi inline children → unwrap", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "pre";
        window.__fmFocusedTA = ta;
        const range = document.createRange();
        range.selectNodeContents(ta);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        const dirty = '<div><i>ccc</i><u>ddd</u></div>';
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: { getData: (t) => t === "text/html" ? dirty : "cccddd" },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML };
    });
    expect(r.html, "<div> wrapper unwrappato").not.toContain("<div>");
    expect(r.html, "<i>ccc</i><u>ddd</u> inline").toMatch(/<i>ccc<\/i><u>ddd<\/u>/);
});

test("Paste: inter-element whitespace \\n/\\t da clipboard parser → strip (no newline visive)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        // Caret dentro <b>aaa a |</b>
        ta.innerHTML = "<b>aaa a </b>";
        window.__fmFocusedTA = ta;
        const bEl = ta.querySelector("b");
        const range = document.createRange();
        range.setStart(bEl.firstChild, bEl.firstChild.length);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        // Clipboard "sporco" con \n inter-element (tipico browser)
        const dirty = '<!--StartFragment-->\n<div>asd</div>\n<!--EndFragment-->';
        const ev = new Event("paste", { bubbles: true, cancelable: true });
        Object.defineProperty(ev, "clipboardData", {
            value: { getData: (t) => t === "text/html" ? dirty : "asd" },
            writable: false,
        });
        ta.dispatchEvent(ev);
        return { html: ta.innerHTML.replace(/​/g, "") };
    });
    expect(r.html, "no newline letterali dentro <b>").not.toMatch(/<b>[^<]*\n[^<]*<\/b>/);
    expect(r.html, "concat clean: aaa a asd dentro <b>").toContain("<b>aaa a asd</b>");
});

test("Toggle B su nested <b><i><u>aaa</u></i></b>: middle preserva <i>+<u>", async ({ page }) => {
    // BUG: in single text node tutto wrappato, range.cloneContents perde i wrapper
    // intermedi. Fix: ri-wrap middle con innerWrappers (tra text e ancestor target).
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b><i><u>aaaaaaaaaaaaaaaa</u></i></b>";
        window.__fmFocusedTA = ta;

        // Seleziona "aaaa" interno (offset 5-9 del text node)
        const uEl = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(uEl.firstChild, 5);
        range.setEnd(uEl.firstChild, 9);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        // Toggle B: rimuove <b> dal middle, MA <i> e <u> devono restare
        window.FM.__toggleInlineFormatForTest(panel, "b");
        return { html: ta.innerHTML };
    });
    // Atteso: <b><i><u>aaaaa</u></i></b><i><u>aaaa</u></i><b><i><u>aaaaaaa</u></i></b>
    // (split di <b>, middle ri-wrappato con <i><u>)
    expect(r.html, "middle preserva <i><u>aaaa</u></i>").toMatch(/<i><u>aaaa<\/u><\/i>/);
    // Verifica che il middle "aaaa" NON sia plain (no <b> attorno)
    expect(r.html, "middle NON dentro <b>").not.toMatch(/<b>[^<]*<i><u>aaaa<\/u><\/i>[^<]*<\/b>/);
});

test("Toggle U su <b><i><u>aaaa</u></i></b>: middle preserva <b><i>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b><i><u>aaaaaaaaaaaaaaaa</u></i></b>";
        window.__fmFocusedTA = ta;

        const uEl = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(uEl.firstChild, 5);
        range.setEnd(uEl.firstChild, 9);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        // Toggle U: rimuove <u> dal middle (più interno, no innerWrappers)
        window.FM.__toggleInlineFormatForTest(panel, "u");
        return { html: ta.innerHTML };
    });
    // Middle "aaaa" senza <u> ma con <b><i> mantenuti (struttura esterna intatta)
    expect(r.html, "middle ha aaaa senza <u> ma dentro <b><i>").toMatch(/<u>aaaaa<\/u>\s*aaaa\s*<u>aaaaaaa<\/u>/);
});

test("Toggle I su <b><i><u>aaaa</u></i></b>: middle preserva <u> interno", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<b><i><u>aaaaaaaaaaaaaaaa</u></i></b>";
        window.__fmFocusedTA = ta;

        const uEl = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(uEl.firstChild, 5);
        range.setEnd(uEl.firstChild, 9);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        // Toggle I: rimuove <i> dal middle, <u> interno deve restare
        window.FM.__toggleInlineFormatForTest(panel, "i");
        return { html: ta.innerHTML };
    });
    // Middle: <u>aaaa</u> preservato senza <i> attorno
    expect(r.html, "middle preserva <u> interno").toMatch(/<u>aaaa<\/u>/);
});

test("DIAG: 40 chars 'aaa...' wrappato <b><i><u>: 3 toggle B/I/U sequenza", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const longStr = "a".repeat(40);
        const initial = `<b><i><u>${longStr}</u></i></b>`;
        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };

        // Test B
        ta.innerHTML = initial;
        window.__fmFocusedTA = ta;
        const u1 = ta.querySelector("u");
        const r1 = document.createRange();
        r1.setStart(u1.firstChild, 10); r1.setEnd(u1.firstChild, 25);
        const s1 = window.getSelection(); s1.removeAllRanges(); s1.addRange(r1);
        ta.focus();
        window.FM.__toggleInlineFormatForTest(panel, "b");
        const afterB = ta.innerHTML;

        // Test I
        ta.innerHTML = initial;
        const u2 = ta.querySelector("u");
        const r2 = document.createRange();
        r2.setStart(u2.firstChild, 10); r2.setEnd(u2.firstChild, 25);
        const s2 = window.getSelection(); s2.removeAllRanges(); s2.addRange(r2);
        ta.focus();
        window.FM.__toggleInlineFormatForTest(panel, "i");
        const afterI = ta.innerHTML;

        // Test U
        ta.innerHTML = initial;
        const u3 = ta.querySelector("u");
        const r3 = document.createRange();
        r3.setStart(u3.firstChild, 10); r3.setEnd(u3.firstChild, 25);
        const s3 = window.getSelection(); s3.removeAllRanges(); s3.addRange(r3);
        ta.focus();
        window.FM.__toggleInlineFormatForTest(panel, "u");
        const afterU = ta.innerHTML;

        return { afterB, afterI, afterU };
    });
    // Atteso B: split <b>, middle ri-wrappato con <i><u>
    expect(r.afterB, "Toggle B: middle <i><u>aaa..</u></i> presente").toMatch(/<i><u>a{15}<\/u><\/i>/);
    // Atteso I: split <i> dentro <b>, middle ri-wrappato con <u>
    expect(r.afterI, "Toggle I: middle <u>aaa..</u> presente dentro <b>").toMatch(/<\/i><u>a{15}<\/u><i>/);
    // Atteso U: split <u> dentro <i>, middle plain
    expect(r.afterU, "Toggle U: middle plain 'aaa..' dentro <i>").toMatch(/<\/u>a{15}<u>/);
});

test("Keydown Ctrl+U/I/B: handler attached + intercetta (preventDefault + toggle)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const longStr = "a".repeat(40);
        const initial = `<b><i><u>${longStr}</u></i></b>`;
        const results = {};

        for (const key of ["b", "i", "u"]) {
            ta.innerHTML = initial;
            window.__fmFocusedTA = ta;
            const u = ta.querySelector("u");
            const range = document.createRange();
            range.setStart(u.firstChild, 10);
            range.setEnd(u.firstChild, 25);
            const sel = window.getSelection();
            sel.removeAllRanges(); sel.addRange(range);
            ta.focus();

            const ev = new KeyboardEvent("keydown", {
                key, ctrlKey: true, bubbles: true, cancelable: true,
            });
            ta.dispatchEvent(ev);
            results[key] = {
                prevented: ev.defaultPrevented,
                html: ta.innerHTML.substring(0, 200),
                hasSplit: ta.querySelectorAll(key === "b" ? "b" : key === "i" ? "i" : "u").length > 1,
            };
        }
        return results;
    });
    expect(r.b.prevented, "Ctrl+B: preventDefault chiamato").toBe(true);
    expect(r.b.hasSplit, "Ctrl+B: <b> splittato in 2+ elementi").toBe(true);
    expect(r.i.prevented, "Ctrl+I: preventDefault chiamato").toBe(true);
    expect(r.i.hasSplit, "Ctrl+I: <i> splittato in 2+ elementi").toBe(true);
    expect(r.u.prevented, "Ctrl+U: preventDefault chiamato").toBe(true);
    expect(r.u.hasSplit, "Ctrl+U: <u> splittato in 2+ elementi").toBe(true);
});

test("Normalize: <i><div><b><u>aaa</u></b></div></i> → <div><i><b><u>aaa</u></b></i></div>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = "<i><div><b><u>aaaaaaaaaaaaaaaaaaaaaa</u></b></div></i>";
        window.__fmFocusedTA = ta;

        const u = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(u.firstChild, 5);
        range.setEnd(u.firstChild, 15);
        const sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };
        window.FM.__toggleInlineFormatForTest(panel, "i");
        return { html: ta.innerHTML };
    });
    // Post-normalize: struttura è <div><i><b><u>...</u></b></i></div>
    // Post-toggle I: split <i>, middle senza <i>. Tutto dentro UN solo <div>.
    expect(r.html, "no <div> dentro <i> (struttura normalizzata)").not.toMatch(/<i>\s*<div>/);
    expect(r.html, "split produce un solo <div> top-level").toMatch(/^<div>[\s\S]*<\/div>$/);
    // Conta i <div> visibili: dev'essere 1 (no multi-righe block)
    expect((r.html.match(/<div>/g) || []).length).toBe(1);
});

test("Chain B+U+I sulla STESSA selezione: ogni toggle trova ancestor correttamente", async ({ page }) => {
    // BUG: dopo primo toggle, selezione restaurata via setStartBefore/setEndAfter(middleRoot)
    // ha commonAncestorContainer = PARENT del middleRoot. Walk up perdeva i wrapper interni.
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        const longStr = "a".repeat(30);
        ta.innerHTML = `<b><i><u>${longStr}</u></i></b>`;
        window.__fmFocusedTA = ta;

        const u = ta.querySelector("u");
        const range = document.createRange();
        range.setStart(u.firstChild, 5);
        range.setEnd(u.firstChild, 20);
        const sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);
        ta.focus();

        const panel = { _focusedTextarea: ta, querySelector: (s) => wrap.querySelector(s) };

        // Step 1: toggle B (rimuove bold dal middle)
        window.FM.__toggleInlineFormatForTest(panel, "b");
        const afterB_selText = window.getSelection().toString();
        const afterB_count_b = ta.querySelectorAll("b").length;

        // Step 2: SENZA riselezione, toggle U
        window.FM.__toggleInlineFormatForTest(panel, "u");
        const afterU_selText = window.getSelection().toString();
        // Conta <u> nel middle (dovrebbe essere stato splittato)
        const afterU_html = ta.innerHTML;

        // Step 3: SENZA riselezione, toggle I
        window.FM.__toggleInlineFormatForTest(panel, "i");
        const afterI_selText = window.getSelection().toString();
        const afterI_html = ta.innerHTML;

        return {
            middleStr: longStr.substring(5, 20),
            afterB_selText,
            afterB_count_b,
            afterU_selText,
            afterU_html: afterU_html.substring(0, 300),
            afterI_selText,
            afterI_html: afterI_html.substring(0, 300),
        };
    });
    const middle = r.middleStr; // "aaaaaaaaaaaaaaa" (15 chars)
    expect(r.afterB_selText, "post-B: selezione preservata sul middle").toBe(middle);
    expect(r.afterB_count_b, "post-B: 2 <b> (split)").toBe(2);
    expect(r.afterU_selText, "post-U: selezione ANCORA sul middle (no riselezione)").toBe(middle);
    // Post-U: <u> interno al middle <i><u>aaa</u></i> è stato unwrapped → <i>aaa</i>
    expect(r.afterU_html, "post-U: middle è <i>aaa</i> (no <u>)").toMatch(/<i>a{15}<\/i>/);
    expect(r.afterU_html, "post-U: middle NON ha <u> attorno").not.toMatch(/<u>a{15}<\/u>/);
    expect(r.afterI_selText, "post-I: selezione ANCORA sul middle").toBe(middle);
    // Post-I: <i> interno al middle viene unwrappato → middle plain
    expect(r.afterI_html, "post-I: middle è plain (no <i>, no <u>)").toContain("a".repeat(15));
    expect(r.afterI_html, "post-I: no più <i> nel middle").not.toMatch(/<i>a{15}<\/i>/);
});

test("Bold inside list <li>: tag preserved in nested block.content", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const ta = wrap.querySelector(".fm-editor-field");
        ta.innerHTML = '<ol class="fm-dsa-li-list"><li>Testo <b>grasso</b> qui</li></ol>';
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        // blocks[0] = {type:"list", items:[[{type:"text", content:"Testo <b>grasso</b> qui"}]]}
        const liBlocks = blocks[0]?.items?.[0] || [];
        return {
            blocks,
            firstTextContent: liBlocks.find(b => b.type === "text")?.content || "",
        };
    });
    expect(r.firstTextContent, "<li> text deve avere <b> preservato").toContain("<b>grasso</b>");
});
