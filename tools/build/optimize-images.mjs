#!/usr/bin/env node
/**
 * Image pipeline — Phase Roadmap 11.
 *
 * Converte PNG/JPEG sorgenti in WebP + AVIF responsive multi-size
 * con metadata-strip (privacy GDPR — EXIF GPS).
 *
 * Run:    npm run build:images
 * Watch:  npm run build:images -- --watch
 *
 * Output:
 *   img/sources/foo.png  →  public/img/optimized/foo-{360,768,1280}.{webp,avif}
 *   manifest:            →  public/img/optimized/manifest.json
 *
 * Quality:
 *   WebP q=80 (good UX/size balance)
 *   AVIF q=65 (perceived equivalent, ~40% smaller)
 *
 * Dipendenza: `sharp` (NON ancora in package.json). Per usare:
 *   npm i -D sharp
 * Lo script si auto-skip se sharp non installato (no fail CI).
 */
import { readdirSync, statSync, mkdirSync, writeFileSync, existsSync } from "node:fs";
import { join, basename, extname, relative } from "node:path";

const SRC = "img/sources";
const DST = "public/img/optimized";
const SIZES = [360, 768, 1280];
const FORMATS = [
    { ext: "webp", opts: { quality: 80, effort: 4 } },
    { ext: "avif", opts: { quality: 65, effort: 4 } },
];

let sharp;
try {
    sharp = (await import("sharp")).default;
} catch {
    console.warn("[optimize-images] sharp non installato — skip. Run `npm i -D sharp` per abilitare.");
    process.exit(0);
}

if (!existsSync(SRC)) {
    console.warn(`[optimize-images] ${SRC} non esiste — niente da processare.`);
    process.exit(0);
}

mkdirSync(DST, { recursive: true });

const manifest = {};

function walk(dir) {
    const out = [];
    for (const name of readdirSync(dir)) {
        const p = join(dir, name);
        const st = statSync(p);
        if (st.isDirectory()) out.push(...walk(p));
        else if (/\.(png|jpe?g)$/i.test(name)) out.push(p);
    }
    return out;
}

const inputs = walk(SRC);
console.log(`[optimize-images] ${inputs.length} sorgenti trovate`);

for (const src of inputs) {
    const rel = relative(SRC, src).replace(/\\/g, "/");
    const stem = rel.replace(/\.[^.]+$/, "");
    const out = manifest[rel] = { src: rel, variants: [] };

    const meta = await sharp(src).metadata();
    const maxW = meta.width ?? 1280;

    // Picking sizes: usa SIZES standard se sorgente >=360, altrimenti
    // emette UNA variant alla dimensione originale (logo/icone piccole).
    const effectiveSizes = maxW < 360
        ? [maxW]
        : SIZES.filter((w) => w <= maxW * 1.1);
    if (effectiveSizes.length === 0) effectiveSizes.push(maxW);

    for (const w of effectiveSizes) {
        for (const fmt of FORMATS) {
            const outPath = join(DST, `${stem}-${w}.${fmt.ext}`);
            mkdirSync(join(DST, stem, "..").replace(/[^/\\]+$/, ""), { recursive: true });

            // Strip metadata, resize, encode
            const img = sharp(src).rotate().resize({ width: w, withoutEnlargement: true });
            if (fmt.ext === "webp") await img.webp(fmt.opts).toFile(outPath);
            else if (fmt.ext === "avif") await img.avif(fmt.opts).toFile(outPath);

            const size = statSync(outPath).size;
            out.variants.push({
                width: w,
                format: fmt.ext,
                path: `/img/optimized/${stem}-${w}.${fmt.ext}`,
                size,
            });
        }
    }
    console.log(`  ✓ ${rel} → ${out.variants.length} varianti`);
}

writeFileSync(join(DST, "manifest.json"), JSON.stringify(manifest, null, 2));
console.log(`[optimize-images] manifest scritto in ${DST}/manifest.json`);
