/**
 * Tab "smart" column-aligned (TAB_WIDTH=4):
 *   - Caret a col 0 → 4 spazi
 *   - Caret a col 3 → 1 spazio (porta a col 4)
 *   - Caret a col 4 → 4 spazi (porta a col 8)
 *   - Caret a col 10 → 2 spazi (porta a col 12)
 *   - Tab consecutivi avanzano correttamente (caret COLLASSATO post-insert)
 *   - Shift+Tab rimuove fino a 4 spazi precedenti (de-indent)
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

test("Tab al col 0: 4 spazi inseriti, caret COLLASSATO a fine", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "";
        // Caret all'inizio
        const range = document.createRange();
        range.setStart(field, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, false);

        // Verifica: caret COLLASSATO E posizionato DOPO gli spazi.
        // Calcolo posizione assoluta del caret nel field via Range.
        const sel2 = window.getSelection();
        const isCollapsed = sel2.isCollapsed;
        let absPos = -1;
        if (sel2.rangeCount) {
            const r = sel2.getRangeAt(0);
            const measureRange = document.createRange();
            measureRange.selectNodeContents(field);
            measureRange.setEnd(r.startContainer, r.startOffset);
            absPos = measureRange.toString().length;
        }

        return {
            text: field.textContent,
            length: field.textContent.length,
            isCollapsed,
            absPos,
        };
    });
    expect(r.text).toBe("    ");
    expect(r.length).toBe(4);
    expect(r.isCollapsed, "selection collapsed (no selezione attiva)").toBe(true);
    expect(r.absPos, "caret POSIZIONATO dopo i 4 spazi").toBe(4);
});

test("Tab consecutivi: 4+4+4 = 12 spazi avanzando per colonne", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "";
        const range = document.createRange();
        range.setStart(field, 0);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        // 3 Tab consecutivi
        window.FM.__insertTabAtCaretForTest(field, false);
        window.FM.__insertTabAtCaretForTest(field, false);
        window.FM.__insertTabAtCaretForTest(field, false);

        return field.textContent;
    });
    expect(r.length, "3 Tab × 4 spazi = 12 spazi totali").toBe(12);
    expect(r).toBe("            ");
});

test("Tab dopo testo a col 3: 1 spazio (allinea a col 4)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "abc";  // 3 chars
        // Caret a fine "abc" (col 3)
        const range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, false);

        return {
            text: field.textContent,
            length: field.textContent.length,
        };
    });
    expect(r.text, "abc + 1 spazio = 'abc '").toBe("abc ");
    expect(r.length).toBe(4);
});

test("Tab dopo testo a col 4: 4 spazi (allinea a col 8)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "abcd";  // 4 chars
        const range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, false);

        return field.textContent;
    });
    expect(r, "'abcd' + 4 spazi = 'abcd    '").toBe("abcd    ");
});

test("Tab a col 10: 2 spazi (allinea a col 12)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "abcdefghij";  // 10 chars
        const range = document.createRange();
        range.selectNodeContents(field);
        range.collapse(false);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, false);

        return field.textContent.length;
    });
    expect(r, "10 chars + 2 spazi = 12 totali").toBe(12);
});

test("Tab dopo <br>: column si resetta, 4 spazi", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "abc<br>";
        // Caret subito DOPO il <br> (col 0 della nuova riga)
        const range = document.createRange();
        range.setStartAfter(field.querySelector("br"));
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, false);

        // textContent = "abc" + spazi (br non ha textContent ma resetta col)
        return field.innerHTML;
    });
    // Attesi 4 spazi dopo <br>
    expect(r, "<br>+4 spazi after").toMatch(/<br>\s{4}/);
});

test("Shift+Tab dopo 4 spazi: rimuove tutti", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.textContent = "    ciao";  // 4 spazi + ciao
        // Caret prima di "ciao" (offset 4, dopo gli spazi)
        const range = document.createRange();
        range.setStart(field.firstChild, 4);
        range.collapse(true);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        field.focus();

        window.FM.__insertTabAtCaretForTest(field, true /* shift */);

        return field.textContent;
    });
    expect(r, "Shift+Tab rimosso 4 spazi → 'ciao'").toBe("ciao");
});

test("_columnAtCaret precision check", async ({ page }) => {
    await login(page);
    const cols = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = "abc<br>defgh<br>i";

        const tn = field.childNodes;  // [text"abc", br, text"defgh", br, text"i"]
        // Col del caret a fine "abc" (offset 3 nel primo text node)
        const c1 = window.FM.__columnAtCaretForTest(field, tn[0], 3);
        // Col del caret a inizio "defgh" (offset 0 nel terzo nodo)
        const c2 = window.FM.__columnAtCaretForTest(field, tn[2], 0);
        // Col del caret a posizione 3 di "defgh"
        const c3 = window.FM.__columnAtCaretForTest(field, tn[2], 3);
        // Col del caret subito dopo l'ultimo <br>
        const c4 = window.FM.__columnAtCaretForTest(field, tn[4], 0);
        return { c1, c2, c3, c4 };
    });
    expect(cols.c1, "caret a fine 'abc' (riga 1) → col 3").toBe(3);
    expect(cols.c2, "caret a inizio 'defgh' (riga 2) → col 0").toBe(0);
    expect(cols.c3, "caret in mezzo 'defgh' (col 3 in riga 2) → col 3").toBe(3);
    expect(cols.c4, "caret a inizio 'i' (riga 3) → col 0").toBe(0);
});
