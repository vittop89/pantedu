#!/usr/bin/env node
/**
 * Controllo contrasti WCAG dei design token — problema M (2026-09-19).
 *
 * Legge css/tokens.css, estrae i valori dei token nel tema chiaro (:root)
 * e nel tema scuro — letto da TUTTI E TRE i blocchi in cui è duplicato a
 * mano (body.fm-dark, :root[data-theme="dark"], @media prefers-color-scheme;
 * vedi leggiTokenDaFile) — e verifica:
 *
 *   1. Testo/sfondo per le coppie principali >= 4,5:1 (WCAG 2.1 AA, 1.4.3),
 *      chiaro e scuro.
 *   2. Sidebar vs contenuto (superficie-su-superficie, non testo) >= 1,5:1
 *      nei due temi — la soglia scelta per il problema M (vedi
 *      css/tokens.css, commento su --fm-c-sidebar-bg).
 *   3. I tre blocchi scuri sono fra loro identici (stessiToken) — non solo
 *      "per costruzione", MISURATO a ogni run.
 *
 * Non e' collegato a `npm run ci` (nessuno script lo richiede): e' pensato
 * per girare a mano dopo aver toccato un token colore, e per le prove
 * vitest in tests/js-unit/contrasto-token.test.js, che importano le
 * funzioni pure esportate qui.
 *
 * Run:  node tools/audit/contrasto-token.mjs
 */
import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const __dirname = dirname(fileURLToPath(import.meta.url));
const TOKENS_PATH = join(__dirname, "..", "..", "css", "tokens.css");

/** "#rrggbb" | "#rgb" | "rgb(r g b[ / a%])" -> [r,g,b] (0-255). null se non riconosciuto. */
export function parseColor(raw) {
    const v = raw.trim();
    let m = /^#([0-9a-f]{6})$/i.exec(v);
    if (m) {
        const n = parseInt(m[1], 16);
        return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
    }
    m = /^#([0-9a-f]{3})$/i.exec(v);
    if (m) {
        const [r, g, b] = m[1].split("").map((c) => parseInt(c + c, 16));
        return [r, g, b];
    }
    m = /^rgb\(\s*(\d+)\s+(\d+)\s+(\d+)\s*(?:\/[^)]+)?\)$/i.exec(v);
    if (m) return [Number(m[1]), Number(m[2]), Number(m[3])];
    return null;
}

/** Luminanza relativa WCAG (0..1) da [r,g,b] 0-255. */
export function relativeLuminance([r, g, b]) {
    const lin = (c) => {
        const cs = c / 255;
        return cs <= 0.04045 ? cs / 12.92 : ((cs + 0.055) / 1.055) ** 2.4;
    };
    const [rl, gl, bl] = [lin(r), lin(g), lin(b)];
    return 0.2126 * rl + 0.7152 * gl + 0.0722 * bl;
}

/** Rapporto di contrasto WCAG fra due colori [r,g,b] (>= 1, ordine libero). */
export function contrastRatio(colorA, colorB) {
    const la = relativeLuminance(colorA);
    const lb = relativeLuminance(colorB);
    const [lighter, darker] = la >= lb ? [la, lb] : [lb, la];
    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Estrae { nomeToken: [r,g,b] } da un blocco CSS (contenuto fra { }).
 * Ignora i token il cui valore non è un colore riconosciuto (spacing,
 * ombre, gradienti…) — non sono nello scope di questo controllo.
 */
export function estraiTokenColore(bloccoCss) {
    const out = {};
    const re = /--([a-z0-9-]+)\s*:\s*([^;]+);/gi;
    let m;
    while ((m = re.exec(bloccoCss))) {
        const nome = m[1];
        const colore = parseColor(m[2]);
        if (colore) out[nome] = colore;
    }
    return out;
}

/**
 * True se due mappe { nomeToken: [r,g,b] } hanno le stesse chiavi con
 * esattamente gli stessi valori (usata per confrontare i blocchi scuri
 * duplicati a mano in tokens.css).
 */
export function stessiToken(a, b) {
    const chiaviA = Object.keys(a).sort();
    const chiaviB = Object.keys(b).sort();
    if (chiaviA.length !== chiaviB.length) return false;
    return chiaviA.every((nome, i) => {
        if (nome !== chiaviB[i]) return false;
        const [ra, ga, ba] = a[nome];
        const [rb, gb, bb] = b[nome];
        return ra === rb && ga === gb && ba === bb;
    });
}

/**
 * Legge css/tokens.css e restituisce { light, dark, darkMedia,
 * darkDataTheme }, ciascuno { nomeToken: [r,g,b] }.
 *
 * Il tema scuro è scritto TRE volte nel file sorgente, sincronizzate a
 * mano (vedi il commento sopra i blocchi, in tokens.css):
 *   - `@media (prefers-color-scheme: dark) { :root { ... } }` — preferenza
 *     di sistema, applicata da views/partials/head.php in modo sincrono
 *     prima del primo paint;
 *   - `:root[data-theme="dark"] { ... }` — override esplicito utente,
 *     stessa applicazione sincrona pre-paint;
 *   - `body.fm-dark { ... }` — toggle legacy, aggiunto da
 *     js/modules/bootstrap-compat.js a DOMContentLoaded (quindi DOPO il
 *     primo paint).
 * "dark" (il valore restituito, usato dai controlli sopra soglia) viene
 * da quest'ultimo per compatibilità con le chiamate esistenti, ma i tre
 * blocchi vengono letti e restituiti tutti: chi chiama può verificare che
 * non siano divergenti (tests/js-unit/contrasto-token.test.js lo fa). Un
 * controllo che leggesse solo `body.fm-dark` non si accorgerebbe di una
 * modifica futura che tocca solo uno degli altri due blocchi — proprio
 * nella finestra fra il primo paint e DOMContentLoaded.
 */
export function leggiTokenDaFile(path = TOKENS_PATH) {
    const css = readFileSync(path, "utf8");

    const rootMatch = /:root\s*\{([\s\S]*?)\n\}/.exec(css);
    if (!rootMatch) throw new Error("blocco :root non trovato in tokens.css");
    const light = estraiTokenColore(rootMatch[1]);

    const mediaMatch = /@media \(prefers-color-scheme: dark\)\s*\{\s*:root[^{]*\{([\s\S]*?)\n\s*\}\s*\n\}/.exec(css);
    if (!mediaMatch) throw new Error("blocco @media (prefers-color-scheme: dark) non trovato in tokens.css");
    const darkMedia = estraiTokenColore(mediaMatch[1]);

    const dataThemeMatch = /:root\[data-theme="dark"\]\s*\{([\s\S]*?)\n\}/.exec(css);
    if (!dataThemeMatch) throw new Error('blocco :root[data-theme="dark"] non trovato in tokens.css');
    const darkDataTheme = estraiTokenColore(dataThemeMatch[1]);

    const darkMatch = /body\.fm-dark\s*\{([\s\S]*?)\n\}/.exec(css);
    if (!darkMatch) throw new Error("blocco body.fm-dark non trovato in tokens.css");
    const dark = estraiTokenColore(darkMatch[1]);

    return { light, dark, darkMedia, darkDataTheme };
}

/** Coppie testo/sfondo da verificare >= 4.5:1 (WCAG AA testo normale). */
export const COPPIE_TESTO_SFONDO = [
    ["fm-c-text", "fm-c-bg", "testo principale sulla pagina"],
    ["fm-c-text", "fm-c-surface", "testo principale sulle card"],
    ["fm-c-text", "fm-c-sidebar-bg", "testo sidebar (problema M)"],
    ["fm-c-text", "fm-c-mappa-bg", "testo su mappe/esercizi (problema M, ex powderblue)"],
    ["fm-c-text-2", "fm-c-bg", "testo secondario sulla pagina"],
    ["fm-c-muted", "fm-c-bg", "testo attenuato sulla pagina"],
    ["fm-c-text", "fm-c-sec-mappe", "sidebar: sezione Mappe"],
    ["fm-c-text", "fm-c-sec-lab", "sidebar: sezione Laboratorio"],
    ["fm-c-text", "fm-c-sec-eser", "sidebar: sezione Esercizi"],
    ["fm-c-text", "fm-c-sec-verif", "sidebar: sezione Verifiche"],
    ["fm-c-text", "fm-c-sec-bes", "sidebar: sezione BES/DSA"],
    ["fm-c-text", "fm-c-sec-risdoc", "sidebar: sezione Risorse docente"],
];

/** Soglia scelta per la distinguibilità sidebar/contenuto (problema M). */
export const SOGLIA_SIDEBAR_CONTENUTO = 1.5;

function fmt(n) {
    return `${n.toFixed(2)}:1`;
}

function main() {
    const { light, dark, darkMedia, darkDataTheme } = leggiTokenDaFile();
    let fallito = false;

    console.log("=== Sincronia dei tre blocchi scuri ===");
    const sincMedia = stessiToken(dark, darkMedia);
    const sincDataTheme = stessiToken(dark, darkDataTheme);
    if (!sincMedia) fallito = true;
    if (!sincDataTheme) fallito = true;
    console.log(`  [${sincMedia ? "OK " : "FAIL"}] body.fm-dark == @media (prefers-color-scheme: dark)`);
    console.log(`  [${sincDataTheme ? "OK " : "FAIL"}] body.fm-dark == :root[data-theme="dark"]`);

    for (const tema of [["chiaro", light], ["scuro", dark]]) {
        const [nomeTema, token] = tema;
        console.log(`\n=== Tema ${nomeTema} — testo/sfondo (>= 4.5:1) ===`);
        for (const [fg, bg, etichetta] of COPPIE_TESTO_SFONDO) {
            if (!token[fg] || !token[bg]) {
                console.log(`  [SKIP] ${etichetta}: token mancante (${fg} o ${bg})`);
                continue;
            }
            const r = contrastRatio(token[fg], token[bg]);
            const ok = r >= 4.5;
            if (!ok) fallito = true;
            console.log(`  [${ok ? "OK " : "FAIL"}] ${etichetta}: var(--${fg}) su var(--${bg}) = ${fmt(r)}`);
        }

        console.log(`=== Tema ${nomeTema} — sidebar vs contenuto (>= ${SOGLIA_SIDEBAR_CONTENUTO}:1) ===`);
        const rs = contrastRatio(token["fm-c-sidebar-bg"], token["fm-c-bg"]);
        const oks = rs >= SOGLIA_SIDEBAR_CONTENUTO;
        if (!oks) fallito = true;
        console.log(`  [${oks ? "OK " : "FAIL"}] var(--fm-c-sidebar-bg) su var(--fm-c-bg) = ${fmt(rs)}`);
    }

    if (fallito) {
        console.error("\nControllo contrasti: FALLITO — almeno una coppia sotto soglia.");
        process.exit(1);
    }
    console.log("\nControllo contrasti: tutte le coppie sopra soglia.");
}

// Esegue solo se lanciato direttamente (non quando importato dai test).
if (import.meta.url === `file://${process.argv[1]}`) {
    main();
}
