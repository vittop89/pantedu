#!/usr/bin/env node
/**
 * Bundle size budget enforcer (Phase 25.E1).
 *
 * Verifica che gli asset Vite buildati non superino i budget definiti.
 * Fail su CI se regression. Run dopo `npm run build`:
 *   node tools/ci/bundle-budget.mjs
 *
 * Budget motivati (aggiornati 2026-05-24 post Fase 3 perf — lazy split):
 *   - bootstrap.js          ≤ 150 kB gzip  (era 600 kB pre-lazy; misurato
 *                                            ~96 kB post-Fase 3e + Fase 4)
 *   - risdoc-pt-editor.js   ≤ 300 kB gzip
 *   - fm-router.js          ≤  20 kB gzip
 *   - checkin-handlers.js   ≤  60 kB gzip  (lazy chunk editor, 50 kB misurato)
 *   - editor-system.js      ≤  20 kB gzip
 *   - manifest.json         ≤  25 kB raw  (Fase 3 split = 35+ chunks ora;
 *                                          16.5 kB misurato, margine 1.5x)
 *
 * Quando un budget sta per essere superato, prima di alzarlo:
 *   1. Verifica che la crescita sia genuina (non dipendenze gonfie).
 *   2. Considera code-splitting (vedi vite.config.js rolldownOptions).
 *   3. Documenta motivo in `wiki/changelog.md` e bump budget.
 */

import { readFileSync, statSync } from "node:fs";
import { gzipSync } from "node:zlib";
import { join } from "node:path";

const BUILD_DIR = "public/build";
const MANIFEST  = join(BUILD_DIR, "manifest.json");

// Budget in BYTES (gzipped per .js, raw per .json).
// 2026-05-24 Fase 5 — budget aggiornati post lazy load (Fase 3a-3e + 4).
const BUDGETS = {
    "bootstrap":         { kind: "gzip",  maxBytes: 150 * 1024 },
    "risdoc-pt-editor":  { kind: "gzip",  maxBytes: 300 * 1024 },
    "fm-router":         { kind: "gzip",  maxBytes:  20 * 1024 },
    "checkin-handlers":  { kind: "gzip",  maxBytes:  60 * 1024 },
    "editor-system":     { kind: "gzip",  maxBytes:  20 * 1024 },
    "manifest.json":     { kind: "raw",   maxBytes:  25 * 1024 },
};

function gzipSize(path) {
    const buf = readFileSync(path);
    return gzipSync(buf, { level: 9 }).length;
}

function rawSize(path) {
    return statSync(path).size;
}

function fmt(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    return `${(bytes / 1024).toFixed(1)} kB`;
}

function readManifest() {
    try {
        return JSON.parse(readFileSync(MANIFEST, "utf8"));
    } catch (e) {
        console.error(`[bundle-budget] manifest.json non leggibile: ${e.message}`);
        console.error("[bundle-budget] hai eseguito `npm run build`?");
        process.exit(2);
    }
}

const manifest = readManifest();
let violations = 0;

// Manifest size
{
    const size = rawSize(MANIFEST);
    const budget = BUDGETS["manifest.json"];
    const ok = size <= budget.maxBytes;
    console.log(`${ok ? "✓" : "✗"} manifest.json  ${fmt(size).padStart(8)} / ${fmt(budget.maxBytes).padEnd(8)} (raw)`);
    if (!ok) violations++;
}

// Per-asset gzip size
const seen = new Set();
for (const entry of Object.values(manifest)) {
    const file = entry.file;
    if (!file || !file.endsWith(".js")) continue;
    if (seen.has(file)) continue;
    seen.add(file);

    // Match basename prefix (stripped hash) to budget key
    const basename = file.replace(/^assets\//, "").replace(/\.[A-Za-z0-9_-]+\.js$/, "");
    const budget = BUDGETS[basename];
    if (!budget) {
        console.log(`  · ${file} (no budget)`);
        continue;
    }

    const size = gzipSize(join(BUILD_DIR, file));
    const ok = size <= budget.maxBytes;
    const prefix = ok ? "✓" : "✗";
    console.log(`${prefix} ${basename.padEnd(20)} ${fmt(size).padStart(8)} / ${fmt(budget.maxBytes).padEnd(8)} (gzip)`);
    if (!ok) {
        violations++;
        const overflow = size - budget.maxBytes;
        console.log(`    sforamento: +${fmt(overflow)} (${((overflow / budget.maxBytes) * 100).toFixed(1)}%)`);
    }
}

if (violations > 0) {
    console.error(`\n[bundle-budget] ${violations} violazione/i. CI fail.`);
    console.error("Vedi commento in tools/ci/bundle-budget.mjs per la procedura di bump.");
    process.exit(1);
}

console.log("\n[bundle-budget] tutti i bundle entro budget.");
