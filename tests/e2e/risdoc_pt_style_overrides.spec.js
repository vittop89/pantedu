/**
 * Phase 24.30 — verify style overrides applied to risdoc.sty in ZIP export.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");
const AdmZip = require("adm-zip");

test.describe("style overrides → risdoc.sty", () => {
    test("colors injected into sty", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);
        const tj = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }

        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const body = new URLSearchParams({
            _csrf: csrf,
            mode: "zip",
            form_state: JSON.stringify({
                fields: {},
                state: {
                    indirizzo: "sc", classe: "2s", disciplina: "MAT",
                    styleOverrides: {
                        sectionboxBg:     "#ff0000",
                        sectionboxBorder: "#00ff00",
                        titleText:        "#0000ff",
                    },
                },
            }),
        });
        const r = await page.request.post(`/api/risdoc/templates/${piano.id}/export`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: body.toString(),
        });
        expect(r.ok()).toBeTruthy();
        const j = await r.json();
        const buf = await (await page.request.get(j.url)).body();
        const zip = new AdmZip(buf);
        const sty = zip.getEntries().find(e => e.entryName.endsWith("risdoc.sty"));
        expect(sty, "sty in zip").toBeTruthy();
        const styContent = sty.getData().toString("utf8");

        // Override injected with RGB values
        expect(styContent, "Phase 24.30 marker").toContain("Phase 24.30");
        expect(styContent, "colorBackTitleSec rgb(255,0,0)")
            .toMatch(/\\definecolor\{colorBackTitleSec\}\{RGB\}\{255,0,0\}/);
        expect(styContent, "borderColor rgb(0,255,0)")
            .toMatch(/\\definecolor\{borderColor\}\{RGB\}\{0,255,0\}/);
        expect(styContent, "titleTextColor rgb(0,0,255)")
            .toMatch(/\\definecolor\{titleTextColor\}\{RGB\}\{0,0,255\}/);
    });
});
