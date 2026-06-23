// ADR-026 Step 4 (motore UNICO): genera il master body_pt per OGNI schema
// istituzionale, così il fork copia un master STORATO (server-side, consistente,
// editabile dal super-admin) invece di ri-derivarlo client-side ad ogni fork.
// Additivo: i fork preferiscono il master se presente, altrimenti fallback a
// ensureTemplateSeedPt (derivazione live). Output keyed per BASENAME dello
// schema → l'apply PHP matcha risdoc_templates.schema_path.
//
// Uso: node tools/backfill-master-bodypt.mjs   (scrive tools/_master-bodypt.json)
import fs from "node:fs";
import path from "node:path";
globalThis.window = {};
const { sectionSchemaToPt } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const dir = "schemas/risdoc";
const files = fs.readdirSync(dir).filter((f) => f.endsWith(".json") && f !== "template.schema.json");

const out = {};       // basename → body_pt[]
let totalBlocks = 0, placeholders = 0, withholes = [];

for (const f of files) {
    const schema = JSON.parse(fs.readFileSync(path.join(dir, f), "utf8"));
    const sections = Array.isArray(schema.sections) ? schema.sections : [];
    const body = [];
    for (const s of sections) {
        if (Array.isArray(s.default)) { body.push(...s.default); continue; }
        try { const pt = sectionSchemaToPt(s, {}, {}); if (Array.isArray(pt)) body.push(...pt); }
        catch (e) { /* sezione non convertibile → skip */ }
    }
    // Rileva placeholder "[type]" (blocco non convertito) → master incompleto.
    const holes = JSON.stringify(body).match(/"\[[a-z-]+\]"/g) || [];
    if (holes.length) { placeholders += holes.length; withholes.push(`${f}: ${holes.length}`); }
    totalBlocks += body.length;
    out[f] = body;
}

fs.writeFileSync("tools/_master-bodypt.json", JSON.stringify(out));
console.log(`\n=== MASTER body_pt generato (${files.length} schemi, ${totalBlocks} blocchi) ===`);
console.log(`Output: tools/_master-bodypt.json (keyed per basename schema)`);
if (placeholders === 0) console.log("✅ 0 placeholder — tutti i master sono completi (fork-ready)");
else { console.log(`❌ ${placeholders} placeholder rimasti:`); withholes.forEach((h) => console.log("  " + h)); process.exit(1); }
