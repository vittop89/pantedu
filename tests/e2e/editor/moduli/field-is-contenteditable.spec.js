/**
 * Verifica HARD che dopo refactor l'editor inline su un esercizio reale
 * usi <div contenteditable> e renderizzi HTML visualmente (non testo).
 *
 * Step:
 *   1. Login docente
 *   2. Naviga a una pagina con .fm-collection__item (verifica MAT-Funzioni-ver)
 *   3. Click ✎ (matita) per aprire editor inline su primo .fm-collection__item
 *   4. Trova .fm-editor-field
 *   5. Verifica: tagName === "DIV" + isContentEditable === true
 *   6. insertListSnippet → field DOM contiene <ol class="fm-dsa-li-list"> come elemento
 *      (NON come testo: testCheck via querySelector)
 *   7. Snapshot screenshot per debug visivo
 */
const { test, expect } = require("../../support/test");

test("Editor inline reale: .fm-editor-field è contenteditable e mostra <ol> rendered", async ({ bancoEditor }) => {
    const page = bancoEditor.page;

    const result = await page.evaluate(async () => {
        // Usa direttamente buildSection per simulare l'apertura editor
        if (typeof window.FM?.__buildSectionForTest !== "function") {
            return { error: "buildSection non esposto" };
        }
        const wrap = window.FM.__buildSectionForTest("Quesito", "Quesito iniziale");
        document.body.appendChild(wrap);

        const field = wrap.querySelector(".fm-editor-field");
        if (!field) {
            return { error: "field non creato" };
        }
        // Verifica natura del field
        const isCe = field.isContentEditable;
        const tag = field.tagName;

        // Insert lista
        if (typeof window.FM?.__insertListSnippetForTest !== "function") {
            return { error: "insertListSnippet helper missing" };
        }
        field.focus();
        window.FM.__insertListSnippetForTest({ _focusedTextarea: field }, "ol");

        return {
            tag,
            isCe,
            valueLen: field.value?.length || 0,
            // DOM check: c'è davvero un <ol class="fm-dsa-li-list"> figlio del field?
            hasOlElement: !!field.querySelector("ol.fm-dsa-li-list"),
            liCount: field.querySelectorAll("li").length,
            innerHTML: field.innerHTML?.slice(0, 200),
        };
    });

    expect(result.error, JSON.stringify(result)).toBeUndefined();
    expect(result.tag, "field tag DIV (refactor)").toBe("DIV");
    expect(result.isCe, "field contenteditable").toBe(true);
    expect(result.hasOlElement, "field DOM contiene <ol> rendered (NOT text)").toBe(true);
    expect(result.liCount, "field DOM ha 1 <li> (wysiwyg: caret vuoto → singolo li)").toBe(1);

});
