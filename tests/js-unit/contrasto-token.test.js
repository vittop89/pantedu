/**
 * Contrasti WCAG dei design token — problema M (2026-09-19).
 *
 * Tre livelli:
 *   - le funzioni pure (parseColor/relativeLuminance/contrastRatio) contro
 *     valori noti, cosi' un errore di formula si vede qui e non solo a
 *     valle su un token vero;
 *   - i token VERI letti da css/tokens.css, per le coppie principali
 *     testo/sfondo (>= 4.5:1 WCAG AA), per sidebar-vs-contenuto (>= 1.5:1,
 *     la soglia scelta per il problema M) e per la sincronia dei tre
 *     blocchi scuri duplicati a mano nel file sorgente;
 *   - un controllo statico sul sorgente JS (non CSS) del componente Lit
 *     che aveva ancora il fallback letterale "powderblue" — revisione
 *     indipendente sulla PR #149.
 *
 * Prova nei due versi (regola del progetto): un token che regredisce al
 * valore del difetto originale (sidebar identica al contenuto, 1:1), o un
 * blocco scuro che diverge dagli altri due, deve far fallire il controllo
 * — vedi i test "si accorge di una regressione".
 */
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { describe, test, expect } from "vitest";
import {
    parseColor, relativeLuminance, contrastRatio,
    leggiTokenDaFile, estraiTokenColore, stessiToken,
    COPPIE_TESTO_SFONDO, SOGLIA_SIDEBAR_CONTENUTO,
} from "../../tools/audit/contrasto-token.mjs";

const __dirname = dirname(fileURLToPath(import.meta.url));

describe("parseColor", () => {
    test("#rrggbb", () => {
        expect(parseColor("#1f2937")).toEqual([31, 41, 55]);
    });
    test("#rgb abbreviato", () => {
        expect(parseColor("#fff")).toEqual([255, 255, 255]);
    });
    test("rgb(r g b) senza alpha", () => {
        expect(parseColor("rgb(134 239 181)")).toEqual([134, 239, 181]);
    });
    test("rgb(r g b / a%) ignora l'alpha (non serve al confronto sfondo-su-sfondo)", () => {
        expect(parseColor("rgb(0 0 0 / 50%)")).toEqual([0, 0, 0]);
    });
    test("valori non colore (gradienti, var(), parole chiave CSS) -> null", () => {
        expect(parseColor("linear-gradient(180deg, red, blue)")).toBeNull();
        expect(parseColor("var(--fm-c-bg)")).toBeNull();
        expect(parseColor("transparent")).toBeNull();
    });
});

describe("relativeLuminance / contrastRatio", () => {
    test("bianco su nero = 21:1 (il massimo possibile)", () => {
        expect(contrastRatio([255, 255, 255], [0, 0, 0])).toBeCloseTo(21, 1);
    });
    test("un colore su se stesso = 1:1 (nessun contrasto)", () => {
        expect(contrastRatio([128, 128, 128], [128, 128, 128])).toBeCloseTo(1, 5);
    });
    test("l'ordine degli argomenti non cambia il risultato", () => {
        const a = contrastRatio([255, 255, 255], [15, 18, 24]);
        const b = contrastRatio([15, 18, 24], [255, 255, 255]);
        expect(a).toBeCloseTo(b, 10);
    });
    test("nero puro ha luminanza 0, bianco puro ha luminanza 1", () => {
        expect(relativeLuminance([0, 0, 0])).toBe(0);
        expect(relativeLuminance([255, 255, 255])).toBeCloseTo(1, 6);
    });
});

describe("estraiTokenColore", () => {
    test("legge solo i token che sono colori, ignora il resto", () => {
        const out = estraiTokenColore(`
            --fm-c-bg: #f5f7fb;
            --fm-space-4: 1rem;
            --fm-shadow: 0 4px 16px rgb(0 0 0 / 8%);
            --fm-c-danger-light: rgb(252 232 236);
        `);
        expect(out).toEqual({
            "fm-c-bg": [245, 247, 251],
            "fm-c-danger-light": [252, 232, 236],
        });
    });
});

describe("css/tokens.css — coppie testo/sfondo (WCAG AA, >= 4.5:1)", () => {
    const { light, dark } = leggiTokenDaFile();

    test.each(COPPIE_TESTO_SFONDO)("chiaro: %s su %s (%s)", (fg, bg) => {
        expect(light[fg], `token --${fg} non trovato in :root`).toBeDefined();
        expect(light[bg], `token --${bg} non trovato in :root`).toBeDefined();
        expect(contrastRatio(light[fg], light[bg])).toBeGreaterThanOrEqual(4.5);
    });

    test.each(COPPIE_TESTO_SFONDO)("scuro: %s su %s (%s)", (fg, bg) => {
        expect(dark[fg], `token --${fg} non trovato in body.fm-dark`).toBeDefined();
        expect(dark[bg], `token --${bg} non trovato in body.fm-dark`).toBeDefined();
        expect(contrastRatio(dark[fg], dark[bg])).toBeGreaterThanOrEqual(4.5);
    });
});

describe("css/tokens.css — sidebar distinguibile dal contenuto (problema M)", () => {
    const { light, dark } = leggiTokenDaFile();

    test(`chiaro: --fm-c-sidebar-bg su --fm-c-bg >= ${SOGLIA_SIDEBAR_CONTENUTO}:1`, () => {
        expect(contrastRatio(light["fm-c-sidebar-bg"], light["fm-c-bg"]))
            .toBeGreaterThanOrEqual(SOGLIA_SIDEBAR_CONTENUTO);
    });

    test(`scuro: --fm-c-sidebar-bg su --fm-c-bg >= ${SOGLIA_SIDEBAR_CONTENUTO}:1`, () => {
        expect(contrastRatio(dark["fm-c-sidebar-bg"], dark["fm-c-bg"]))
            .toBeGreaterThanOrEqual(SOGLIA_SIDEBAR_CONTENUTO);
    });

    test("si accorge di una regressione: --fm-c-sidebar-bg che tornasse a valere come --fm-c-bg è 1:1, sotto soglia", () => {
        // Il difetto originale (problema M): la sidebar riusava lo sfondo
        // pagina. Si riproduce passando a estraiTokenColore() — la STESSA
        // funzione con cui leggiTokenDaFile() legge i token veri sopra —
        // un frammento dove --fm-c-sidebar-bg vale come il --fm-c-bg REALE
        // (letto da tokens.css, non un valore a caso), cosi' la prova
        // esercita il nome del token e il percorso di lettura, non solo la
        // formula pura di contrastRatio (già coperta da "un colore su se
        // stesso" sopra: qui si vuole in più che regredire DAVVERO
        // --fm-c-sidebar-bg faccia scattare il controllo).
        const [r, g, b] = light["fm-c-bg"];
        const regredito = estraiTokenColore(`
            --fm-c-bg: rgb(${r} ${g} ${b});
            --fm-c-sidebar-bg: rgb(${r} ${g} ${b});
        `);
        expect(regredito["fm-c-sidebar-bg"]).toEqual(light["fm-c-bg"]);
        const rapporto = contrastRatio(regredito["fm-c-sidebar-bg"], regredito["fm-c-bg"]);
        expect(rapporto).toBe(1);
        expect(rapporto).toBeLessThan(SOGLIA_SIDEBAR_CONTENUTO);
    });
});

describe("css/tokens.css — i tre blocchi scuri restano sincronizzati", () => {
    // body.fm-dark, :root[data-theme="dark"] e @media (prefers-color-scheme:
    // dark) sono scritti tre volte a mano nel file sorgente (vedi il
    // commento sopra leggiTokenDaFile). Se una modifica futura tocca solo
    // uno dei tre, i controlli sopra (che leggono solo "dark" = body.fm-dark)
    // resterebbero verdi lo stesso: questa prova legge e confronta tutti e
    // tre, cosi' la sincronia è MISURATA a ogni run e non solo dichiarata
    // nel commento.
    const { dark, darkMedia, darkDataTheme } = leggiTokenDaFile();

    test("body.fm-dark == @media (prefers-color-scheme: dark)", () => {
        expect(stessiToken(dark, darkMedia)).toBe(true);
    });

    test('body.fm-dark == :root[data-theme="dark"]', () => {
        expect(stessiToken(dark, darkDataTheme)).toBe(true);
    });

    test("si accorge di una regressione: stessiToken() vede la differenza se un valore diverge", () => {
        const alterato = { ...dark, "fm-c-sidebar-bg": [0, 0, 0] };
        expect(stessiToken(dark, alterato)).toBe(false);
    });
});

describe("js/components/risdoc/fm-risdoc-section-header.js — nessun fallback powderblue", () => {
    // Problema M: "Powderblue sostituito ovunque comparisse" (descrizione
    // della PR) — ma il fallback CSS-in-JS di questo componente Lit era
    // rimasto letterale. Prova statica sul sorgente: costa meno di un test
    // che monta il componente in JSDOM (qui non serve DOM, il difetto è nel
    // testo del CSS-in-JS) e si accorge comunque se powderblue ricompare,
    // qui o altrove nello stesso file.
    const sorgente = readFileSync(
        join(__dirname, "..", "..", "js", "components", "risdoc", "fm-risdoc-section-header.js"),
        "utf8",
    );

    test("il file non contiene 'powderblue'", () => {
        expect(sorgente.toLowerCase()).not.toContain("powderblue");
    });
});
