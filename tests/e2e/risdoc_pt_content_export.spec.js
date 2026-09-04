/**
 * Phase 24.36 — POST /api/teacher/content/{id}/export verifica TeX ZIP
 * generato da metadata.body_pt (cross-domain non-risdoc).
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");
const AdmZip = require("adm-zip");

test("teacher content export ZIP from body_pt", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const ptAst = [
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "Esercizio MARK_EXP body inline", marks: ["strong"] },
        ]},
        { _type: "checkboxGroup", renderMode: "checked-only",
          items: [
              { state: "x", label: "Risposta_A" },
              { state: "_", label: "Risposta_B" },
          ]},
    ];
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            type: "lab",
            subject: "MAT",
            indirizzo: "sc",
            classe: "2s",
            topic: "ExportTest-" + Date.now().toString(36),
            title: "Title MARK_TITLE ZIP test",
            visibility: "draft",
            metadata: JSON.stringify({ body_pt: ptAst }),
        }).toString(),
    });
    expect(create.ok()).toBeTruthy();
    const id = (await create.json()).id;

    try {
        const exp = await page.request.post(`/api/teacher/content/${id}/export`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, mode: "zip" }).toString(),
        });
        expect(exp.ok(), `export ${exp.status()}`).toBeTruthy();
        const j = await exp.json();
        expect(j.ok).toBeTruthy();
        expect(j.url).toBeTruthy();

        const zipRes = await page.request.get(j.url);
        expect(zipRes.ok()).toBeTruthy();
        const buf = await zipRes.body();
        const zip = new AdmZip(buf);
        const docEntry = zip.getEntries().find(e =>
            e.entryName.endsWith(".tex") && !e.entryName.endsWith("main.tex")
            && !e.entryName.includes("texCommon/")
        );
        expect(docEntry, "doc .tex in zip").toBeTruthy();
        const docTex = docEntry.getData().toString("utf8").replace(/\\_/g, "_");

        expect(docTex, "title section").toContain("MARK_TITLE");
        expect(docTex, "PT body bold").toContain("\\textbf{Esercizio MARK_EXP body inline}");
        expect(docTex, "checked-only itemize Risposta_A").toMatch(/\\item\s+Risposta_A/);
        expect(docTex, "Risposta_B unchecked NON in itemize").not.toMatch(/\\item\s+Risposta_B/);

        // main.tex + risdoc.sty + intestaLAteX_IIS.tex presenti
        const names = zip.getEntries().map(e => e.entryName);
        expect(names, "main.tex").toContain("main.tex");
        expect(names.some(n => n.endsWith("risdoc.sty")), "risdoc.sty").toBeTruthy();
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});
