/**
 * Verifica VISUAL che ogni preset in edit mode (.fm-editor-field) produca
 * il marker corretto via CSS list-style-type.
 *
 * Test injects layout.css completa via addStyleTag, poi computa
 * `getComputedStyle(ol).listStyleType` per ogni preset.
 */
const { test, expect } = require("../../support/test");

// preset → expected list-style-type for OUTER level (computed by browser)
const EXPECTED = [
    { preset: "",                   expect: "decimal" },           // default ol
    { preset: "alpha-decimal",      expect: "upper-alpha" },
    { preset: "lower-alpha-roman",  expect: "lower-alpha" },
    { preset: "roman-alpha",        expect: "upper-roman" },
    { preset: "decimal-zero",       expect: "decimal-leading-zero" },
    { preset: "paren",              expect: "decimal" },           // suffisso ) via ::marker
    { preset: "alpha-paren",        expect: "upper-alpha" },
    { preset: "lower-alpha-paren",  expect: "lower-alpha" },
    { preset: "roman-paren",        expect: "upper-roman" },
    { preset: "decimal-zero-paren", expect: "decimal-leading-zero" },
];


for (const { preset, expect: expectedType } of EXPECTED) {
    test(`Preset ${preset || "(default)"} → list-style-type=${expectedType}`, async ({ bancoEditor }) => {
        const page = bancoEditor.page;
        const result = await page.evaluate((cfg) => {
            const wrap = window.FM.__buildSectionForTest("Quesito", "");
            document.body.appendChild(wrap);
            const field = wrap.querySelector(".fm-editor-field");
            // Inserisci ol con preset (struttura sintetica, no F/GF buttons)
            const presetAttr = cfg.preset ? ` data-fm-list-style="${cfg.preset}"` : "";
            field.innerHTML = `<ol class="fm-dsa-li-list" data-dsa-section="question"${presetAttr}><li>uno</li><li>due</li><li>tre</li></ol>`;
            const ol = field.querySelector("ol.fm-dsa-li-list");
            return getComputedStyle(ol).listStyleType;
        }, { preset });
        expect(result, `preset=${preset || "(default)"}`).toBe(expectedType);
    });
}
