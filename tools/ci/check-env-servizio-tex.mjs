#!/usr/bin/env node
/**
 * check-env-servizio-tex.mjs — ogni variabile d'ambiente che il servizio TeX
 * legge deve comparire nel suo `.env.example` (tools/tex-compile-vps), che
 * `provision.sh` copia in /opt/tex-compile/.env alla prima installazione.
 *
 * 23/9/2026 (revisione architetturale 2026-09, A-43) — il file elencava nove
 * variabili, e il servizio ne leggeva cinque in più: SVG_TO_PDF_TIMEOUT,
 * SVG_TO_PDF_DPI, TEX_COMPILE_LATEXINDENT_OFF, TEX_COMPILE_LATEXINDENT_TIMEOUT,
 * TIKZ_RENDER_TIMEOUT. Una chiave letta ma non scritta lì è una configurazione
 * che chi installa non sa di avere. Stessa regola di check-env-example.mjs,
 * per l'applicazione.
 *
 * Che cosa conta come lettura: `os.environ.get("X")`, `os.environ["X"]`,
 * `os.getenv("X")` nei file Python di `app/`, e `${X}` nelle unità systemd di
 * `systemd/`. Gli script di prova (`smoke_*.py`) non sono il servizio e non
 * si leggono. Una chiave documentata e mai letta è un avviso, non un errore
 * (come TEX_COMPILE_PORT, solo informativa).
 *
 * Uso: node tools/ci/check-env-servizio-tex.mjs   (uscita 1 se manca una chiave)
 *      SERVIZIO_TEX_DIR=<cartella> per provarlo su una copia (le prove in
 *      tests/js-unit/env-servizio-tex.test.js).
 */
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const RADICE = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");
const DIR = process.env.SERVIZIO_TEX_DIR || join(RADICE, "tools", "tex-compile-vps");

const LETTURE_PY = [
    /os\.environ\.get\(\s*["']([A-Z][A-Z0-9_]*)["']/g,
    /os\.environ\[\s*["']([A-Z][A-Z0-9_]*)["']\s*\]/g,
    /os\.getenv\(\s*["']([A-Z][A-Z0-9_]*)["']/g,
];
const LETTURE_UNITA = [/\$\{([A-Z][A-Z0-9_]*)\}/g];

/** @returns {string[]} i file sotto `dir` con l'estensione data */
function file(dir, estensione) {
    let esiti = [];
    let voci;
    try {
        voci = readdirSync(dir);
    } catch {
        return esiti;
    }
    for (const voce of voci) {
        const p = join(dir, voce);
        const st = statSync(p);
        if (st.isDirectory()) {
            esiti = esiti.concat(file(p, estensione));
        } else if (voce.endsWith(estensione)) {
            esiti.push(p);
        }
    }
    return esiti;
}

/** @type {Map<string, Set<string>>} chiave → file che la leggono */
const lette = new Map();
function leggi(percorso, regole) {
    const testo = readFileSync(percorso, "utf8");
    for (const re of regole) {
        re.lastIndex = 0;
        let m;
        while ((m = re.exec(testo)) !== null) {
            if (!lette.has(m[1])) lette.set(m[1], new Set());
            lette.get(m[1]).add(percorso.slice(DIR.length + 1));
        }
    }
}
for (const f of file(join(DIR, "app"), ".py")) leggi(f, LETTURE_PY);
for (const f of file(join(DIR, "systemd"), ".service")) leggi(f, LETTURE_UNITA);

let esempio;
try {
    esempio = readFileSync(join(DIR, ".env.example"), "utf8");
} catch {
    console.error(`ERRORE: ${join(DIR, ".env.example")} non si legge.`);
    process.exit(1);
}
const documentate = new Set();
for (const riga of esempio.split(/\r?\n/)) {
    const m = /^\s*#?\s*([A-Z][A-Z0-9_]+)\s*=/.exec(riga);
    if (m) documentate.add(m[1]);
}

// Un controllo che non trova nessuna lettura non ha guardato niente.
if (lette.size === 0) {
    console.error(`ERRORE: nessuna variabile letta in ${DIR}/app né nelle unità: il controllo non guarda niente.`);
    process.exit(1);
}

const mancanti = [...lette.keys()].filter((k) => !documentate.has(k)).sort();
const maiLette = [...documentate].filter((k) => !lette.has(k)).sort();

if (maiLette.length > 0) {
    console.log(`Avviso: ${maiLette.length} chiavi nel .env.example del servizio TeX che il servizio non legge: ${maiLette.join(", ")}`);
}
if (mancanti.length > 0) {
    console.error(`\nERRORE: ${mancanti.length} chiavi lette dal servizio TeX ma assenti dal suo .env.example:`);
    for (const k of mancanti) {
        console.error(`  ${k}  ←  ${[...lette.get(k)].join(", ")}`);
    }
    console.error("\nScrivile in tools/tex-compile-vps/.env.example (anche commentate: `# CHIAVE=valore`).");
    process.exit(1);
}
console.log(`OK: ${lette.size} chiavi lette dal servizio TeX, tutte nel suo .env.example.`);
