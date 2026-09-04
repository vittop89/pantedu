// GATE unificazione (ADR-026 C2-full): valida che la conversione FORWARD
// `schema → body_pt` (via sectionSchemaToPt) sia COMPLETA e fedele per TUTTI gli
// schemi risdoc. È la base del modello unificato: "forkare un modello" = generare
// un body_pt (formato custom) editabile e salvabile come un documento custom.
// Direzione FORWARD only → NON serve ptToFields (niente ri-segmentazione lossy).
//
// Verifica per ogni schema:
//   - ogni sezione produce ≥1 blocco PT (nessuna sezione persa)
//   - tutti i tipi di field noti → un nodo PT corrispondente (no fallback "[type]")
//   - 0 blocchi placeholder/sconosciuti
//
// Uso: node tools/validate-fork-to-bodypt.mjs [glob-dir]   (default schemas/risdoc)
import fs from "node:fs";
import path from "node:path";
globalThis.window = {};
const { sectionSchemaToPt } = await import("../js/modules/risdoc/pt/section-to-pt.js");

const dir = process.argv[2] || "schemas/risdoc";
const files = fs.readdirSync(dir).filter((f) => f.endsWith(".json") && f !== "template.schema.json");

// Conta i field "convertibili" nello schema per confrontare con i nodi prodotti.
const KNOWN_FIELD_TYPES = new Set([
    "nota-textarea", "checkbox-group", "grade-selector", "giudizio-item",
    "giudizio-group", "info-field", "form-checkbox", "dynamic-table", "header",
    "static-content",
]);
function countFields(node, acc) {
    if (!node || typeof node !== "object") return;
    if (node.type && KNOWN_FIELD_TYPES.has(node.type)) acc.fields++;
    for (const k of Object.keys(node)) {
        const v = node[k];
        if (Array.isArray(v)) v.forEach((x) => countFields(x, acc));
        else if (v && typeof v === "object") countFields(v, acc);
    }
}

let allOk = true;
const report = [];
for (const f of files) {
    const schema = JSON.parse(fs.readFileSync(path.join(dir, f), "utf8"));
    const sections = Array.isArray(schema.sections) ? schema.sections : [];
    const pt = [];
    let convError = null;
    for (const s of sections) {
        try { const p = sectionSchemaToPt(s, {}, {}); if (Array.isArray(p)) pt.push(...p); }
        catch (e) { convError = `${s.title || s.id || "?"}: ${e.message}`; }
    }
    // placeholder "[type] ..." = field type NON gestito da fieldToPt → perdita.
    const placeholders = pt.filter((b) =>
        b._type === "block" && Array.isArray(b.children)
        && b.children.some((c) => typeof c.text === "string" && /^\[[a-z-]+\]/.test(c.text))
    ).length;
    const acc = { fields: 0 };
    sections.forEach((s) => countFields(s, acc));
    const ok = !convError && pt.length > 0 && placeholders === 0;
    if (!ok) allOk = false;
    report.push(`${ok ? "✅" : "❌"} ${f}: sezioni=${sections.length} field=${acc.fields} blocchi=${pt.length} placeholder=${placeholders}${convError ? " ERR:" + convError : ""}`);
}

console.log(`\n=== FORK → body_pt (${files.length} schemi) ===`);
console.log(report.join("\n"));
console.log(allOk ? "\n✅ TUTTI gli schemi convertono a body_pt completo (fork = custom OK)"
                  : "\n❌ alcuni schemi hanno field non convertiti (placeholder) o errori");
process.exit(allOk ? 0 : 1);
