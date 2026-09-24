#!/usr/bin/env node
/**
 * Copia in public/vendor/ le librerie che il browser carica come script
 * classici e che quindi non passano dal bundle Vite: MathJax 4 e il suo
 * font. Gira come `prebuild` di `npm run build` (quindi anche nel deploy).
 *
 * PERCHE' (2026-09-04)
 *   Fino a qui MathJax arrivava a runtime da un CDN (`mathjax@4`, senza
 *   fissaggio di versione ne' integrita'), e il font con lui. Un'istanza che
 *   dichiara «nessuna dipendenza SaaS» non puo' dipendere da un host terzo
 *   per rendere una formula, e il CSP doveva autorizzare quell'host per
 *   script, stili, font e fetch. Ora la versione la fissa package.json, i
 *   file vengono dal repository npm al build e il CSP resta su 'self'
 *   (revisione architetturale 2026-09, rilievo A4).
 *
 * COSA COPIA
 *   node_modules/mathjax/{tex-mml-chtml.js, core.js, loader.js, startup.js,
 *     a11y/, input/, output/, ui/, adaptors/, sre/}   → public/vendor/mathjax/
 *   node_modules/@mathjax/mathjax-stix2-font/{chtml*, mjs/, cjs/, def/, svg*, package.json}
 *                                                    → public/vendor/@mathjax/mathjax-stix2-font/
 *
 *   MathJax carica i componenti opzionali (assistive-mml, estensioni TeX)
 *   dalla cartella dello script principale e il font da
 *   `loader.paths.fonts` (`[fonts]/%%FONT%%-font`): per questo le due
 *   cartelle vanno copiate intere, non solo il file combinato. Le pagine le
 *   referenziano come /vendor/mathjax/tex-mml-chtml.js e impostano
 *   `loader.paths.fonts = '/vendor/@mathjax'` (views/partials/_mathjax_loader.php,
 *   _exercise_assets.php, js/entries/pdf-import.js).
 *
 *   Le cartelle di destinazione sono ignorate da git: si rigenerano a ogni
 *   build. Il file public/vendor/vendor-manifest.json riporta le versioni
 *   copiate, per il cache-bust in PHP.
 */
import { chmodSync, cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = join(fileURLToPath(new URL(".", import.meta.url)), "..", "..");
const NM = join(ROOT, "node_modules");
const OUT = join(ROOT, "public", "vendor");

/**
 * Permessi espliciti (chmod ignora la umask). Sul VPS la umask di sistema
 * e' 007: le cartelle appena create nascono 770 col gruppo dell'utente di
 * deploy, non con www-data, e nginx non le apriva: 404 sul loader e sul
 * font al primo deploy dopo il rilievo A4 (2026-09-04). Sono asset
 * pubblici: cartelle 755, file 644.
 */
function makeWorldReadable(path) {
    chmodSync(path, 0o755);
    for (const entry of readdirSync(path, { withFileTypes: true })) {
        const p = join(path, entry.name);
        if (entry.isDirectory()) makeWorldReadable(p);
        else chmodSync(p, 0o644);
    }
}

const PACKAGES = [
    {
        name: "mathjax",
        src: join(NM, "mathjax"),
        dest: join(OUT, "mathjax"),
        entries: [
            "tex-mml-chtml.js", "core.js", "loader.js", "startup.js",
            "a11y", "input", "output", "ui", "adaptors", "sre", "package.json", "LICENSE",
        ],
    },
    {
        name: "@mathjax/mathjax-stix2-font",
        src: join(NM, "@mathjax", "mathjax-stix2-font"),
        dest: join(OUT, "@mathjax", "mathjax-stix2-font"),
        entries: ["chtml", "chtml.js", "mjs", "cjs", "def", "svg", "svg.js",
            "tex-mml-chtml-mathjax-stix2.js", "package.json", "LICENSE"],
    },
];

const manifest = {};
for (const pkg of PACKAGES) {
    if (!existsSync(pkg.src)) {
        console.error(`[vendor-assets] manca ${pkg.name} in node_modules: esegui npm ci`);
        process.exit(1);
    }
    const version = JSON.parse(readFileSync(join(pkg.src, "package.json"), "utf8")).version;
    rmSync(pkg.dest, { recursive: true, force: true });
    mkdirSync(pkg.dest, { recursive: true });
    let copied = 0;
    for (const entry of pkg.entries) {
        const from = join(pkg.src, entry);
        if (!existsSync(from)) continue;
        cpSync(from, join(pkg.dest, entry), { recursive: true });
        copied++;
    }
    makeWorldReadable(pkg.dest);
    manifest[pkg.name] = version;
    console.log(`[vendor-assets] ${pkg.name}@${version}: ${copied} voci → ${pkg.dest.replace(ROOT, "")}`);
}

mkdirSync(OUT, { recursive: true });
writeFileSync(join(OUT, "vendor-manifest.json"), JSON.stringify(manifest, null, 2) + "\n");
chmodSync(join(OUT, "vendor-manifest.json"), 0o644);
console.log("[vendor-assets] manifest scritto: public/vendor/vendor-manifest.json");
