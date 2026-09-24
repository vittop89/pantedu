#!/usr/bin/env node
/**
 * check-env-example.mjs — ogni variabile d'ambiente letta dal codice deve
 * comparire in `.env.example`, che e' il contratto di configurazione di
 * un'istanza (README, wiki/deployment). Una chiave letta ma non documentata
 * e' una configurazione invisibile a chi installa: il check la blocca.
 *
 * Cosa conta come "lettura": `$_ENV['KEY']`, `$_ENV["KEY"]`, `getenv('KEY')`,
 * `Config::booleanoDallAmbiente('KEY', …)`, `Config::testoDallAmbiente('KEY', …)`
 * nei sorgenti PHP di app/, routes/, views/, public/, tools/, config/, e
 * `process.env.KEY` in tools/ e vite.config.js.
 *
 * Le chiavi documentate ma mai lette sono segnalate come avviso (non
 * bloccano): possono essere lette in modo dinamico, o essere residui da
 * ripulire a mano.
 *
 * 2026-09-23 — e il `.env` versionato non contiene chiavi che il codice non
 * legge: questo invece blocca (revisione architetturale del 23/9, A-9). Il
 * rilascio monta quel file nel container di produzione, e una chiave morta
 * lì sembra configurare qualcosa che non esiste; tre ci sono rimaste per
 * settimane dopo che `.env.example` le aveva già tolte. Che cosa sta in quale
 * file: wiki/environment-variables.md. Se `.env` non c'è (la copia pubblica,
 * dove il sanitizer lo toglie) non si guarda.
 *
 * Uso: node tools/ci/check-env-example.mjs   (exit 1 se mancano chiavi)
 *      node tools/ci/check-env-example.mjs --env-versionato <file>
 *      (un altro file al posto di `.env`: serve alle prove della guardia)
 *
 * Revisione architetturale 2026-09, intervento P7.
 */
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, relative } from "node:path";

const ROOT = new URL("../../", import.meta.url).pathname.replace(/^\/([A-Za-z]:)/, "$1");
const SCAN_DIRS = ["app", "routes", "views", "public", "tools", "config"];
const SCAN_FILES = ["vite.config.js"];
const SKIP_DIRS = new Set(["node_modules", "vendor", "build", "drawio-app", "storage", "log"]);
// Script personali dello sviluppatore (tools/dev) e questo stesso check:
// le loro variabili non sono configurazione dell'istanza.
const SKIP_PATHS = ["tools/dev", "tools/ci"];

// Chiavi che il codice legge con nome costruito a runtime, o che servono
// solo a strumenti esterni: non le si trova con una grep letterale.
const KNOWN_DYNAMIC = new Set([
    "NODE_ENV",       // letta da Vite/npm, non dal codice applicativo
    // Variabili d'ambiente POSIX: le mette la shell, non la configurazione
    // dell'istanza. `tools/ops/visto.php` le legge solo per scrivere **chi**
    // ha segnato un'anomalia come vista. Documentarle in `.env.example`
    // suggerirebbe che si possano impostare per cambiare il comportamento del
    // programma, e non è vero: un elenco di configurazioni che contiene cose
    // che non sono configurazioni si smette di leggere.
    "SUDO_USER",
    "USER",
]);

// Chiavi documentate in anticipo per un'integrazione non ancora scritta:
// il codice le leggera' quando arrivera' (SPID/CIE, ADR sull'identita').
const RESERVED = new Set([
    "SPID_SP_ENTITY_ID", "SPID_SP_CERT_PATH", "SPID_SP_KEY_PATH", "SPID_SP_ORGANIZATION",
    "SPID_SP_TECH_CONTACT_EMAIL", "SPID_REQUESTED_ATTRIBUTES", "SPID_REQUESTED_AUTH_LEVEL",
    "CIE_SP_ENTITY_ID", "CIE_SP_CERT_PATH", "CIE_SP_KEY_PATH", "CIE_REQUESTED_AUTH_LEVEL",
]);

const PATTERNS = [
    /\$_ENV\[\s*['"]([A-Z][A-Z0-9_]+)['"]\s*\]/g,
    /getenv\(\s*['"]([A-Z][A-Z0-9_]+)['"]\s*\)/g,
    /process\.env\.([A-Z][A-Z0-9_]+)/g,
    // 23/9/2026 (A-35) — le letture dei file di app/Config passano da
    // App\Core\Config::booleanoDallAmbiente / testoDallAmbiente: una regola
    // sola per «vuoto», «sconosciuto» e i valori di un interruttore.
    /(?:booleano|testo)DallAmbiente\(\s*['"]([A-Z][A-Z0-9_]+)['"]/g,
];

function* walk(dir) {
    for (const entry of readdirSync(dir)) {
        if (SKIP_DIRS.has(entry)) continue;
        const full = join(dir, entry);
        const rel = relative(ROOT, full).replace(/\\/g, "/");
        if (SKIP_PATHS.some((p) => rel === p || rel.startsWith(p + "/"))) continue;
        const st = statSync(full);
        if (st.isDirectory()) {
            yield* walk(full);
        } else if (/\.(php|mjs|js|cjs|sh)$/.test(entry)) {
            yield full;
        }
    }
}

const used = new Map(); // KEY -> Set<file>
function scanFile(full) {
    const src = readFileSync(full, "utf8");
    for (const re of PATTERNS) {
        re.lastIndex = 0;
        let m;
        while ((m = re.exec(src)) !== null) {
            const key = m[1];
            if (!used.has(key)) used.set(key, new Set());
            used.get(key).add(relative(ROOT, full).replace(/\\/g, "/"));
        }
    }
}

for (const dir of SCAN_DIRS) {
    try { statSync(join(ROOT, dir)); } catch { continue; }
    for (const f of walk(join(ROOT, dir))) scanFile(f);
}
for (const f of SCAN_FILES) {
    try { scanFile(join(ROOT, f)); } catch { /* assente: ok */ }
}

const example = readFileSync(join(ROOT, ".env.example"), "utf8");
const documented = new Set();
for (const line of example.split(/\r?\n/)) {
    const m = /^\s*#?\s*([A-Z][A-Z0-9_]+)\s*=/.exec(line);
    if (m) documented.add(m[1]);
}

const missing = [...used.keys()].filter((k) => !documented.has(k) && !KNOWN_DYNAMIC.has(k)).sort();
const unused = [...documented].filter((k) => !used.has(k) && !KNOWN_DYNAMIC.has(k) && !RESERVED.has(k)).sort();

// Il `.env` versionato: solo chiavi che il codice legge. Si guardano le
// righe attive, non i commenti: una chiave commentata non la carica nessuno.
const argomento = process.argv.indexOf("--env-versionato");
const fileVersionato = argomento > -1 ? process.argv[argomento + 1] : join(ROOT, ".env");
let morte = [];
let versionatoLetto = false;
try {
    const versionato = readFileSync(fileVersionato, "utf8");
    versionatoLetto = true;
    const chiavi = new Set();
    for (const line of versionato.split(/\r?\n/)) {
        const m = /^\s*(?:export\s+)?([A-Z][A-Z0-9_]+)\s*=/.exec(line);
        if (m) chiavi.add(m[1]);
    }
    morte = [...chiavi].filter((k) => !used.has(k) && !KNOWN_DYNAMIC.has(k)).sort();
} catch {
    if (argomento > -1) {
        console.error(`ERRORE: non leggo ${fileVersionato}`);
        process.exit(1);
    }
    // `.env` assente, come nella copia pubblica: niente da guardare.
}

if (unused.length > 0) {
    console.log(`Avviso: ${unused.length} chiavi in .env.example che il codice non legge letteralmente:`);
    console.log("  " + unused.join(", "));
}
let errore = false;
if (missing.length > 0) {
    console.error(`\nERRORE: ${missing.length} chiavi lette dal codice ma assenti da .env.example:`);
    for (const k of missing) {
        console.error(`  ${k}  ←  ${[...used.get(k)].slice(0, 3).join(", ")}`);
    }
    console.error("\nDocumenta ogni chiave in .env.example (anche commentata: `# KEY=`).");
    errore = true;
}
if (morte.length > 0) {
    console.error(`\nERRORE: ${morte.length} chiavi nel .env versionato che il codice non legge:`);
    console.error("  " + morte.join(", "));
    console.error(
        "\nIl rilascio monta quel file in produzione: una chiave che nessuno legge si toglie "
        + "(wiki/environment-variables.md).",
    );
    errore = true;
}
if (errore) process.exit(1);
console.log(
    `OK: ${used.size} chiavi lette dal codice, tutte documentate in .env.example`
    + (versionatoLetto ? "; il .env versionato ne contiene solo di lette." : "; nessun .env versionato da guardare."),
);
