// GATE unificazione (ADR-026 C2-full, motore UNICO): misura quali METADATI
// STRUTTURALI dello schema NON sopravvivono in body_pt. Per avere un solo motore
// (super-admin edita la struttura come body_pt, docente il contenuto), i nodi PT
// devono PORTARE i metadati schema (name, type, options_source, columns, selectors,
// seed_ref). Questo gate confronta, per ogni campo nominato:
//   schema[name].{type,options_source,columns,selectors,seed_ref}
//   vs body_pt[name].{type-inferito,options_source,columns,...}
// e segnala cosa manca → è il lavoro di "carry" da fare sui nodi.
//
// Uso: node tools/validate-schema-carry.mjs [dir]
import fs from "node:fs";
import path from "node:path";
globalThis.window = {};
const { sectionSchemaToPt } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const dir = process.argv[2] || "schemas/risdoc";
const files = fs.readdirSync(dir).filter((f) => f.endsWith(".json") && f !== "template.schema.json");

// Metadati schema per ogni field nominato.
function schemaFields(schema) {
    const out = {};
    const walk = (n) => {
        if (!n || typeof n !== "object") return;
        if (n.name && n.type) {
            out[n.name] = {
                type: n.type,
                options_source: n.options_source ? JSON.stringify(n.options_source) : null,
                seed_ref: n.seed_ref || n.body_ref || null,
                columns: Array.isArray(n.columns) ? n.columns.length : null,
                selectors: Array.isArray(n.selectors) ? n.selectors.length : null,
            };
        }
        for (const k of Object.keys(n)) {
            const v = n[k];
            if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v === "object") walk(v);
        }
    };
    (schema.sections || []).forEach(walk);
    return out;
}

// Metadati portati dai nodi body_pt (per name).
function bodyPtFields(pt) {
    const out = {};
    const walk = (nodes) => {
        for (const n of (Array.isArray(nodes) ? nodes : [])) {
            if (!n || typeof n !== "object") continue;
            const key = n.name || n.fieldName; // fieldName = marker su block (nota)
            if (key) {
                out[key] = {
                    _type: n._type,
                    options_source: n.options_source ? JSON.stringify(n.options_source) : null,
                    seed_ref: n.seed_ref || null,
                    columns: Array.isArray(n.columns) ? n.columns.length : null,
                    selectors: Array.isArray(n.selectors) ? n.selectors.length : null,
                    fieldType: n.fieldType || null, // hint tipo-schema (carry)
                };
            }
            if (Array.isArray(n.children)) walk(n.children);
            if (Array.isArray(n.items)) for (const it of n.items) walk(it?.body_pt);
        }
    };
    walk(pt);
    return out;
}

const lost = {}; // metadato → count
function note(m) { lost[m] = (lost[m] || 0) + 1; }
let totFields = 0;

for (const f of files) {
    const schema = JSON.parse(fs.readFileSync(path.join(dir, f), "utf8"));
    const sf = schemaFields(schema);
    const pt = [];
    for (const s of (schema.sections || [])) { try { pt.push(...sectionSchemaToPt(s, {}, {})); } catch (_) {} }
    const pf = bodyPtFields(pt);
    for (const [name, meta] of Object.entries(sf)) {
        totFields++;
        const b = pf[name];
        if (!b) { note(`name-assente (${meta.type})`); continue; }
        if (meta.options_source && !b.options_source) note("options_source");
        if (meta.seed_ref && !b.seed_ref) note("seed_ref");
        if (meta.columns && meta.columns !== b.columns) note("columns");
        if (meta.selectors && meta.selectors !== b.selectors) note("selectors");
        if (!b.fieldType) note(`fieldType-hint (${meta.type})`);
    }
}

console.log(`\n=== SCHEMA→body_pt CARRY (${files.length} schemi, ${totFields} campi nominati) ===`);
const keys = Object.keys(lost).sort((a, b) => lost[b] - lost[a]);
if (keys.length === 0) console.log("✅ tutti i metadati strutturali sopravvivono (body_pt = schema lossless)");
else { console.log("Metadati PERSI (da aggiungere al carry dei nodi):"); for (const k of keys) console.log(`  ${k}: ${lost[k]}`); }
