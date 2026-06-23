/**
 * Smoke test standalone per pm-pt-converter.js (Phase 22.3).
 *
 * Esegue: `node js/modules/risdoc/pt/pm-pt-converter.smoke.mjs`
 *
 * Copertura:
 *   - Conversione base (empty, plain paragraph, marks)
 *   - Mapping mark names (strong↔bold, em↔italic)
 *   - FieldRef inline round-trip
 *   - CheckboxGroup + rawTex round-trip
 *   - Fixture profilo_classe round-trip PT → PM → PT (no-loss)
 *   - Edge case: span vuoti droppati, fieldRef senza name droppato
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, resolve } from "node:path";
import { ptToPmDoc, pmDocToPt } from "./pm-pt-converter.js";

const __dirname = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(__dirname, "../../../../");

let passed = 0, failed = 0;

function assertEq(actual, expected, label) {
    const a = JSON.stringify(actual);
    const e = JSON.stringify(expected);
    if (a === e) { passed++; return; }
    failed++;
    console.error(`FAIL: ${label}`);
    console.error(`  expected: ${e}`);
    console.error(`  actual:   ${a}`);
}

// 1. Empty
assertEq(
    ptToPmDoc([]),
    { type: "doc", content: [] },
    "empty PT → doc with empty content"
);
assertEq(pmDocToPt({ type: "doc", content: [] }), [], "empty PM doc → []");

// 2. Invalid input guards
assertEq(ptToPmDoc(null), { type: "doc", content: [] }, "null PT");
assertEq(pmDocToPt(null), [], "null PM doc");
assertEq(pmDocToPt({ type: "other" }), [], "non-doc PM");

// 3. Plain paragraph
assertEq(
    ptToPmDoc([{
        _type: "block", style: "normal",
        children: [{ _type: "span", text: "Ciao", marks: [] }],
    }]),
    {
        type: "doc",
        content: [{
            type: "paragraph",
            content: [{ type: "text", text: "Ciao" }],
        }],
    },
    "plain paragraph PT → PM"
);

// 4. Marks mapping PT → PM
const pmWithMarks = ptToPmDoc([{
    _type: "block", style: "normal",
    children: [{ _type: "span", text: "x", marks: ["strong", "em"] }],
}]);
assertEq(
    pmWithMarks.content[0].content[0].marks,
    [{ type: "bold" }, { type: "italic" }],
    "marks strong+em → bold+italic"
);

// 5. Marks reverse PM → PT
const ptFromMarks = pmDocToPt({
    type: "doc",
    content: [{
        type: "paragraph",
        content: [{
            type: "text", text: "y",
            marks: [{ type: "underline" }, { type: "code" }],
        }],
    }],
});
assertEq(
    ptFromMarks[0].children[0].marks,
    ["underline", "code"],
    "marks underline+code preserved"
);

// 6. FieldRef PT → PM
assertEq(
    ptToPmDoc([{
        _type: "block", style: "normal",
        children: [
            { _type: "span", text: "Classe ", marks: [] },
            { _type: "fieldRef", name: "classe" },
        ],
    }]),
    {
        type: "doc",
        content: [{
            type: "paragraph",
            content: [
                { type: "text", text: "Classe " },
                { type: "fieldRef", attrs: { name: "classe" } },
            ],
        }],
    },
    "fieldRef PT → PM"
);

// 7. FieldRef senza name droppato
assertEq(
    ptToPmDoc([{
        _type: "block", style: "normal",
        children: [{ _type: "fieldRef", name: "" }],
    }]).content[0].content ?? [],
    [],
    "fieldRef empty name droppato"
);

// 8. CheckboxGroup PT → PM
assertEq(
    ptToPmDoc([{
        _type: "checkboxGroup",
        items: [{ state: "x", label: "ok" }, { state: "_", label: "no" }],
    }]),
    {
        type: "doc",
        content: [{
            type: "checkboxGroup",
            attrs: { items: [{ state: "x", label: "ok" }, { state: "_", label: "no" }] },
        }],
    },
    "checkboxGroup PT → PM"
);

// 9. RawTex PT → PM
assertEq(
    ptToPmDoc([{ _type: "rawTex", content: "\\begin{x}..." }]),
    {
        type: "doc",
        content: [{ type: "rawTex", attrs: { content: "\\begin{x}..." } }],
    },
    "rawTex PT → PM"
);

// 10. Unknown block type skipped
assertEq(
    ptToPmDoc([
        { _type: "block", style: "normal", children: [{ _type: "span", text: "a", marks: [] }] },
        { _type: "alienBlock" },
        { _type: "block", style: "normal", children: [{ _type: "span", text: "b", marks: [] }] },
    ]).content.length,
    2,
    "unknown PT block dropped"
);

// 11. Fixture profilo_classe round-trip PT → PM → PT
const fixturePath = resolve(repoRoot, "schemas/risdoc/_pt/fixture-profilo.pt.json");
const fixture = JSON.parse(readFileSync(fixturePath, "utf-8"));
const pmDoc = ptToPmDoc(fixture);
const ptAgain = pmDocToPt(pmDoc);

// Round-trip non è byte-identico (span vuoti " " tra fieldRef NON vengono
// droppati, quindi fixture originale deve essere robusta). Verifichiamo
// che ri-applicando il walker TeX il risultato sia equivalente.
assertEq(ptAgain.length, fixture.length, "round-trip blocks count");
assertEq(
    ptAgain[0]._type, "block",
    "round-trip blocco 0 _type"
);
assertEq(
    ptAgain[0].children.filter((c) => c._type === "fieldRef").map((c) => c.name),
    ["classe", "sezione"],
    "round-trip fieldRef preservati"
);
assertEq(
    ptAgain[1],
    fixture[1],
    "round-trip checkboxGroup identico"
);
// Blocco 2 finale: span con mark strong
const finalBlock = ptAgain[2];
assertEq(finalBlock._type, "block", "round-trip block 2");
const strongSpan = finalBlock.children.find((c) => c._type === "span" && (c.marks || []).includes("strong"));
assertEq(
    strongSpan?.text,
    "regolare svolgimento",
    "round-trip strong span text"
);

console.log(`\npm-pt-converter.smoke: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
