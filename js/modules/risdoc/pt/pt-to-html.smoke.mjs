/**
 * Smoke test standalone per pt-to-html.js (Phase 22.1 POC).
 *
 * Eseguilo con: `node js/modules/risdoc/pt/pt-to-html.smoke.mjs`
 *
 * 15 asserzioni che coprono gli stessi casi del PHPUnit PtToTexTest.php.
 * Exit code: 0 tutti pass, 1 almeno un fail. Stampa diff su fail.
 *
 * Nota: POC — sostituibile con Vitest/Mocha quando introdotto tooling JS test.
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { ptToHtml } from "./pt-to-html.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, "../../../../");

let passed = 0;
let failed = 0;

function assertEq(actual, expected, label) {
    if (actual === expected) { passed++; return; }
    failed++;
    console.error(`FAIL: ${label}`);
    console.error(`  expected: ${JSON.stringify(expected)}`);
    console.error(`  actual:   ${JSON.stringify(actual)}`);
}

function assertContains(haystack, needle, label) {
    if (typeof haystack === "string" && haystack.includes(needle)) { passed++; return; }
    failed++;
    console.error(`FAIL: ${label}`);
    console.error(`  expected contains: ${JSON.stringify(needle)}`);
    console.error(`  actual:            ${JSON.stringify(haystack)}`);
}

// 1. Empty array
assertEq(ptToHtml([]), "", "empty array");

// 2. Invalid input
assertEq(ptToHtml(null), "", "null input");
assertEq(ptToHtml(undefined), "", "undefined input");
assertEq(ptToHtml("string"), "", "string input");

// 3. Single plain span
assertEq(
    ptToHtml([{ _type: "block", style: "normal", children: [
        { _type: "span", text: "Ciao mondo", marks: [] },
    ]}]),
    "<p>Ciao mondo</p>",
    "single plain span"
);

// 4. Strong mark
assertEq(
    ptToHtml([{ _type: "block", style: "normal", children: [
        { _type: "span", text: "bold", marks: ["strong"] },
    ]}]),
    "<p><strong>bold</strong></p>",
    "strong mark"
);

// 5. All standard marks
const standardMarks = {
    "strong":    "<p><strong>x</strong></p>",
    "em":        "<p><em>x</em></p>",
    "underline": "<p><u>x</u></p>",
    "code":      "<p><code>x</code></p>",
};
for (const [mark, expected] of Object.entries(standardMarks)) {
    assertEq(
        ptToHtml([{ _type: "block", style: "normal", children: [
            { _type: "span", text: "x", marks: [mark] },
        ]}]),
        expected,
        `mark=${mark}`
    );
}

// 6. Marks nesting (strong outer, em inner)
assertEq(
    ptToHtml([{ _type: "block", style: "normal", children: [
        { _type: "span", text: "x", marks: ["strong", "em"] },
    ]}]),
    "<p><strong><em>x</em></strong></p>",
    "marks nesting"
);

// 7. Unknown mark passes through
assertEq(
    ptToHtml([{ _type: "block", style: "normal", children: [
        { _type: "span", text: "x", marks: ["foobar"] },
    ]}]),
    "<p>x</p>",
    "unknown mark pass-through"
);

// 8. HTML escape
const escOut = ptToHtml([{ _type: "block", style: "normal", children: [
    { _type: "span", text: '<script>alert("xss")</script> & more', marks: [] },
]}]);
assertContains(escOut, "&lt;script&gt;", "html escape <");
assertContains(escOut, "&amp;", "html escape &");
assertContains(escOut, "&quot;", "html escape \"");

// 9. FieldRef inline
assertEq(
    ptToHtml([{ _type: "block", style: "normal", children: [
        { _type: "span", text: "Classe ", marks: [] },
        { _type: "fieldRef", name: "classe" },
        { _type: "span", text: " sezione ", marks: [] },
        { _type: "fieldRef", name: "sezione" },
    ]}]),
    '<p>Classe <span class="pt-field-ref" data-field="classe">[classe]</span> sezione <span class="pt-field-ref" data-field="sezione">[sezione]</span></p>',
    "fieldRef inline"
);

// 10. CheckboxGroup mixed states
assertEq(
    ptToHtml([{
        _type: "checkboxGroup",
        items: [
            { state: "x", label: "corretto" },
            { state: "_", label: "adeguato" },
        ],
    }]),
    '<div class="fm-pt-checkbox-group"><label class="fm-pt-cb-item"><span class="fm-pt-cb-state">☑</span> corretto</label> <label class="fm-pt-cb-item"><span class="fm-pt-cb-state">☐</span> adeguato</label></div>',
    "checkboxGroup mixed"
);

// 11. CheckboxGroup empty
assertEq(
    ptToHtml([{ _type: "checkboxGroup", items: [] }]),
    "",
    "checkboxGroup empty"
);

// 12. RawTex block rendered as escaped callout
const rawOut = ptToHtml([{ _type: "rawTex", content: "\\begin{eq}x^2\\end{eq}" }]);
assertContains(rawOut, "pt-raw-tex", "rawTex wrapper class");
assertContains(rawOut, "\\begin{eq}", "rawTex content preserved");

// 13. Multiple blocks
assertEq(
    ptToHtml([
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "primo", marks: [] },
        ]},
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "secondo", marks: [] },
        ]},
    ]),
    "<p>primo</p>\n<p>secondo</p>",
    "multiple blocks"
);

// 14. Unknown block type skipped
assertEq(
    ptToHtml([
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "ok", marks: [] },
        ]},
        { _type: "alienBlock", payload: "ignored" },
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "still", marks: [] },
        ]},
    ]),
    "<p>ok</p>\n<p>still</p>",
    "unknown block skipped"
);

// 15. Fixture profilo_classe — contract test
const fixturePath = resolve(repoRoot, "schemas/risdoc/_pt/fixture-profilo.pt.json");
const fixture = JSON.parse(readFileSync(fixturePath, "utf-8"));
const fixtureOut = ptToHtml(fixture);
assertContains(fixtureOut, "Gli alunni della classe ", "fixture text");
assertContains(fixtureOut, 'data-field="classe"', "fixture fieldRef classe");
assertContains(fixtureOut, 'data-field="sezione"', "fixture fieldRef sezione");
assertContains(fixtureOut, '<span class="fm-pt-cb-state">☑</span> corretto', "fixture checkbox checked");
assertContains(fixtureOut, '<span class="fm-pt-cb-state">☐</span> adeguato', "fixture checkbox unchecked");
assertContains(fixtureOut, "<strong>regolare svolgimento</strong>", "fixture nested strong");

// Report
console.log(`\npt-to-html.smoke: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
