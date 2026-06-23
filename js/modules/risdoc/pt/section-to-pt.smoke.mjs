/**
 * Smoke test standalone per section-to-pt.js (Phase 24.6/7).
 * Eseguilo con: `node js/modules/risdoc/pt/section-to-pt.smoke.mjs`
 */

import { sectionSchemaToPt } from "./section-to-pt.js";

let passed = 0, failed = 0;
function assertEq(a, e, label) {
    const aj = JSON.stringify(a), ej = JSON.stringify(e);
    if (aj === ej) { passed++; return; }
    failed++;
    console.error(`FAIL: ${label}`);
    console.error(`  expected: ${ej}`);
    console.error(`  actual:   ${aj}`);
}
function assertHas(arr, predicate, label) {
    if (Array.isArray(arr) && arr.some(predicate)) { passed++; return; }
    failed++;
    console.error(`FAIL: ${label} (not found in array of ${arr?.length})`);
}

// 1. section minimal (no title, no items)
assertEq(sectionSchemaToPt({}), [], "empty section → []");
assertEq(sectionSchemaToPt(null), [], "null section → []");

// 2. section con title → sectionHeader header
const s1 = sectionSchemaToPt({ title: "1. Sezione", items: [] });
assertHas(s1, (b) => b._type === "sectionHeader" && b.title === "1. Sezione", "title → sectionHeader");

// 3. header type → sectionHeader level 1 + selectors
const s2 = sectionSchemaToPt({
    type: "header", title: "Piano", selectors: ["classe", "sezione"],
});
assertHas(s2, (b) => b._type === "sectionHeader" && b.level === 1 && b.selectors?.includes("classe"), "header → sectionHeader level 1 + selectors");

// 4. nota-textarea con default PT → pass-through
const ptDefault = [{
    _type: "block", style: "normal",
    children: [{ _type: "span", text: "hello", marks: [] }],
}];
const s3 = sectionSchemaToPt({
    items: [{ type: "nota-textarea", name: "x", default: ptDefault }],
}, {});
assertHas(s3, (b) => b._type === "block" && b.children?.[0]?.text === "hello", "nota-textarea default PT pass-through");

// 5. nota-textarea con value string legacy → single block
const s4 = sectionSchemaToPt({
    items: [{ type: "nota-textarea", name: "x" }],
}, { x: "Testo legacy" });
assertHas(s4, (b) => b._type === "block" && b.children?.some(c => c.text === "Testo legacy"),
    "nota-textarea string legacy → block span");

// 6. checkbox-group con options + values selected
const s5 = sectionSchemaToPt({
    items: [{
        type: "checkbox-group", name: "c1",
        options: [{value: "a", label: "A"}, {value: "b", label: "B"}, {value: "c", label: "C"}],
    }],
}, { c1: ["a", "c"] });
assertHas(s5, (b) => b._type === "checkboxGroup"
    && b.items?.length === 3
    && b.items[0].state === "x" && b.items[1].state === "_" && b.items[2].state === "x",
    "checkbox-group with values");

// 7. grade-selector → select
const s6 = sectionSchemaToPt({
    items: [{
        type: "grade-selector", name: "g", title: "Voto",
        options: [{value: "6", label: "Sei"}, {value: "8", label: "Otto"}],
    }],
}, { g: "8" });
assertHas(s6, (b) => b._type === "select" && b.value === "8" && b.label === "Voto",
    "grade-selector → select");

// 8. info-field → textField
const s7 = sectionSchemaToPt({
    items: [{ type: "info-field", name: "n", title: "Nome", kind: "text" }],
}, { n: "Mario" });
assertHas(s7, (b) => b._type === "textField" && b.value === "Mario" && b.label === "Nome",
    "info-field → textField");

// 9. form-checkbox truthy → formCheckbox checked
const s8 = sectionSchemaToPt({
    items: [{ type: "form-checkbox", name: "f", title: "Consenso" }],
}, { f: true });
assertHas(s8, (b) => b._type === "formCheckbox" && b.checked === true && b.label === "Consenso",
    "form-checkbox truthy → checked");

// 10. dynamic-table → table
const s9 = sectionSchemaToPt({
    items: [{
        type: "dynamic-table", name: "t",
        columns: ["N.", "Nome"],
    }],
}, { t: [["1", "A"], ["2", "B"]] });
assertHas(s9, (b) => b._type === "table" && b.columns[0] === "N." && b.rows.length === 2,
    "dynamic-table → table");

// 11. text-section nested → flatten
const s10 = sectionSchemaToPt({
    title: "Parent",
    type: "text-section",
    items: [
        { type: "nota-textarea", name: "n1" },
        {
            type: "text-section", title: "Child",
            items: [{ type: "info-field", name: "i" }],
        },
    ],
}, { i: "val" });
assertHas(s10, (b) => b._type === "sectionHeader" && b.title === "Parent", "nested parent header");
assertHas(s10, (b) => b._type === "sectionHeader" && b.title === "Child", "nested child header");
assertHas(s10, (b) => b._type === "textField" && b.value === "val", "nested textField");

// 12. static-content → block plain
const s11 = sectionSchemaToPt({
    items: [{ type: "static-content", html: "<p>Hello <strong>world</strong></p>" }],
});
assertHas(s11, (b) => b._type === "block" && b.children?.some(c => /Hello.*world/.test(c.text || "")),
    "static-content → block plain");

// 13. checkbox-group raggruppato per `group`
const s12 = sectionSchemaToPt({
    items: [{
        type: "checkbox-group",
        options: [
            { value: "a1", label: "A1", group: "Asse A" },
            { value: "a2", label: "A2", group: "Asse A" },
            { value: "b1", label: "B1", group: "Asse B" },
        ],
    }],
});
// Deve avere 2 heading block + 2 checkboxGroup
const headers = s12.filter(b => b._type === "block" && b.children?.some(c => c.marks?.includes("strong")));
const groups  = s12.filter(b => b._type === "checkboxGroup");
assertEq(headers.length, 2, "checkbox-group grouped: 2 heading");
assertEq(groups.length, 2, "checkbox-group grouped: 2 groups");

// 14. unknown type → fallback block
const s13 = sectionSchemaToPt({
    items: [{ type: "future-widget", title: "Strano" }],
});
assertHas(s13, (b) => b._type === "block" && b.children?.some(c => /future-widget/.test(c.text || "")),
    "unknown type → fallback visible");

// 15. options_source + dynamicOpts (Phase 24.9)
const osSection = {
    items: [{
        type: "checkbox-group",
        name: "competenze_base",
        options: [], // empty, fetchato da options_source
        options_source: { file: "competenze_DM2007/competenze_DM2007.json" },
    }],
};
const osKey = JSON.stringify(osSection.items[0].options_source);
const dynamicOpts = {
    [osKey]: [
        { value: "A", label: "Competenza A", default: false },
        { value: "B", label: "Competenza B", default: true },
    ],
};
const s14 = sectionSchemaToPt(osSection, {}, dynamicOpts);
assertHas(s14, (b) => b._type === "checkboxGroup" && b.items?.length === 2,
    "options_source dynamicOpts populated");
assertHas(s14, (b) => b._type === "checkboxGroup"
    && b.items?.find(i => i.label === "Competenza A" && i.state === "_")
    && b.items?.find(i => i.label === "Competenza B" && i.state === "x"),
    "options_source states match default");

// 16. options_source senza dynamicOpts → checkboxGroup vuoto (fallback su options statiche vuote)
const s15 = sectionSchemaToPt({
    items: [{
        type: "checkbox-group",
        options: [],
        options_source: { file: "missing.json" },
    }],
}, {}, {});
// Non deve crashare + può essere vuoto o senza checkboxGroup
assertEq(Array.isArray(s15), true, "options_source missing dynamicOpts → no crash");

console.log(`\nsection-to-pt.smoke: ${passed} passed, ${failed} failed`);
process.exit(failed === 0 ? 0 : 1);
