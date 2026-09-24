/**
 * `js/modules/security/tikz-geogebra-client-sanitize.js` in isolamento — vedi
 * `tests/js-unit/blocchi-tikz-geogebra-render-sicuro.test.js` per la prova
 * che il renderer dei blocchi la usa davvero (A-20, revisione architetturale
 * del 23/9/2026, voce 125 del registro del debito).
 *
 * `sanitizeGeogebraSvg` è stata riscritta il 23/9/2026: la prima versione (a
 * espressioni regolari, commit a4db86af) si aggirava con `<animate>`/`<set>`
 * che riscrivono un attributo a runtime, entità numeriche, spazi/tabulazioni
 * dentro lo schema URI e `<style>` con `@import`. Misurato PRIMA di
 * riscrivere: eseguita davvero quella versione (`git show a4db86af:js/
 * modules/security/tikz-geogebra-client-sanitize.js`) sugli otto vettori
 * qui sotto, in un file di prova a parte poi tolto — il payload sopravvive
 * in 7 casi su 8 (il solo `xlink:HREF` maiuscolo era già preso dal flag `i`
 * della regex). Ora fa il parsing XML vero (`image/svg+xml`, non
 * `text/html`: quest'ultima abbasserebbe gli attributi camelCase) con una
 * lista bianca di elementi — le prove sotto («vettori che aggiravano…»)
 * bastano da sole a dimostrarlo rosso-poi-verde: mutando la lista bianca o
 * la regola su `href` (misurato, poi ripristinato) tornano rosse.
 *
 * `image/svg+xml` in happy-dom (l'ambiente di questa suite, vitest.config.js):
 * verificato che `DOMParser`/`XMLSerializer` fanno parsing XML vero — non
 * abbassano `viewBox`/`preserveAspectRatio`, decodificano le entità
 * (numeriche comprese) PRIMA che il codice legga `.value`, e producono un
 * `<parsererror>` su XML malformato — quindi non serve un ambiente jsdom a
 * parte per questo file.
 */
import { describe, it, expect } from "vitest";
import { escapeTikzScriptClosing, sanitizeGeogebraSvg } from "../../js/modules/security/tikz-geogebra-client-sanitize.js";

describe("escapeTikzScriptClosing", () => {
    it("neutralizza un </script> letterale", () => {
        expect(escapeTikzScriptClosing("a</script>b")).toBe("a<\\/script>b");
    });

    it("è idempotente su un corpo già escapato", () => {
        const gia = escapeTikzScriptClosing("a</script>b");
        expect(escapeTikzScriptClosing(gia)).toBe(gia);
    });

    it("un corpo senza </ resta invariato", () => {
        expect(escapeTikzScriptClosing("\\draw (0,0) -- (1,1);")).toBe("\\draw (0,0) -- (1,1);");
    });

    it("stringa vuota o assente → stringa vuota", () => {
        expect(escapeTikzScriptClosing("")).toBe("");
        expect(escapeTikzScriptClosing(undefined)).toBe("");
        expect(escapeTikzScriptClosing(null)).toBe("");
    });
});

const SVG_NS_ATTR = 'xmlns="http://www.w3.org/2000/svg"';

describe("sanitizeGeogebraSvg — bonifiche di base", () => {
    it("toglie uno <script> intero", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><script>alert(1)</script><circle r="1"/></svg>`);
        expect(out).not.toContain("<script");
        expect(out).toContain("<circle");
    });

    it("toglie un <foreignObject> intero", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><foreignObject><body xmlns="http://www.w3.org/1999/xhtml" onload="x()">y</body></foreignObject><rect/></svg>`);
        expect(out).not.toContain("foreignObject");
        expect(out).toContain("<rect/>");
    });

    it("toglie gli attributi on* (event handler)", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR} onload="alert(1)"><rect onclick="x()"/></svg>`);
        expect(out).not.toMatch(/\son\w+\s*=/i);
    });

    it("toglie un href javascript: su un elemento non in lista bianca (l'intero <a>)", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><a href="javascript:alert(1)"><circle r="1"/></a></svg>`);
        expect(out).not.toContain("javascript:");
        expect(out).not.toContain("<a");
    });

    it("toglie uno style con expression()", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><rect style="width:expression(alert(1))"/></svg>`);
        expect(out).not.toContain("expression(");
    });

    it("lascia invariato un SVG pulito, attributi camelCase compresi", () => {
        const pulito = `<svg ${SVG_NS_ATTR} viewBox="0 0 10 10" preserveAspectRatio="xMidYMid"><circle r="1"/></svg>`;
        expect(sanitizeGeogebraSvg(pulito)).toBe(pulito);
    });

    it("stringa vuota o assente → stringa vuota", () => {
        expect(sanitizeGeogebraSvg("")).toBe("");
        expect(sanitizeGeogebraSvg(undefined)).toBe("");
    });
});

describe("sanitizeGeogebraSvg — input non valido → niente figura", () => {
    it("XML malformato (tag non chiuso) → stringa vuota", () => {
        expect(sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><circle r="1"></svg>`)).toBe("");
    });

    it("radice diversa da <svg> → stringa vuota", () => {
        expect(sanitizeGeogebraSvg("<foo>bar</foo>")).toBe("");
    });

    it("senza il namespace SVG → stringa vuota (niente figura è meglio di una non bonificata)", () => {
        expect(sanitizeGeogebraSvg('<svg><circle r="1"/></svg>')).toBe("");
    });
});

// I sei vettori sotto aggiravano la versione a espressioni regolari del
// 23/9/2026 (misurato eseguendo quella versione — vedi il commento in testa
// al file): con la versione attuale sono tutti rossi-poi-verdi.
describe("sanitizeGeogebraSvg — vettori che aggiravano la versione a espressioni regolari", () => {
    it("<animate> che riscrive href a runtime → l'elemento (e il suo <a>) sparisce", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><a href="#"><animate attributeName="href" to="javascript:alert(1)" /></a><circle r="1"/></svg>`);
        expect(out).not.toContain("animate");
        expect(out).not.toContain("javascript:");
        expect(out).toContain("<circle");
    });

    it("<set> che riscrive href a runtime → l'elemento (e il suo <a>) sparisce", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><a href="#x"><set attributeName="href" to="javascript:alert(1)"/></a><circle r="1"/></svg>`);
        expect(out).not.toContain("<set");
        expect(out).not.toContain("javascript:");
        expect(out).toContain("<circle");
    });

    it("entità numerica (&#106;avascript:) — il parser XML la decodifica, la bonifica la vede", () => {
        // L'intero <a> sparisce (non in lista bianca): qui si controlla
        // soprattutto che il payload decodificato non sopravviva altrove.
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><a href="&#106;avascript:alert(1)"><circle r="1"/></a></svg>`);
        expect(out).not.toContain("javascript:");
        expect(out).not.toContain("alert(1)");
    });

    it("schema URI con una tabulazione in mezzo (java\\tscript:)", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><a href="java\tscript:alert(1)"><circle r="1"/></a></svg>`);
        expect(out).not.toContain("alert(1)");
    });

    it("<style> con @import verso un host esterno → l'intero elemento sparisce", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><style>@import url("http://evil.example/x.css");</style><circle r="1"/></svg>`);
        expect(out).not.toContain("@import");
        expect(out).not.toContain("evil.example");
        expect(out).toContain("<circle");
    });

    it("un url(...) esterno (non a frammento) nello style, fuori da @import", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><rect style="fill:url(http://evil.example/x.svg#y)"/></svg>`);
        expect(out).not.toContain("evil.example");
    });
});

describe("sanitizeGeogebraSvg — href/xlink:href ristretti al frammento interno", () => {
    it("xlink:href maiuscolo con schema pericoloso, tolto comunque (per valore, non per nome)", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR} xmlns:xlink="http://www.w3.org/1999/xlink"><use xlink:HREF="javascript:alert(1)"/><circle r="1"/></svg>`);
        expect(out).not.toContain("javascript:");
    });

    it("<use href> verso un host esterno (protocol-relative) tolto", () => {
        const out = sanitizeGeogebraSvg(`<svg ${SVG_NS_ATTR}><use href="//evil.example/x.svg#y"/><circle r="1"/></svg>`);
        expect(out).not.toContain("evil.example");
        expect(out).toContain("<use/>");
    });

    it("<use href=\"#id\"> a un frammento interno resta", () => {
        const svg = `<svg ${SVG_NS_ATTR}><defs><circle id="c" r="1"/></defs><use href="#c"/></svg>`;
        expect(sanitizeGeogebraSvg(svg)).toBe(svg);
    });

    it("url(#id) nello style (gradiente) resta", () => {
        const svg = `<svg ${SVG_NS_ATTR}><defs><radialGradient id="g"/></defs><rect style="fill:url(#g)"/></svg>`;
        expect(sanitizeGeogebraSvg(svg)).toBe(svg);
    });
});

describe("sanitizeGeogebraSvg — una figura GeoGebra realistica esce identica", () => {
    it("viewBox, gradiente, use a frammento e testo sopravvivono intatti", () => {
        const svg = `<svg ${SVG_NS_ATTR} viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">`
            + `<defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">`
            + `<stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#000"/>`
            + `</linearGradient></defs>`
            + `<g stroke="#000" fill="none"><path d="M0 0L100 100"/></g>`
            + `<circle cx="10" cy="10" r="3" fill="url(#g1)"/>`
            + `<text x="5" y="5" font-size="10">Ciao</text>`
            + `</svg>`;
        expect(sanitizeGeogebraSvg(svg)).toBe(svg);
    });
});
