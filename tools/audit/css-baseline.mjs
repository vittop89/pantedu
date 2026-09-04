#!/usr/bin/env node
/**
 * CSS Migration Baseline Auditor — Phase Roadmap 1.
 *
 * Misura stato attuale del codice CSS:
 *   - LOC per file
 *   - count !important (deprecation target)
 *   - count hex colors fuori tokens (deprecation target)
 *   - count inline style="…" in views/**.php
 *   - count BEM-compliant classi (.fm-*) vs legacy
 *
 * Output:
 *   - tools/audit/css-baseline.json (snapshot timestamped)
 *   - console table report
 *
 * Run:  node tools/audit/css-baseline.mjs
 */
import { readdirSync, readFileSync, statSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join, relative } from "node:path";

const ROOT = process.cwd();
const CSS_DIR = "css";
const VIEWS_DIR = "views";
const OUT_FILE = "tools/audit/css-baseline.json";

function walk(dir, ext) {
    const out = [];
    if (!existsSync(dir)) return out;
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) out.push(...walk(p, ext));
        else if (name.endsWith(ext)) out.push(p);
    }
    return out;
}

function analyzeCss(path) {
    const txt = readFileSync(path, "utf8");
    const lines = txt.split("\n").length;
    const important = (txt.match(/!important/g) || []).length;
    const hex = (txt.match(/#[0-9a-fA-F]{3,8}\b/g) || []).length;
    const tokens = (txt.match(/var\(--fm-/g) || []).length;
    const bemClasses = new Set();
    const legacyClasses = new Set();
    const allClasses = txt.match(/\.[a-zA-Z_-][\w-]*/g) || [];
    for (const c of allClasses) {
        if (c.match(/^\.fm-[a-z0-9]/)) bemClasses.add(c);
        else legacyClasses.add(c);
    }
    return {
        path: relative(ROOT, path),
        lines,
        important,
        hex,
        tokens,
        bemClasses: bemClasses.size,
        legacyClasses: legacyClasses.size,
        bytes: Buffer.byteLength(txt, "utf8"),
    };
}

function analyzeView(path) {
    const txt = readFileSync(path, "utf8");
    const inlineStyles = (txt.match(/style\s*=\s*["'][^"']*["']/g) || []).length;
    const fmClasses = (txt.match(/class\s*=\s*["'][^"']*\bfm-[a-z0-9][^"']*["']/g) || []).length;
    return {
        path: relative(ROOT, path),
        inlineStyles,
        fmClassUsage: fmClasses,
    };
}

const cssFiles = walk(CSS_DIR, ".css");
const cssReport = cssFiles.map(analyzeCss);

const viewFiles = [...walk(VIEWS_DIR, ".php")];
const viewReport = viewFiles.map(analyzeView).filter((v) => v.inlineStyles > 0);
viewReport.sort((a, b) => b.inlineStyles - a.inlineStyles);

const summary = {
    timestamp: new Date().toISOString(),
    css: {
        files: cssReport.length,
        totalLines: cssReport.reduce((s, r) => s + r.lines, 0),
        totalImportant: cssReport.reduce((s, r) => s + r.important, 0),
        totalHex: cssReport.reduce((s, r) => s + r.hex, 0),
        totalTokenUsage: cssReport.reduce((s, r) => s + r.tokens, 0),
        totalBytes: cssReport.reduce((s, r) => s + r.bytes, 0),
        bemClasses: cssReport.reduce((s, r) => s + r.bemClasses, 0),
        legacyClasses: cssReport.reduce((s, r) => s + r.legacyClasses, 0),
    },
    views: {
        filesWithInlineStyles: viewReport.length,
        totalInlineStyles: viewReport.reduce((s, r) => s + r.inlineStyles, 0),
        totalFmClassUsage: viewReport.reduce((s, r) => s + r.fmClassUsage, 0),
    },
    files: cssReport,
    topInlineStyleOffenders: viewReport.slice(0, 30),
};

mkdirSync("tools/audit", { recursive: true });
writeFileSync(OUT_FILE, JSON.stringify(summary, null, 2));

// Console report
console.log("\n=== Pantedu CSS Migration Baseline ===\n");
console.log(`Timestamp: ${summary.timestamp}\n`);

console.log("CSS files:");
const sorted = [...cssReport].sort((a, b) => b.lines - a.lines);
for (const r of sorted) {
    console.log(
        `  ${r.path.padEnd(40)} ${String(r.lines).padStart(5)} loc · ` +
            `${String(r.important).padStart(4)} !important · ` +
            `${String(r.hex).padStart(4)} hex · ` +
            `${String(r.tokens).padStart(4)} tokens · ` +
            `${(r.bytes / 1024).toFixed(1).padStart(6)} KB`,
    );
}

console.log("\nTotals:");
console.log(`  Lines:        ${summary.css.totalLines}`);
console.log(`  !important:   ${summary.css.totalImportant} (target: ~0 in modules)`);
console.log(`  Hex colors:   ${summary.css.totalHex} (target: only in tokens.css)`);
console.log(`  Token usage:  ${summary.css.totalTokenUsage}`);
console.log(`  BEM classes:  ${summary.css.bemClasses}`);
console.log(`  Legacy:       ${summary.css.legacyClasses}`);
console.log(`  Bytes raw:    ${(summary.css.totalBytes / 1024).toFixed(1)} KB`);

console.log("\nInline style audit (top 10 offenders):");
for (const v of viewReport.slice(0, 10)) {
    console.log(`  ${v.path.padEnd(50)} ${v.inlineStyles} inline style="…"`);
}
console.log(`\nTotal inline styles in views/: ${summary.views.totalInlineStyles}`);
console.log(`Total .fm-* class usages in views: ${summary.views.totalFmClassUsage}`);

console.log(`\nReport saved to: ${OUT_FILE}\n`);
