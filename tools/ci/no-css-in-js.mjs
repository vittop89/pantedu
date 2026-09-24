#!/usr/bin/env node
/**
 * Guard ADR-023 Fase 5 — vieta il CSS-in-JS runtime.
 *
 * Fallisce (exit 1) se un sorgente JS inietta <style> a runtime:
 *   document.createElement('style')  / "style"
 *   <style ...> via innerHTML / insertAdjacentHTML
 *
 * Motivo: lo <style> iniettato è unlayered → batte il bundle @layer e la
 * sua posizione nel <head> dipende dal timing d'esecuzione del modulo →
 * cascade non-deterministica (flip visivi cache-dipendenti, incidente
 * 2026-05-25). Tutto il CSS statico va in css/modules/_*.css sotto @layer.
 * Stato dinamico → element.style.setProperty('--x', ...), non regole CSS.
 *
 * Uso: node tools/ci/no-css-in-js.mjs   (wired in `npm run ci`)
 *
 * Deroga esplicita: aggiungi il path a ALLOW con commento + motivo.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const ROOT = process.cwd();
const SCAN_DIR = join(ROOT, 'js');
const ALLOW = new Set([
    // (vuoto) — nessuna deroga. Aggiungere qui SOLO con motivazione.
]);

// createElement('style') | createElement("style")
const RE_CREATE = /createElement\(\s*['"]style['"]\s*\)/;
// innerHTML / insertAdjacentHTML con un tag <style ...>
const RE_INNER = /(innerHTML\s*=|insertAdjacentHTML\s*\()[^;]*<style[\s>]/;

function walk(dir, acc) {
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) walk(p, acc);
        else if (name.endsWith('.js')) acc.push(p);
    }
    return acc;
}

const offenders = [];
for (const file of walk(SCAN_DIR, [])) {
    const rel = relative(ROOT, file).replace(/\\/g, '/');
    if (ALLOW.has(rel)) continue;
    const src = readFileSync(file, 'utf8');
    const lines = src.split('\n');
    lines.forEach((line, i) => {
        if (RE_CREATE.test(line) || RE_INNER.test(line)) {
            offenders.push(`${rel}:${i + 1}: ${line.trim().slice(0, 100)}`);
        }
    });
}

if (offenders.length) {
    console.error('\n✗ ADR-023 Fase 5 — CSS-in-JS runtime vietato. Trovato:\n');
    offenders.forEach((o) => console.error('  ' + o));
    console.error('\nSposta il CSS in css/modules/_*.css (import in components.css, @layer).');
    console.error('Stato dinamico → element.style.setProperty(\'--x\', ...). Vedi ADR-023.\n');
    process.exit(1);
}
console.log('✓ ADR-023 Fase 5 — nessun CSS-in-JS runtime (scansionati js/**/*.js).');
