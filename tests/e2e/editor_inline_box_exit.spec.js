/**
 * ArrowRight/ArrowLeft per uscire da <span class="dots"> / <span class="fm-add-text-dsa">.
 * Caret intrappolato in span inline → ArrowRight a fine sposta caret dopo lo span.
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

test("ArrowRight a fine span.dots → caret uscito dopo lo span", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = 'pre <span class="dots">testo</span>';
        // Caret a fine "fm-testo" dentro span
        const span = field.querySelector("span.dots");
        const range = document.createRange();
        range.setStart(span.firstChild, 5);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        // Simula ArrowRight keydown
        const ev = new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true, cancelable: true });
        field.dispatchEvent(ev);

        // Verifica: caret è OUT dello span
        const sel2 = window.getSelection();
        const caretContainer = sel2.getRangeAt(0).startContainer;
        const insideSpan = span.contains(caretContainer);
        return {
            preventedDefault: ev.defaultPrevented,
            caretInsideSpan: insideSpan,
            html: field.innerHTML,
        };
    });
    expect(r.preventedDefault).toBe(true);
    expect(r.caretInsideSpan, "caret NON deve essere più dentro span.dots").toBe(false);
});

test("ArrowLeft a inizio span.AddTextDSA → caret esce prima dello span", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = 'pre <span class="fm-add-text-dsa">**</span> post';
        const span = field.querySelector("span.fm-add-text-dsa");
        // Caret a inizio "**" (offset 0 nel text node figlio)
        const range = document.createRange();
        range.setStart(span.firstChild, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        const ev = new KeyboardEvent("keydown", { key: "ArrowLeft", bubbles: true, cancelable: true });
        field.dispatchEvent(ev);

        const sel2 = window.getSelection();
        const caretContainer = sel2.getRangeAt(0).startContainer;
        return {
            preventedDefault: ev.defaultPrevented,
            caretInsideSpan: span.contains(caretContainer),
        };
    });
    expect(r.preventedDefault).toBe(true);
    expect(r.caretInsideSpan, "caret esce a sinistra dello span").toBe(false);
});

test("ArrowRight in mezzo a span (NON a boundary) → no preventDefault (navigation normale)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.innerHTML = '<span class="dots">testo</span>';
        const span = field.querySelector("span.dots");
        const range = document.createRange();
        range.setStart(span.firstChild, 2);  // a metà "fm-testo"
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        const ev = new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true, cancelable: true });
        field.dispatchEvent(ev);

        return { preventedDefault: ev.defaultPrevented };
    });
    expect(r.preventedDefault, "no preventDefault: caret è in mezzo, browser muove caret di 1 char").toBe(false);
});
