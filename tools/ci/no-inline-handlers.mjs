#!/usr/bin/env node
/**
 * CI guard — inline event handlers (on*=) nelle view.
 *
 * Contesto (Track 7 CSP hardening, 2026-06-03): la CSP strict (nonce +
 * strict-dynamic) blocca gli handler inline (`script-src-attr`). Tutte le view
 * `.php` (rese dal front controller → middleware) sono state bonificate a ZERO
 * inline handler. Resta debito solo in 2 file `.html` legacy serviti come
 * frammento/statico (`Elementi_Riservati.html` template editor injettato via
 * innerHTML — richiede delegation in un modulo; `delete_temp.html` non routato).
 *
 * Regole:
 *   1. ZERO tolleranza nei file `.php` (l'intera superficie resa dal middleware).
 *   2. RATCHET nei file `.html` sotto views/ — può solo calare (baseline 24).
 */
import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");
const VIEWS = join(ROOT, "views");

// Ratchet debito .html legacy. Resta solo views/admin/delete_temp.html (1),
// file MORTO non routato (/delete_temp.php → CronController, non la .html).
const HTML_BASELINE = 1;

const HANDLER_RE =
    /\son(click|change|submit|input|load|keyup|keydown|mouseover|mouseout|focus|blur|error|mousedown|mouseup|dblclick|contextmenu)\s*=\s*["']/gi;

function walk(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) out.push(...walk(p));
        else if (/\.(php|html?)$/.test(name)) out.push(p);
    }
    return out;
}

let htmlCount = 0;
const phpHits = [];

for (const file of walk(VIEWS)) {
    const rel = relative(ROOT, file).replace(/\\/g, "/");
    const isPhp = /\.php$/.test(rel);
    const lines = readFileSync(file, "utf8").split(/\r?\n/);
    lines.forEach((line, i) => {
        const m = line.match(HANDLER_RE);
        if (!m) return;
        if (isPhp) phpHits.push(`${rel}:${i + 1}  ${line.trim().slice(0, 90)}`);
        else htmlCount += m.length;
    });
}

let failed = false;

if (phpHits.length) {
    failed = true;
    console.error(`\n✗ Inline event handler(s) in view .php (superficie strict-CSP — vietati):`);
    for (const h of phpHits) console.error(`    ${h}`);
    console.error(`  → Converti in addEventListener (script nonce-ato co-locato) o data-* + delegation.`);
}

if (htmlCount > HTML_BASELINE) {
    failed = true;
    console.error(`\n✗ Inline handler in .html aumentati: ${htmlCount} > baseline ${HTML_BASELINE}.`);
} else if (htmlCount < HTML_BASELINE) {
    console.log(`ℹ Inline handler .html scesi a ${htmlCount} (baseline ${HTML_BASELINE}). Abbassa HTML_BASELINE.`);
}

if (failed) process.exit(1);
console.log(`✓ no-inline-handlers OK — .php: 0, .html legacy: ${htmlCount}/${HTML_BASELINE} (ratchet).`);
