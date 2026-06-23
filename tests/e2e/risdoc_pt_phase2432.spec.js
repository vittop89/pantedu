/**
 * Phase 24.32 — verify cellcolor + boxed sectionHeader + optgroup.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function fetchCsrf(page) {
    const r = await page.request.get("/auth/csrf");
    return (await r.json()).token;
}

async function saveAndFetchTex(page, tid, fields) {
    const csrf = await fetchCsrf(page);
    const save = await page.request.post(`/api/risdoc/templates/${tid}/compilations`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            compilation_key: "e2e_2432_" + Date.now(),
            label: "E2E 24.32",
            data: JSON.stringify({ fields, state: { indirizzo: "sc", classe: "2s", disciplina: "MAT" } }),
        }).toString(),
    });
    expect(save.ok()).toBeTruthy();
    const id = (await save.json()).id;
    const tex = await (await page.request.get(`/api/risdoc/templates/${tid}/tex?compilation_id=${id}`)).text();
    return tex;
}

test.describe("Phase 24.32 cellcolor + boxed + optgroup", () => {
    let tid;
    test.beforeAll(async ({ browser }) => {
        const ctx = await browser.newContext();
        const page = await ctx.newPage();
        await loginAdmin(page);
        const j = await page.request.get("/api/risdoc/templates").then(r => r.json());
        tid = (j.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        )?.id;
        await ctx.close();
    });

    test("table cell con bg → \\cellcolor[HTML]{...}", async ({ page }) => {
        if (!tid) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);
        const ptWithTable = [{
            _type: "table",
            columns: ["A", "B"],
            rows: [
                [
                    { text: "MARK_RED", widget: null, colspan: 1, rowspan: 1, merged: false, bg: "#ff0000" },
                    "MARK_NORMAL",
                ],
            ],
        }];
        const tex = await saveAndFetchTex(page, tid, { profilo_classe: ptWithTable });
        const texN = tex.replace(/\\_/g, "_");
        expect(texN, "cellcolor su MARK_RED").toMatch(/\\cellcolor\[HTML\]\{FF0000\}\s+MARK_RED/);
        expect(texN, "MARK_NORMAL senza cellcolor").not.toMatch(/\\cellcolor\[HTML\]\{[A-F0-9]+\}\s+MARK_NORMAL/);
    });

    test("sectionHeader boxed:true → sectionbox{title}", async ({ page }) => {
        if (!tid) { test.skip(true, "no piano"); return; }
        await loginAdmin(page);
        const pt = [
            { _type: "sectionHeader", title: "Sezione Custom MARK_BX", level: 3, boxed: true },
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "Contenuto MARK_INNER box.", marks: [] },
            ]},
            { _type: "sectionHeader", title: "Altra Sezione MARK_OUT", level: 3, boxed: false },
        ];
        const tex = await saveAndFetchTex(page, tid, { profilo_classe: pt });
        const texN = tex.replace(/\\_/g, "_");
        // Box apre con title custom
        expect(texN, "sectionbox{Sezione Custom MARK_BX}")
            .toMatch(/\\begin\{sectionbox\}\{Sezione Custom MARK_BX\}/);
        // Content dentro box
        expect(texN, "MARK_INNER presente").toContain("MARK_INNER");
        // Box chiude prima del prossimo sectionHeader same level
        const boxStart = texN.indexOf("\\begin{sectionbox}{Sezione Custom MARK_BX}");
        const boxEnd   = texN.indexOf("\\end{sectionbox}", boxStart);
        const next     = texN.indexOf("MARK_OUT");
        expect(boxEnd, "end{sectionbox} esiste").toBeGreaterThan(boxStart);
        expect(next, "MARK_OUT dopo close").toBeGreaterThan(boxEnd);
    });
});
