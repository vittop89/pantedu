// Gate sicurezza onepath: round-trip schema→PT→fields sul VERO schema complesso
// (Piano annuale, template 16). Misura PER-CAMPO se il valore sopravvive.
// Uso: node tools/dev/_validate_onepath_roundtrip.mjs <path-schema.json>
import fs from "node:fs";
globalThis.window = {};
const { sectionSchemaToPt, ptToFields } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const schemaPath = process.argv[2] || "schemas/risdoc/piano-annuale-docente.json";
const schema = JSON.parse(fs.readFileSync(schemaPath, "utf8"));
const sections = Array.isArray(schema.sections) ? schema.sections : [];

// 1. Genera valori di esempio per OGNI field nominato dello schema.
const sample = {};
const fieldMeta = {}; // name → {type}
let counter = 0;
function walk(node) {
    if (!node || typeof node !== "object") return;
    const type = node.type;
    const name = node.name;
    if (name && type) {
        fieldMeta[name] = { type };
        counter++;
        if (type === "checkbox-group") {
            const opts = node.options || node.items || [];
            // seleziona la prima opzione (per value o label)
            const first = opts[0];
            const v = first ? (first.value ?? first.label ?? first) : "opt1";
            sample[name] = [typeof v === "object" ? (v.value ?? v.label) : v];
        } else if (type === "dynamic-table") {
            const cols = (node.columns || []).map((c) => c.key || c.label || c.name || "c");
            const row = {};
            cols.forEach((c, i) => { row[c] = `R0C${i}`; });
            sample[name] = [row, (() => { const r = {}; cols.forEach((c, i) => r[c] = `R1C${i}`); return r; })()];
        } else if (type === "nota-textarea") {
            sample[name] = `NOTA_${counter}_testo`;
        } else if (type === "grade-selector" || type === "giudizio-item" || type === "info-field") {
            const opts = node.options || [];
            sample[name] = opts[0] ? (opts[0].value ?? opts[0].label ?? `val${counter}`) : `val${counter}`;
        } else if (type === "form-checkbox") {
            sample[name] = true;
        }
    }
    for (const k of Object.keys(node)) {
        const v = node[k];
        if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v === "object") walk(v);
    }
}
sections.forEach(walk);

// 2. Round-trip per ogni sezione: schema+fields → PT → fields'.
const pt = [];
for (const s of sections) {
    try { const p = sectionSchemaToPt(s, sample, {}); if (Array.isArray(p)) pt.push(...p); }
    catch (e) { console.log(`  ⚠ sezione non convertibile: ${s.id || s.name || "?"} — ${e.message}`); }
}
const back = ptToFields(pt);

// 3. Confronto per-campo.
const lost = [], changed = [], ok = [];
for (const [name, meta] of Object.entries(fieldMeta)) {
    if (!(name in sample)) continue; // field senza valore di esempio (es. header/text-section)
    const a = JSON.stringify(sample[name]);
    const b = JSON.stringify(back[name]);
    if (!(name in back)) lost.push(`${name} (${meta.type})`);
    else if (a !== b) changed.push(`${name} (${meta.type}): ${a} → ${b}`);
    else ok.push(name);
}

console.log(`\n=== ROUND-TRIP ${schemaPath} ===`);
console.log(`campi con valore: ${ok.length + changed.length + lost.length} | OK: ${ok.length} | CAMBIATI: ${changed.length} | PERSI: ${lost.length}`);
const byType = {};
for (const [n, m] of Object.entries(fieldMeta)) (byType[m.type] ??= []).push(n);
console.log(`tipi: ${Object.entries(byType).map(([t, a]) => `${t}=${a.length}`).join(", ")}`);
if (lost.length) console.log(`\nPERSI:\n  ${lost.join("\n  ")}`);
if (changed.length) console.log(`\nCAMBIATI:\n  ${changed.slice(0, 20).join("\n  ")}`);
console.log(lost.length === 0 && changed.length === 0 ? "\n✅ LOSSLESS" : "\n❌ NON lossless");
