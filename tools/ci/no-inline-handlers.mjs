#!/usr/bin/env node
/**
 * CI guard — gestori di eventi in linea (on*=) in viste, classi PHP e moduli JS.
 *
 * Contesto (Track 7 CSP hardening, 2026-06-03): la CSP strict (nonce +
 * strict-dynamic) blocca gli handler inline (`script-src-attr`). Tutte le view
 * `.php` (rese dal front controller → middleware) sono state bonificate a ZERO
 * inline handler.
 *
 * 23/9/2026 (revisione architetturale A-19, R-3 passo 5) — la guardia guardava
 * solo `views/`, e fuori ne erano rimasti sei, tutti muti in produzione, dove
 * la CSP è rigorosa: il «✕ Esci» scritto da TemplateViewController, due
 * `this.select()` nella finestra della chiave di recupero del cruscotto, tre
 * `event.stopPropagation()` sulle caselle delle tabelle dell'editor. Adesso
 * guarda anche `app/` (le classi PHP che scrivono HTML) e `js/` (i moduli che
 * lo scrivono con innerHTML), e riconosce anche la forma
 * `setAttribute("onclick", …)`.
 *
 * Regole:
 *   1. ZERO tolleranza nei `.php` di views/ e app/ e nei `.js`/`.mjs` di js/.
 *      Le righe di commento (che cominciano con `*`, `//`, `/*` o `#`) non
 *      contano: lì un `onclick="…"` è un esempio, non un gestore.
 *   2. RATCHET nei file `.html` sotto views/ — può solo calare (baseline 0
 *      dal 23/9/2026).
 *   3. ECCEZIONI: una riga precisa, con il perché. Un'eccezione che non trova
 *      più la sua riga fa fallire la guardia (niente eccezioni morte).
 *
 * Provata nei due versi da tests/ops/gestori-in-linea.test.sh.
 * Uso: node tools/ci/no-inline-handlers.mjs [radice]   (radice: per le prove)
 */
import { readFileSync, readdirSync, statSync, existsSync } from "node:fs";
import { join, relative, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = process.argv[2]
    ? resolve(process.argv[2])
    : join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");

// Ratchet debito .html legacy. Era 1, tenuto su da views/admin/delete_temp.html,
// file MORTO non routato (/delete_temp.php → CronController, non la .html),
// tolto il 23/9/2026 (revisione A-45): da allora zero, come nel resto.
const HTML_BASELINE = 0;

/**
 * Righe che la guardia accetta, una per una, con il perché. Il testo è la riga
 * senza spazi in testa e in coda.
 */
const ECCEZIONI = [
    {
        file: "js/modules/risdoc/pt/html-sanitizer.js",
        riga: "'<p onclick=\"alert(1)\">x</p>',",
        perche: "vettore XSS del selfTest: è l'input che il sanificatore deve togliere",
    },
    {
        file: "js/modules/ui/ui-comp.js",
        riga: "label.setAttribute(\"onclick\", \"event.stopPropagation();\");",
        perche: "CheckSolSel di ui-comp.js: NON è nella catena morta tolta il 23/9/2026 (A-45), "
            + "la chiama setupProblemElements da CloneManager. La CSP rigorosa blocca questo gestore: "
            + "da convertire in addEventListener",
    },
    {
        file: "js/modules/ui/ui-comp.js",
        riga: "checkbox.setAttribute(\"onclick\", \"event.stopPropagation();\");",
        perche: "CheckSolSel di ui-comp.js: NON è nella catena morta tolta il 23/9/2026 (A-45), "
            + "la chiama setupProblemElements da CloneManager. La CSP rigorosa blocca questo gestore: "
            + "da convertire in addEventListener",
    },
];

const EVENTI = "click|change|submit|input|load|keyup|keydown|mouseover|mouseout|focus|blur|error|mousedown|mouseup|dblclick|contextmenu";
const HANDLER_RE = new RegExp(`\\son(?:${EVENTI})\\s*=\\s*\\\\?["']`, "gi");
const SET_ATTRIBUTE_RE = /setAttribute\(\s*["']on[a-z]+["']/gi;

/** @returns {number} quante occorrenze nella riga */
function occorrenze(riga) {
    return (riga.match(HANDLER_RE) || []).length + (riga.match(SET_ATTRIBUTE_RE) || []).length;
}

function eUnCommento(riga) {
    const t = riga.trim();
    return t.startsWith("*") || t.startsWith("//") || t.startsWith("/*") || t.startsWith("#");
}

function walk(dir, estensioni) {
    const out = [];
    if (!existsSync(dir)) return out;
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) out.push(...walk(p, estensioni));
        else if (estensioni.test(name) && !name.endsWith(".min.js")) out.push(p);
    }
    return out;
}

const PERIMETRO = [
    { cartella: "views", estensioni: /\.(php|html?)$/ },
    { cartella: "app", estensioni: /\.php$/ },
    { cartella: "js", estensioni: /\.(js|mjs)$/ },
];

let htmlCount = 0;
const hits = [];
const eccezioniUsate = new Set();

for (const { cartella, estensioni } of PERIMETRO) {
    for (const file of walk(join(ROOT, cartella), estensioni)) {
        const rel = relative(ROOT, file).replace(/\\/g, "/");
        const isHtml = /\.html?$/.test(rel);
        const lines = readFileSync(file, "utf8").split(/\r?\n/);
        lines.forEach((line, i) => {
            const n = occorrenze(line);
            if (!n) return;
            if (isHtml) {
                htmlCount += n;
                return;
            }
            if (eUnCommento(line)) return;
            const eccezione = ECCEZIONI.findIndex((e) => e.file === rel && e.riga === line.trim());
            if (eccezione >= 0) {
                eccezioniUsate.add(eccezione);
                return;
            }
            hits.push(`${rel}:${i + 1}  ${line.trim().slice(0, 90)}`);
        });
    }
}

let failed = false;

if (hits.length) {
    failed = true;
    console.error("\n✗ Gestori di eventi in linea in views/, app/ o js/ (la CSP rigorosa li blocca — vietati):");
    for (const h of hits) console.error(`    ${h}`);
    console.error("  → Converti in addEventListener nel modulo, o in un attributo data-fm-* (js/modules/core/declarative.js).");
}

const morte = ECCEZIONI.filter((_, i) => !eccezioniUsate.has(i));
if (morte.length) {
    failed = true;
    console.error("\n✗ Eccezioni che non trovano più la loro riga (toglile):");
    for (const e of morte) console.error(`    ${e.file}  ${e.riga}`);
}

if (htmlCount > HTML_BASELINE) {
    failed = true;
    console.error(`\n✗ Inline handler in .html aumentati: ${htmlCount} > baseline ${HTML_BASELINE}.`);
} else if (htmlCount < HTML_BASELINE) {
    console.log(`ℹ Inline handler .html scesi a ${htmlCount} (baseline ${HTML_BASELINE}). Abbassa HTML_BASELINE.`);
}

if (failed) process.exit(1);
console.log(`✓ no-inline-handlers OK — views/, app/, js/: 0 (eccezioni motivate: ${ECCEZIONI.length}), .html legacy: ${htmlCount}/${HTML_BASELINE} (ratchet).`);
