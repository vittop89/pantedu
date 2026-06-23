/**
 * Stili gerarchici tipo Google Docs:
 *   - Default <ol>: 1./a./i. (decimal/lower-alpha/lower-roman)
 *   - Default <ul>: ●/○/■ (disc/circle/square)
 *   - Preset: alpha-decimal (A./1./a.), roman-alpha (I./A./1.), decimal-zero (01./a./i.),
 *     paren (1)/a)/i)), arrow-bullet (➤/♦/●), star-circle (★/○/■)
 *
 * Verifica:
 *   1. data-fm-list-style attribute persisted on <ol>/<ul>
 *   2. NON viene aggiunto type=A/a/I (solo preset, nessun conflitto specificity)
 *   3. Round-trip: insert preset → buildBlocks (list_preset) → _toHtml mantiene preset
 *
 * NB: Computed CSS style non è testabile reliably in Playwright headless
 * (layout.css non caricato, addStyleTag inconsistent). Verifica visiva
 * affidata al rendering manuale del browser reale.
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

test("Preset alpha-decimal: data-fm-list-style settato, no type attr", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol-Alpha");
        const ol = field.querySelector("ol.fm-dsa-li-list");
        return {
            preset: ol?.getAttribute("data-fm-list-style"),
            type: ol?.getAttribute("type"),
        };
    });
    expect(r.preset).toBe("alpha-decimal");
    expect(r.type, "type attr non settato (preset CSS-driven)").toBeFalsy();
});

test("Preset roman-alpha: data-fm-list-style=roman-alpha", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol-Roman");
        const ol = field.querySelector("ol.fm-dsa-li-list");
        return { preset: ol?.getAttribute("data-fm-list-style") };
    });
    expect(r.preset).toBe("roman-alpha");
});

test("Preset decimal-zero: data-fm-list-style=decimal-zero", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol-zero");
        const ol = field.querySelector("ol.fm-dsa-li-list");
        return { preset: ol?.getAttribute("data-fm-list-style") };
    });
    expect(r.preset).toBe("decimal-zero");
});

test("Preset paren / arrow-bullet / star-circle settano data-fm-list-style", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const presets = ["ol-paren", "ul-arrow", "ul-star"];
        const out = {};
        for (const p of presets) {
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const field = wrap.querySelector(".fm-editor-field");
            field.focus();
            window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, p);
            const list = field.querySelector("ol.fm-dsa-li-list, ul.fm-dsa-li-list");
            out[p] = list?.getAttribute("data-fm-list-style");
        }
        return out;
    });
    expect(r["ol-paren"]).toBe("paren");
    expect(r["ul-arrow"]).toBe("arrow-bullet");
    expect(r["ul-star"]).toBe("star-circle");
});

test("Round-trip preset: insert → buildBlocks → _toHtml preserva data-fm-list-style", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const ta = document.createElement("textarea");
        ta.dataset.field = "quesito";
        document.body.appendChild(ta);
        window.FM.__insertListSnippetForTest({ _focusedTextarea: ta }, "ol-Alpha");
        const blocks = window.FM.__buildBlocksFromTextareaForTest(ta);
        const list = blocks.find((b) => b.type === "list");
        const html = window.FM.__toHtmlForTest(blocks);
        return {
            blockPreset: list?.list_preset,
            htmlHasPreset: html.includes('data-fm-list-style="alpha-decimal"'),
        };
    });
    expect(r.blockPreset, "block list ha list_preset").toBe("alpha-decimal");
    expect(r.htmlHasPreset, "_toHtml HTML preserva data-fm-list-style").toBe(true);
});

test("Default ol/ul: nessun preset (default CSS nesting via descendant selectors)", async ({ page }) => {
    await login(page);
    const r = await page.evaluate(() => {
        const wrap = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap);
        const field = wrap.querySelector(".fm-editor-field");
        field.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");
        const ol = field.querySelector("ol.fm-dsa-li-list");
        const olPreset = ol?.getAttribute("data-fm-list-style");
        // pulisco
        wrap.remove();
        // ul
        const wrap2 = window.FM.__buildSectionForTest("Quesito", "");
        document.body.appendChild(wrap2);
        const field2 = wrap2.querySelector(".fm-editor-field");
        field2.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field2 }, "ul");
        const ul = field2.querySelector("ul.fm-dsa-li-list");
        const ulPreset = ul?.getAttribute("data-fm-list-style");
        return { olPreset, ulPreset };
    });
    // Default: nessun preset → CSS default usa descendant selector ol→ol→ol
    expect(r.olPreset, "ol default no preset").toBeFalsy();
    expect(r.ulPreset, "ul default no preset").toBeFalsy();
});
