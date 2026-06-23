/**
 * Unit test per renameSvgIds + makeSvgPrefix in tikz-render-client.js.
 *
 * Background: dvisvgm emette IDs generici (`g0-1`, `g1-1`, `page1`, `cp0`).
 * Quando multiple SVG inline nella stessa pagina → collisione → glyph wrong.
 * Fix: prefix unico per istanza prima dell'embed.
 */
import { describe, it, expect } from "vitest";
import { renameSvgIds, makeSvgPrefix } from "../../js/modules/editor/tikz-render-client.js";

describe("renameSvgIds", () => {
    it("rinomina id attribute", () => {
        const out = renameSvgIds(`<path id='g1-1' d='M0'/>`, "P");
        expect(out).toBe(`<path id='P_g1-1' d='M0'/>`);
    });

    it("rinomina xlink:href reference", () => {
        const out = renameSvgIds(`<use xlink:href='#g1-1'/>`, "P");
        expect(out).toBe(`<use xlink:href='#P_g1-1'/>`);
    });

    it("rinomina href reference (no xlink prefix)", () => {
        const out = renameSvgIds(`<use href='#g1-1'/>`, "P");
        expect(out).toBe(`<use href='#P_g1-1'/>`);
    });

    it("rinomina url(#X) reference", () => {
        const out = renameSvgIds(`<rect clip-path='url(#cp0)'/>`, "P");
        expect(out).toBe(`<rect clip-path='url(#P_cp0)'/>`);
    });

    it("NON applica prefix doppio su xlink:href (regex unificata)", () => {
        // Regression: prima usavamo 2 regex separate (href + xlink:href) con \b
        // che matchavano entrambe il `xlink:href` → doppio prefix.
        const input = `<use xlink:href='#g1-2'/>`;
        const out = renameSvgIds(input, "P");
        expect(out).toBe(`<use xlink:href='#P_g1-2'/>`);
        // Conta occorrenze del prefix per essere sicuri non doppiato
        expect((out.match(/P_/g) || []).length).toBe(1);
    });

    it("preserva coerenza id/href: stessa coppia P_g1-1 in entrambi", () => {
        const svg = `<defs><path id='g1-1' d='M0'/></defs><use xlink:href='#g1-1'/>`;
        const out = renameSvgIds(svg, "P");
        expect(out).toContain(`id='P_g1-1'`);
        expect(out).toContain(`xlink:href='#P_g1-1'`);
    });

    it("supporta sia ' che \" come delimitatori", () => {
        const svg = `<path id="g1-1"/><use xlink:href="#g1-1"/>`;
        const out = renameSvgIds(svg, "P");
        expect(out).toBe(`<path id="P_g1-1"/><use xlink:href="#P_g1-1"/>`);
    });

    it("rinomina IDs multi-pattern (g0-N, g1-N, page1, cp0)", () => {
        const svg = `
            <defs>
                <clipPath id='cp0'><rect/></clipPath>
                <path id='g0-1'/>
                <path id='g1-1'/>
            </defs>
            <g id='page1'>
                <use xlink:href='#g0-1'/>
                <use href='#g1-1'/>
                <rect clip-path='url(#cp0)'/>
            </g>`;
        const out = renameSvgIds(svg, "X");
        expect(out).toContain(`id='X_cp0'`);
        expect(out).toContain(`id='X_g0-1'`);
        expect(out).toContain(`id='X_g1-1'`);
        expect(out).toContain(`id='X_page1'`);
        expect(out).toContain(`xlink:href='#X_g0-1'`);
        expect(out).toContain(`href='#X_g1-1'`);
        expect(out).toContain(`url(#X_cp0)`);
    });

    it("ritorna input invariato se prefix vuoto", () => {
        const svg = `<path id='g1-1'/>`;
        expect(renameSvgIds(svg, "")).toBe(svg);
    });

    it("ritorna input invariato se svg vuoto", () => {
        expect(renameSvgIds("", "P")).toBe("");
        expect(renameSvgIds(null, "P")).toBe(null);
    });
});

describe("makeSvgPrefix", () => {
    it("genera prefix con hash short + counter", () => {
        const p1 = makeSvgPrefix("abcdef1234567890");
        expect(p1).toMatch(/^tkabcdef_\d+$/);
    });

    it("counter cresce monotonicamente tra chiamate", () => {
        const p1 = makeSvgPrefix("hash1234");
        const p2 = makeSvgPrefix("hash1234");
        // Stesso hash, counter diverso → prefix diverso
        expect(p1).not.toBe(p2);
        // Extract counter parts
        const c1 = parseInt(p1.split("_").pop(), 10);
        const c2 = parseInt(p2.split("_").pop(), 10);
        expect(c2).toBe(c1 + 1);
    });

    it("gestisce hash undefined/null", () => {
        const p = makeSvgPrefix(undefined);
        expect(p).toMatch(/^tk_\d+$/);
    });
});
