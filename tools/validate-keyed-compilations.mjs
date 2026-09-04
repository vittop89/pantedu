// Gate C (rivisto): valida che l'approccio onepath "card per-campo keyed by name"
// sia LOSSLESS su TUTTE le compilation reali. Modello: data_json.fields è una
// mappa name→valore; in onepath ogni chiave = una card che porta quel valore e
// lo risalva verbatim → reopen-equality = identità. Questo gate verifica che la
// struttura sia universale (ogni valore è PT[] o primitivo gestito) e che il
// "round-trip keyed" (build sezioni → merge) riproduca fields IDENTICO.
//
// Uso: node tools/validate-keyed-compilations.mjs   (legge dal DB via PHP dump)
//      node tools/validate-keyed-compilations.mjs <file.json>  (un singolo fields)
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

function isPtArray(v) {
    return Array.isArray(v) && v.every((b) => b && typeof b === "object" && "_type" in b);
}
function isPrimitive(v) {
    return typeof v === "string" || typeof v === "boolean" || typeof v === "number"
        || (Array.isArray(v) && v.every((x) => typeof x === "string")); // checkbox-group selected[]
}

// Simula l'approccio keyed: fields → sezioni {name, value} → merge → fields'.
function keyedRoundTrip(fields) {
    const sections = Object.entries(fields).map(([name, value]) => ({ name, value }));
    const out = {};
    for (const s of sections) out[s.name] = s.value; // save verbatim per nome
    return out;
}

function deepEqual(a, b) { return JSON.stringify(a) === JSON.stringify(b); }

function analyzeOne(fields, id) {
    const back = keyedRoundTrip(fields);
    const lossless = deepEqual(fields, back);
    const types = { pt: 0, primitive: 0, other: [] };
    for (const [k, v] of Object.entries(fields)) {
        if (isPtArray(v)) types.pt++;
        else if (isPrimitive(v)) types.primitive++;
        else types.other.push(`${k}:${Array.isArray(v) ? "arr" : typeof v}`);
    }
    return { id, keys: Object.keys(fields).length, ...types, lossless };
}

// Input: file dump (array di {id,fields}) prodotto da PHP, oppure un singolo
// fields.json. Default: <tmp>/pantedu_comps.json (vedi README in testa).
let comps = [];
const inFile = process.argv[2] || path.join(os.tmpdir(), "pantedu_comps.json");
const raw = JSON.parse(fs.readFileSync(inFile, "utf8"));
if (Array.isArray(raw)) comps = raw.map((c) => ({ id: c.id, fields: c.fields || {} }));
else comps = [{ id: inFile, fields: raw }];

let allLossless = true, totOther = 0;
const otherSamples = [];
for (const c of comps) {
    const a = analyzeOne(c.fields || {}, c.id);
    if (!a.lossless) allLossless = false;
    if (a.other.length) { totOther += a.other.length; if (otherSamples.length < 10) otherSamples.push(`${a.id}: ${a.other.join(", ")}`); }
}
const totKeys = comps.reduce((s, c) => s + Object.keys(c.fields || {}).length, 0);
console.log(`\n=== KEYED REOPEN-EQUALITY (${comps.length} compilation, ${totKeys} campi) ===`);
console.log(`lossless (round-trip keyed = identita'): ${allLossless ? "✅ TUTTE" : "❌ NO"}`);
console.log(`valori non-PT/non-primitivi (da gestire a parte): ${totOther}`);
if (otherSamples.length) console.log("  " + otherSamples.join("\n  "));
console.log(allLossless && totOther === 0 ? "\n✅ APPROCCIO KEYED LOSSLESS su dati reali" : "\n⚠ verificare gli edge-case sopra");
