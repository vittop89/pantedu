// ADR-026 Step 5 — DRY-RUN (non distruttivo) migrazione compilation → body_pt.
// Per ognuna delle 60 compilation reali (da backup JSON), costruisce il body_pt
// FILLED via sectionSchemaToPt(section, fields, {}) e verifica la COPERTURA:
//   - quante chiavi-campo NON vuote vengono CONSUMATE (sono leaf-name dello schema)
//   - quante restano ORPHAN (blob-sezione/sintetiche → ignorate da sectionSchemaToPt)
// Le orphan con valore non banale = potenziale PERDITA → vanno gestite prima
// di rimuovere il vecchio motore. Nessuna scrittura.
//
// Uso: node tools/migrate-compilations-dryrun.mjs <backup.json>
import fs from "node:fs";
globalThis.window = {};
const { sectionSchemaToPt } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const backupPath = process.argv[2];
if (!backupPath || !fs.existsSync(backupPath)) { console.error("Uso: node tools/migrate-compilations-dryrun.mjs <backup.json>"); process.exit(1); }
const comps = JSON.parse(fs.readFileSync(backupPath, "utf8"));
const tplMap = JSON.parse(fs.readFileSync("tools/_tpl-schema-map.json", "utf8"));
const schemaCache = {};
function loadSchema(tid) {
    if (tid in schemaCache) return schemaCache[tid];
    const sp = tplMap[tid] || tplMap[String(tid)];
    let s = null;
    try { if (sp) s = JSON.parse(fs.readFileSync(sp, "utf8")); } catch { /* */ }
    return (schemaCache[tid] = s);
}
// Nomi leaf che sectionSchemaToPt consuma (nodi con name, NON section container).
function schemaLeafNames(schema) {
    const out = new Set();
    const walk = (n) => {
        if (!n || typeof n !== "object") return;
        if (n.name && n.type) out.add(n.name);     // leaf con type → consumato via fields[name]
        for (const k of Object.keys(n)) { const v = n[k]; if (Array.isArray(v)) v.forEach(walk); else if (v && typeof v === "object") walk(v); }
    };
    (schema?.sections || []).forEach(walk);
    return out;
}
// Chiavi SINTETICHE che il vecchio motore usa per sezioni SENZA id:
// "section_<indice>_<slug(title)>". Questi blob-PT verranno spliciati così come
// sono nella migrazione (sono già il render della sezione) → NON sono perdita.
function slugify(t) {
    return String(t || "").toLowerCase()
        .replace(/^\s*\d+(\.\d+)*\.?\s*/, "")   // toglie "1. " / "2.3 " iniziale
        .normalize("NFD").replace(/[̀-ͯ]/g, "")
        .replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
}
function syntheticSectionKeys(schema) {
    const out = new Set();
    (schema?.sections || []).forEach((s, i) => {
        if (!s.id && (s.title || s.name)) out.add(`section_${i}_${slugify(s.title || s.name)}`);
    });
    return out;
}
// Valore "non banale" = stringa non vuota, bool true, array con contenuto reale.
function isMeaningful(v) {
    if (v == null) return false;
    if (typeof v === "string") return v.trim() !== "";
    if (typeof v === "boolean") return v === true;
    if (Array.isArray(v)) {
        if (v.length === 0) return false;
        // PT array: significativo se almeno un nodo ha testo/stato/righe.
        return v.some((n) => {
            if (!n || typeof n !== "object") return !!n;
            if (Array.isArray(n.rows) && n.rows.length) return true;
            if (Array.isArray(n.items) && n.items.some((it) => it?.state === "x" || it?.checked)) return true;
            if (Array.isArray(n.children) && n.children.some((c) => (c?.text || "").trim())) return true;
            if ((n.value || "").toString().trim()) return true;
            return false;
        });
    }
    if (typeof v === "object") return Object.keys(v).length > 0;
    return false;
}

let totConsumed = 0, totOrphan = 0, totOrphanMeaningful = 0, cleanDocs = 0, lossyDocs = 0;
const lossy = [];

for (const c of comps) {
    let data; try { data = JSON.parse(c.data_json); } catch { continue; }
    const fields = (data && typeof data.fields === "object") ? data.fields : {};
    const schema = loadSchema(c.template_id);
    if (!schema) { console.log(`  ⚠ comp #${c.id}: schema mancante per tpl ${c.template_id}`); continue; }
    const leaves = schemaLeafNames(schema);
    const synth = syntheticSectionKeys(schema);

    let consumed = 0, orphanMeaningful = [];
    for (const [k, v] of Object.entries(fields)) {
        const meaningful = isMeaningful(v);
        if (leaves.has(k) || synth.has(k)) { if (meaningful) consumed++; }  // synth = blob spliciato
        else if (meaningful) orphanMeaningful.push(k);
    }
    totConsumed += consumed;
    totOrphan += Object.keys(fields).filter((k) => !leaves.has(k)).length;
    totOrphanMeaningful += orphanMeaningful.length;

    if (orphanMeaningful.length === 0) { cleanDocs++; }
    else { lossyDocs++; lossy.push({ id: c.id, tpl: c.template_id, orphans: orphanMeaningful }); }
}

console.log(`\n=== DRY-RUN migrazione compilation → body_pt (${comps.length} doc) ===`);
console.log(`Campi leaf CONSUMATI (non vuoti):      ${totConsumed}`);
console.log(`Chiavi ORPHAN totali:                  ${totOrphan}`);
console.log(`Chiavi ORPHAN con valore SIGNIFICATIVO:${totOrphanMeaningful}  ← potenziale perdita`);
console.log(`\nDoc migrabili SENZA perdita:  ${cleanDocs}/${comps.length}`);
console.log(`Doc con orphan significativi: ${lossyDocs}/${comps.length}`);
if (lossy.length) {
    console.log(`\n--- doc con chiavi orphan significative (da gestire) ---`);
    for (const l of lossy) console.log(`  #${l.id} (tpl ${l.tpl}): ${l.orphans.join(", ")}`);
}
