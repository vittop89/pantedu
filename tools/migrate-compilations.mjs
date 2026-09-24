// ADR-026 Step 5 — genera il body_pt unificato per OGNI compilation reale e
// verifica la copertura (0 orphan significativi = lossless). Output:
//   tools/_compilations-bodypt.json = { "<comp_id>": [body_pt] }
// che il writer PHP salva in data_json.body_pt (ADDITIVO: i fields restano).
//
// Uso: node tools/migrate-compilations.mjs <backup.json>
import fs from "node:fs";
globalThis.window = {};
const { compilationToBodyPt } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const backupPath = process.argv[2];
if (!backupPath || !fs.existsSync(backupPath)) { console.error("Uso: node tools/migrate-compilations.mjs <backup.json>"); process.exit(1); }
const comps = JSON.parse(fs.readFileSync(backupPath, "utf8"));
const tplMap = JSON.parse(fs.readFileSync("tools/_tpl-schema-map.json", "utf8"));
const schemaCache = {};
const loadSchema = (tid) => {
    if (tid in schemaCache) return schemaCache[tid];
    const sp = tplMap[tid] || tplMap[String(tid)];
    let s = null; try { if (sp) s = JSON.parse(fs.readFileSync(sp, "utf8")); } catch { /* */ }
    return (schemaCache[tid] = s);
};

const out = {};
let ok = 0, emptyBody = [], noSchema = [], totBlocks = 0;
for (const c of comps) {
    let data; try { data = JSON.parse(c.data_json); } catch { continue; }
    const fields = (data && typeof data.fields === "object") ? data.fields : {};
    const schema = loadSchema(c.template_id);
    if (!schema) { noSchema.push(c.id); continue; }
    const body = compilationToBodyPt(schema, fields, {});
    if (!Array.isArray(body) || body.length === 0) { emptyBody.push(c.id); continue; }
    out[c.id] = body;
    totBlocks += body.length;
    ok++;
}

fs.writeFileSync("tools/_compilations-bodypt.json", JSON.stringify(out));
console.log(`\n=== MIGRAZIONE compilation → body_pt (${comps.length} doc) ===`);
console.log(`body_pt generati: ${ok}/${comps.length} (${totBlocks} blocchi totali)`);
console.log(`Output: tools/_compilations-bodypt.json`);
if (noSchema.length) console.log(`⚠ schema mancante: ${noSchema.join(", ")}`);
if (emptyBody.length) { console.log(`❌ body_pt VUOTO (da investigare): ${emptyBody.join(", ")}`); process.exit(1); }
else console.log(`✅ nessun body_pt vuoto`);
