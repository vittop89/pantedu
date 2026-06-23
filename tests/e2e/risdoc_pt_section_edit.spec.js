/**
 * Phase 24.34 — verify PT editor cross-domain via section-edit modal.
 *
 * Test pulito a livello API: crea un teacher_content con metadata.body_pt
 * (PT AST), GET, verifica roundtrip.
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe("PT editor cross-domain (section-edit)", () => {
    test("create content with body_pt + roundtrip", async ({ page }) => {
        test.setTimeout(60_000);
        await loginAdmin(page);

        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const ptAst = [
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "Esercizio MARK_X test cross-domain", marks: ["strong"] },
            ]},
            { _type: "checkboxGroup", renderMode: "checked-only",
              items: [
                  { state: "x", label: "OPZ_A" },
                  { state: "_", label: "OPZ_B" },
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
                topic: "PT_TEST_24_34",
                title: "Test cross-domain PT editor",
                visibility: "draft",
                metadata: JSON.stringify({ body_pt: ptAst }),
            }).toString(),
        });
        expect(create.ok(), `create ${create.status()}`).toBeTruthy();
        const cj = await create.json();
        expect(cj.ok || cj.id).toBeTruthy();
        const id = cj.id;
        expect(id, "content id").toBeTruthy();

        const get = await page.request.get(`/api/teacher/content/${id}`);
        expect(get.ok()).toBeTruthy();
        const gj = await get.json();
        const meta = gj.content?.metadata;
        const metaObj = typeof meta === "string" ? JSON.parse(meta) : meta;
        expect(metaObj?.body_pt, "body_pt nel content saved").toBeTruthy();
        expect(Array.isArray(metaObj.body_pt)).toBeTruthy();
        // Verifica content PT preserved
        const flat = JSON.stringify(metaObj.body_pt);
        expect(flat, "MARK_X presente").toContain("MARK_X");
        expect(flat, "OPZ_A item state=x").toContain("OPZ_A");
        expect(flat, "renderMode checked-only").toContain("checked-only");

        // Cleanup
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    });

    test("fm-pt-editor alias custom element registered", async ({ page }) => {
        test.setTimeout(30_000);
        await loginAdmin(page);
        // Navigate a pagina che carica il bundle PT editor
        const tj = await page.request.get("/api/risdoc/templates").then(r => r.json());
        const piano = (tj.templates || []).find(t =>
            String(t.argomento || "").toLowerCase().includes("piano_annuale")
        );
        if (!piano) { test.skip(true, "no piano"); return; }
        await page.goto(`/risdoc/view/${piano.id}`);
        await page.waitForSelector("fm-risdoc-template", { timeout: 10000 });
        await page.waitForTimeout(2000);

        const aliasOk = await page.evaluate(() => {
            return typeof customElements.get("fm-pt-editor") === "function";
        });
        expect(aliasOk, "<fm-pt-editor> registered").toBeTruthy();
    });
});
