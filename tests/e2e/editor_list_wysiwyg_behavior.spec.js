/**
 * E2E logica wysiwyg standard del button List (allineato a legacy ListManager):
 *
 *   1. Caret in field VUOTO → lista con UN <li> vuoto
 *   2. Caret in riga con testo → quella riga diventa l'unico <li>
 *   3. Selezione multi-line → ogni riga diventa un <li>
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

test("Caso 1: caret in field vuoto → lista con UN <li> vuoto", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();
        // Caret all'inizio del field vuoto
        const range = document.createRange();
        range.setStart(field, 0);
        range.collapse(true);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        return {
            olCount: field.querySelectorAll("ol").length,
            liCount: ol?.querySelectorAll("li").length || 0,
            liEmpty: ol?.querySelector("li")?.textContent === "",
        };
    });
    expect(r.olCount).toBe(1);
    expect(r.liCount, "UN solo <li> vuoto").toBe(1);
    expect(r.liEmpty).toBe(true);
});

test("Caso 2: caret in riga con testo → la riga corrente diventa l'unico <li>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");

        // Setup: una riga di testo dentro un <div> (block)
        field.innerHTML = '<div>Riga corrente con testo</div>';
        const blockDiv = field.querySelector("div");
        // Caret a fine della riga
        const range = document.createRange();
        range.selectNodeContents(blockDiv);
        range.collapse(false);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        const li = ol?.querySelector("li");
        return {
            html: field.innerHTML,
            olCount: field.querySelectorAll("ol").length,
            liCount: ol?.querySelectorAll("li").length || 0,
            liText: li?.textContent || "",
            // Verifica che il <div> originale sia stato sostituito
            divCount: field.querySelectorAll("div").length,
        };
    });
    expect(r.olCount, "1 ol totale").toBe(1);
    expect(r.liCount, "UN <li> con il contenuto della riga").toBe(1);
    expect(r.liText, "li contiene il testo originale").toContain("Riga corrente con testo");
    expect(r.divCount, "il div originale è stato sostituito dalla lista").toBe(0);
});

test("Caso 3: selezione multi-line (3 righe) → 3 <li>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");

        // 3 righe consecutive (separate da <br>)
        field.innerHTML = "Riga uno<br>Riga due<br>Riga tre";

        // Selezione TUTTO il contenuto
        const range = document.createRange();
        range.selectNodeContents(field);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        const ol = field.querySelector("ol.fm-dsa-li-list");
        const liEls = ol ? Array.from(ol.querySelectorAll("li")) : [];
        return {
            olCount: field.querySelectorAll("ol").length,
            liCount: liEls.length,
            liTexts: liEls.map((li) => li.textContent.trim()),
        };
    });
    expect(r.olCount).toBe(1);
    expect(r.liCount, "3 li per 3 righe").toBe(3);
    expect(r.liTexts).toEqual(["Riga uno", "Riga due", "Riga tre"]);
});

test("Caso 4: selezione multi-line con <div> blocks → 2 <li>", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");

        // 2 blocchi <div> consecutivi (browser default per Enter in contenteditable)
        field.innerHTML = "<div>Primo blocco</div><div>Secondo blocco</div>";

        // Selezione TUTTO
        const range = document.createRange();
        range.selectNodeContents(field);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        field.focus();

        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ul");

        const ul = field.querySelector("ul.fm-dsa-li-list");
        const liEls = ul ? Array.from(ul.querySelectorAll("li")) : [];
        return {
            ulCount: field.querySelectorAll("ul").length,
            liCount: liEls.length,
            liTexts: liEls.map((li) => li.textContent.trim()),
        };
    });
    expect(r.ulCount).toBe(1);
    expect(r.liCount, "2 li per 2 blocchi").toBe(2);
    expect(r.liTexts).toEqual(["Primo blocco", "Secondo blocco"]);
});
