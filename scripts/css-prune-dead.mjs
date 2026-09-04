#!/usr/bin/env node
/**
 * CSS Prune Dead — rimuove rules CSS che usano solo classi legacy DEAD.
 *
 * Strategia parser fallback (no postcss dependency richiesta):
 *   - Tokenize CSS in rule blocks (selector { decl } )
 *   - Per ogni rule, parse selectors (split su `,` rispettando paren)
 *   - Selector si considera "dead-only" sse TUTTI i suoi class tokens
 *     (estratti da `.classname`) sono nella DEAD list
 *   - Se TUTTI i selettori di una rule sono dead-only → rimuovi rule
 *   - Se MISTI → rimuovi solo selettori dead, mantieni rule
 *
 * Usage:
 *   node scripts/css-prune-dead.mjs [--dry-run] [--batch=N] [--list=FILE]
 *
 * Flags:
 *   --dry-run     No file change; output report
 *   --batch=N     Process only first N classes from DEAD list (for granular commit)
 *   --list=PATH   DEAD list TSV (default: docs/analysis/sprint-h-dead-final.tsv)
 *   --files=GLOB  CSS files target (default: pre-defined legacy CSS files)
 *
 * Output (always):
 *   docs/analysis/sprint-h-dead-applied-<timestamp>.md  (report)
 *
 * Exit codes:
 *   0 ok / 1 errors / 2 invalid args
 */

import { readFileSync, writeFileSync, existsSync, mkdirSync } from "node:fs";
import { join, dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const __dirname = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(__dirname, "..");

// ---- Args ----
const args = process.argv.slice(2);
const flag = (name, def = null) => {
    const k = args.find((a) => a === `--${name}` || a.startsWith(`--${name}=`));
    if (!k) return def;
    if (k === `--${name}`) return true;
    return k.split("=").slice(1).join("=");
};
const DRY_RUN = !!flag("dry-run", false);
const BATCH = Number(flag("batch", 0)) || 0;
const LIST_PATH = flag("list", "docs/analysis/sprint-h-dead-final.tsv");
const FILES_OVERRIDE = flag("files", null);

// ---- Config: target CSS files ----
const LEGACY_CSS_FILES = FILES_OVERRIDE
    ? FILES_OVERRIDE.split(",")
    : [
          "css/modules/_admin-legacy.css",
          "css/modules/_editor-builder.css",
          "css/modules/_exercise-legacy.css",
          "css/modules/_shell-legacy.css",
          "css/admin.css",
          "css/layout.css",
          "css/layout_es.css",
          "css/layout_editor.css",
          "css/shell.css",
          "css/waf.css",
      ];

// ---- Load DEAD list ----
const listPath = resolve(ROOT, LIST_PATH);
if (!existsSync(listPath)) {
    console.error(`[prune] DEAD list not found: ${listPath}`);
    process.exit(2);
}
let deadClasses = readFileSync(listPath, "utf-8")
    .split(/\r?\n/)
    .map((s) => s.trim())
    .filter((s) => s && s !== "class");
if (BATCH > 0) deadClasses = deadClasses.slice(0, BATCH);
const deadSet = new Set(deadClasses);
console.log(`[prune] DEAD classes loaded: ${deadClasses.length}${BATCH ? ` (batch=${BATCH})` : ""}`);
console.log(`[prune] Mode: ${DRY_RUN ? "DRY-RUN" : "APPLY"}`);

// ---- CSS parser (regex-based, sufficient for our legacy CSS) ----
const RX_CLASS_IN_SELECTOR = /\.([a-zA-Z_][\w-]*)/g;

function splitSelectorList(s) {
    // Split top-level commas (no parens depth needed for CSS selectors typically)
    const parts = [];
    let buf = "";
    let depth = 0;
    for (const ch of s) {
        if (ch === "(" || ch === "[") depth++;
        else if (ch === ")" || ch === "]") depth--;
        else if (ch === "," && depth === 0) {
            parts.push(buf.trim());
            buf = "";
            continue;
        }
        buf += ch;
    }
    if (buf.trim()) parts.push(buf.trim());
    return parts;
}

function selectorIsDeadOnly(sel) {
    // Selector "dead-only" sse contiene >=1 class token e TUTTI sono in dead set
    const classes = [...sel.matchAll(RX_CLASS_IN_SELECTOR)].map((m) => m[1]);
    if (classes.length === 0) return false; // No class token = element/pseudo only, NOT dead
    return classes.every((c) => deadSet.has(c));
}

function pruneCss(text) {
    // Tokenize into blocks: { rule | atrule | comment }
    // Simple state machine: walk char-by-char tracking depth + string state.
    const out = [];
    const stats = { rulesRemoved: 0, selectorsTrimmed: 0, locBefore: text.split("\n").length };
    let i = 0;
    const n = text.length;

    function readUntil(predicate) {
        const start = i;
        let stringChar = null;
        let inComment = false;
        while (i < n) {
            const ch = text[i];
            if (inComment) {
                if (ch === "*" && text[i + 1] === "/") {
                    inComment = false;
                    i += 2;
                    continue;
                }
                i++;
                continue;
            }
            if (stringChar) {
                if (ch === "\\") {
                    i += 2;
                    continue;
                }
                if (ch === stringChar) stringChar = null;
                i++;
                continue;
            }
            if (ch === "/" && text[i + 1] === "*") {
                inComment = true;
                i += 2;
                continue;
            }
            if (ch === '"' || ch === "'") {
                stringChar = ch;
                i++;
                continue;
            }
            if (predicate(ch)) break;
            i++;
        }
        return text.slice(start, i);
    }

    while (i < n) {
        // Skip whitespace/comments while collecting them
        const wsStart = i;
        while (i < n && /\s/.test(text[i])) i++;
        if (i > wsStart) out.push(text.slice(wsStart, i));

        // Comment block
        if (text[i] === "/" && text[i + 1] === "*") {
            const cmtStart = i;
            i += 2;
            while (i < n) {
                if (text[i] === "*" && text[i + 1] === "/") {
                    i += 2;
                    break;
                }
                i++;
            }
            out.push(text.slice(cmtStart, i));
            continue;
        }

        if (i >= n) break;

        // At-rule (@media, @keyframes, @import)
        if (text[i] === "@") {
            const start = i;
            // Read until { or ;
            const prelude = readUntil((c) => c === "{" || c === ";");
            if (text[i] === ";") {
                i++;
                out.push(text.slice(start, i));
                continue;
            }
            // Has block
            if (text[i] === "{") {
                // Read balanced braces
                let depth = 1;
                i++;
                const blockStart = i;
                while (i < n && depth > 0) {
                    const ch = text[i];
                    if (ch === "{") depth++;
                    else if (ch === "}") depth--;
                    if (depth === 0) break;
                    i++;
                }
                const blockBody = text.slice(blockStart, i);
                i++; // skip }
                // Recursively prune inside block (e.g. @media nested rules)
                const innerPruned = pruneCss(blockBody);
                stats.rulesRemoved += innerPruned.stats.rulesRemoved;
                stats.selectorsTrimmed += innerPruned.stats.selectorsTrimmed;
                // If inner became empty (except whitespace), drop the at-rule entirely
                // UNLESS it's @keyframes/@font-face (those have declarations not rules)
                const atName = prelude.match(/^@(\S+)/)?.[1] || "";
                const innerStripped = innerPruned.text.replace(/\s/g, "");
                const isDeclarationAtRule = /^(keyframes|-webkit-keyframes|-moz-keyframes|font-face|page|counter-style|property)$/.test(atName);
                if (innerStripped === "" && !isDeclarationAtRule) {
                    stats.rulesRemoved++;
                    continue; // drop
                }
                out.push(prelude + "{" + innerPruned.text + "}");
                continue;
            }
        }

        // Regular rule: selector { decl }
        const selStart = i;
        const selectorRaw = readUntil((c) => c === "{" || c === "}");
        if (text[i] !== "{") {
            // Stray content; just emit
            if (selectorRaw.trim()) out.push(selectorRaw);
            if (text[i] === "}") {
                out.push("}");
                i++;
            }
            continue;
        }
        // Read body { ... }
        let depth = 1;
        i++;
        const bodyStart = i;
        while (i < n && depth > 0) {
            const ch = text[i];
            if (ch === "{") depth++;
            else if (ch === "}") depth--;
            if (depth === 0) break;
            i++;
        }
        const body = text.slice(bodyStart, i);
        i++; // skip }

        // Decide fate of selector list
        const selectors = splitSelectorList(selectorRaw);
        const keep = selectors.filter((s) => !selectorIsDeadOnly(s));
        if (keep.length === 0) {
            // All selectors dead → remove rule entirely
            stats.rulesRemoved++;
            stats.selectorsTrimmed += selectors.length;
        } else if (keep.length < selectors.length) {
            stats.selectorsTrimmed += selectors.length - keep.length;
            out.push(keep.join(",\n") + " {" + body + "}");
        } else {
            // Keep whole rule verbatim (preserve original formatting)
            out.push(text.slice(selStart, i));
        }
    }

    stats.locAfter = out.join("").split("\n").length;
    return { text: out.join(""), stats };
}

// ---- Process files ----
const report = [];
let totalRulesRemoved = 0;
let totalSelectorsTrimmed = 0;
let totalLocSaved = 0;

for (const rel of LEGACY_CSS_FILES) {
    const abs = resolve(ROOT, rel);
    if (!existsSync(abs)) {
        report.push({ file: rel, skipped: "not-found" });
        continue;
    }
    const before = readFileSync(abs, "utf-8");
    const { text: after, stats } = pruneCss(before);
    const locSaved = stats.locBefore - stats.locAfter;
    report.push({
        file: rel,
        rulesRemoved: stats.rulesRemoved,
        selectorsTrimmed: stats.selectorsTrimmed,
        locBefore: stats.locBefore,
        locAfter: stats.locAfter,
        locSaved,
    });
    totalRulesRemoved += stats.rulesRemoved;
    totalSelectorsTrimmed += stats.selectorsTrimmed;
    totalLocSaved += locSaved;
    if (!DRY_RUN && (stats.rulesRemoved > 0 || stats.selectorsTrimmed > 0)) {
        writeFileSync(abs, after, "utf-8");
        console.log(`[prune] ${rel}: -${stats.rulesRemoved} rules, -${stats.selectorsTrimmed} selectors, -${locSaved} LOC`);
    } else if (DRY_RUN) {
        console.log(`[dry] ${rel}: would remove ${stats.rulesRemoved} rules, trim ${stats.selectorsTrimmed} selectors, -${locSaved} LOC`);
    }
}

// ---- Write report ----
const ts = new Date().toISOString().slice(0, 19).replace(/[:T]/g, "-");
const reportPath = resolve(ROOT, `docs/analysis/sprint-h-dead-${DRY_RUN ? "dryrun" : "applied"}-${ts}.md`);
mkdirSync(dirname(reportPath), { recursive: true });

const md = [];
md.push(`# Sprint H-DEAD ${DRY_RUN ? "Dry-run" : "Applied"} Report`);
md.push("");
md.push(`**Date**: ${new Date().toISOString()}`);
md.push(`**Mode**: ${DRY_RUN ? "DRY-RUN (no file change)" : "APPLY"}`);
md.push(`**DEAD classes processed**: ${deadClasses.length}`);
md.push(`**Batch size**: ${BATCH || "all"}`);
md.push("");
md.push("## Aggregate");
md.push(`- Rules removed: **${totalRulesRemoved}**`);
md.push(`- Selectors trimmed (in mixed rules): **${totalSelectorsTrimmed}**`);
md.push(`- LOC saved: **${totalLocSaved}**`);
md.push("");
md.push("## Per file");
md.push("");
md.push("| File | Rules removed | Selectors trimmed | LOC before | LOC after | LOC saved |");
md.push("|---|---|---|---|---|---|");
for (const r of report) {
    if (r.skipped) {
        md.push(`| ${r.file} | — | — | — | — | (${r.skipped}) |`);
        continue;
    }
    md.push(`| ${r.file} | ${r.rulesRemoved} | ${r.selectorsTrimmed} | ${r.locBefore} | ${r.locAfter} | ${r.locSaved} |`);
}
md.push("");
md.push("## DEAD classes processed");
md.push("");
md.push("```");
md.push(deadClasses.join("\n"));
md.push("```");

writeFileSync(reportPath, md.join("\n"), "utf-8");
console.log(`[prune] Report: ${reportPath}`);
console.log(`[prune] Total: -${totalRulesRemoved} rules, -${totalSelectorsTrimmed} selectors, -${totalLocSaved} LOC`);

if (DRY_RUN) {
    console.log(`[prune] DRY-RUN complete. Apply with: node scripts/css-prune-dead.mjs --batch=${BATCH || deadClasses.length}`);
}
