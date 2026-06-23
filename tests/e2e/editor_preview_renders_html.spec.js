/**
 * Verifica come funziona ATTUALMENTE l'editor:
 *   - textarea sx mostra sorgente HTML come TESTO (correct: è textarea)
 *   - preview pane dx (`.fm-editor-preview`) deve renderizzare HTML
 *     visualmente come <ol> lista vera
 *
 * Se il preview funziona → "il textarea mostra HTML come testo" è UX scelta;
 * il visual è nel preview a fianco.
 *
 * Se il preview NON renderizza l'HTML → bug da fixare.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Preview pane renderizza HTML <ol> dopo insertListSnippet", async ({ page }) => {
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

    // Inietta un panel editor sintetico con textarea + preview
    const result = await page.evaluate(async () => {
        const root = document.body;
        // Carica buildSection se non disponibile via window.FM
        const has = typeof window.FM?.__buildSectionForTest === "function";
        if (!has) return { error: "buildSection non esposto" };

        const wrap = window.FM.__buildSectionForTest("Quesito", "Nuovo quesito");
        root.appendChild(wrap);

        const ta = wrap.querySelector(".fm-editor-field");
        const pv = wrap.querySelector(".fm-editor-preview");

        // Click List → ol
        window.FM.__insertListSnippetForTest({ _focusedTextarea: ta }, "ol");
        // Aspetta debounce preview
        await new Promise((r) => setTimeout(r, 700));

        return {
            taValue: ta.value,
            taIsContentEditable: !!ta.isContentEditable,
            taTagName: ta.tagName,
            taHasOlInDom: !!ta.querySelector?.("ol.fm-dsa-li-list"),
            pvHtml: pv?.innerHTML || "",
            pvHasOl: !!pv?.querySelector("ol.fm-dsa-li-list"),
            pvHasLi: pv?.querySelectorAll("li").length || 0,
        };
    });

    expect(result.error, "buildSection esposto").toBeUndefined();
    expect(result.taValue, "field.value (innerHTML) contiene <ol").toContain("<ol");
    expect(result.taIsContentEditable, "field è contenteditable (refactor)").toBe(true);
    expect(result.taHasOlInDom, "field renderizza <ol> visualmente nel DOM").toBe(true);
    expect(result.pvHasOl, "preview pane contiene <ol class=fm-dsa-li-list> renderizzato").toBe(true);
    expect(result.pvHasLi, "preview pane ha almeno 1 <li>").toBeGreaterThanOrEqual(1);

    console.log("Preview HTML (first 200):", result.pvHtml.slice(0, 200));
});
