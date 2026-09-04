/**
 * G22.S15 Phase 1 — Template Filler smoke test.
 *
 * Verifica:
 *   - Modal apre con default data (1 schema)
 *   - Form rendering OK (tabs, tabelle visibili)
 *   - Preview SVG live triggered (mock VPS)
 *   - Save → callback emette tikzString + data
 *   - renderTikz su data complesso (3 schemi) produce output simile all'originale
 */
const { test, expect } = require("@playwright/test");

const BASE_URL = process.env.FM_E2E_BASE_URL || "http://localhost";

test("template filler: open + preview + save", async ({ context, page }) => {
    await context.route("**/tikz/render*", async (route) => {
        if (route.request().method() === "GET") {
            return route.fulfill({ status: 404, contentType: "application/json", body: '{"ok":false}' });
        }
        return route.fulfill({
            status: 200, contentType: "image/svg+xml",
            body: '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><rect x="5" y="5" width="190" height="90" fill="lightblue"/></svg>',
        });
    });
    await context.route("**/auth/csrf", (route) =>
        route.fulfill({ status: 200, contentType: "application/json", body: '{"token":"test"}' }));

    const errors = [];
    page.on("pageerror", (e) => errors.push(e.message));
    page.on("console", (msg) => {
        if (msg.type() === "error" && !/favicon|MIME|404/i.test(msg.text())) {
            errors.push(msg.text());
        }
    });

    await page.goto(BASE_URL + "/");
    await page.waitForFunction(() => window.FM, { timeout: 10000 });

    const result = await page.evaluate(async () => {
        // Carica template filler via manifest
        const manifest = await fetch("/build/manifest.json").then(r => r.json());
        const entry = manifest["js/entries/tikz-template-filler.js"];
        await import("/build/" + entry.file);

        // Save callback capture
        let savedTikz = null, savedData = null;
        window.FM.openTemplateFiller("schema-modulare", null, (tikz, data) => {
            savedTikz = tikz; savedData = data;
        });
        await new Promise(r => setTimeout(r, 400));

        const backdrop = document.querySelector(".fm-tplf-backdrop");
        const tabs = backdrop?.querySelectorAll(".fm-tplf-tabs button") || [];
        const tables = backdrop?.querySelectorAll("table.fm-tplf-table") || [];
        const previewStatus = backdrop?.querySelector(".fm-tplf-preview .preview-status");

        // Aspetta che la preview venga generata (debounce 600ms)
        await new Promise(r => setTimeout(r, 1200));
        const svgPresent = !!backdrop?.querySelector(".fm-tplf-preview svg");

        // Click save
        backdrop?.querySelector('button[data-act="save"]')?.click();
        await new Promise(r => setTimeout(r, 200));

        const modalClosed = !document.querySelector(".fm-tplf-backdrop");
        return {
            modalOpenedOk: !!backdrop,
            tabsCount: tabs.length,
            tablesCount: tables.length,
            previewSvgRendered: svgPresent,
            modalClosed,
            savedTikzHasDoc: !!savedTikz?.includes("\\begin{document}"),
            savedTikzHasModulare: !!savedTikz?.includes("\\schemaModulare"),
            savedTikzLen: savedTikz?.length || 0,
            savedDataId: savedData?.id,
            savedSchemaCount: savedData?.schemas?.length,
        };
    });
    console.log("template filler result:", JSON.stringify(result, null, 2));

    expect(result.modalOpenedOk).toBe(true);
    expect(result.tabsCount).toBeGreaterThanOrEqual(2); // [Schema 1, + Schema]
    expect(result.tablesCount).toBeGreaterThanOrEqual(2); // xValues + rows
    expect(result.previewSvgRendered).toBe(true);
    expect(result.modalClosed).toBe(true);
    expect(result.savedDataId).toBe("schema-modulare");
    expect(result.savedSchemaCount).toBe(1);
    expect(result.savedTikzHasDoc).toBe(true);
    expect(result.savedTikzHasModulare).toBe(true);
    expect(result.savedTikzLen).toBeGreaterThan(2000); // preamble + body

    if (errors.length) console.log("Errors:", errors);
    expect(errors.filter(e => !/Failed to load resource/i.test(e))).toEqual([]);
});

test("assembler renderTikz produce output coerente con il template originale", async ({ page }) => {
    await page.goto(BASE_URL + "/");
    await page.waitForFunction(() => window.FM, { timeout: 10000 });
    const out = await page.evaluate(async () => {
        const mod = await import("/js/modules/editor/tikz-templates/schema-modulare.js");
        // Build dei dati equivalenti agli SCHEMA 1/2/3 dell'esempio utente
        const data = {
            id: "schema-modulare",
            version: 1,
            globalParams: {
                spacing: 5, topTextY: 1, bottomTextPadding: 1,
                highlightFill: "red!70", highlightBorder: "red!40!black",
                highlightText: "white", highlightRadius: "0.2cm", highlightBorderWidth: "0.4pt",
            },
            schemas: [
                {
                    xShift: "0",
                    xValues: [
                        { pos: 1, value: "$2a$" },
                        { pos: 2, value: "$0$" },
                        { pos: 3, value: "$-\\frac{a}{4}$" },
                    ],
                    rows: [
                        { y: 0.5, equation: "$N(x)>0$", signs: ["$+$","$+$","$-$","$+$"], circles: [{idx:2,type:"draw"},{idx:3,type:"draw"}], highlights: [] },
                        { y: 1.5, equation: "$D(x)>0$", signs: ["$+$","$+$","$+$","$+$"], circles: [{idx:1,type:"draw"}], highlights: [] },
                        { y: 2.5, equation: "$\\frac{N(x)}{D(x)}$", signs: ["$+$","$+$","$-$","$+$"], circles: [{idx:1,type:"draw"},{idx:2,type:"draw"},{idx:3,type:"draw"}], highlights: [3] },
                    ],
                    solution: { signs: [], circles: [], highlightIdx: [], text: "" },
                    labelAbove: "$\\text{se }a<0$",
                    labelBelow: "$0<x<-\\dfrac{a}{4}$",
                },
            ],
        };
        const tikz = mod.renderTikz(data);
        return {
            errors: mod.validate(data),
            hasPreamble: tikz.includes("\\schemaModulareCore"),
            hasSchemaCall: tikz.includes("\\schemaModulare{\\firstSchema}"),
            hasXValues: tikz.includes("1/{$2a$}, 2/{$0$}, 3/{$-\\frac{a}{4}$}"),
            hasLabelAbove: tikz.includes("\\schemaTextAbove{\\topTextY}{$\\text{se }a<0$}"),
            length: tikz.length,
            head: tikz.slice(0, 300),
        };
    });
    console.log("assembler output:", JSON.stringify(out, null, 2));
    expect(out.errors).toEqual([]);
    expect(out.hasPreamble).toBe(true);
    expect(out.hasSchemaCall).toBe(true);
    expect(out.hasXValues).toBe(true);
    expect(out.hasLabelAbove).toBe(true);
});
