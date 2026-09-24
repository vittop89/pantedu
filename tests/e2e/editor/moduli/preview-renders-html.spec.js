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
const { test, expect } = require("../../support/test");

test("Preview pane renderizza HTML <ol> dopo insertListSnippet", async ({ bancoEditor }) => {
    const page = bancoEditor.page;

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

});
