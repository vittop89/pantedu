/**
 * Phase 24.35 — verify PT preview render in /studio/{type}/.../{topic}.
 *
 * Crea content type=lab con metadata.body_pt → naviga al topic page
 * → verifica che HTML output contiene markup PT-rendered (sectionHeader,
 * checkboxGroup spuntati, fieldRef risolti).
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test("studio preview renders body_pt server-side", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const ptAst = [
        { _type: "sectionHeader", title: "PT_PREVIEW_HEADER", level: 2 },
        { _type: "block", style: "normal", children: [
            { _type: "span", text: "Frase con ", marks: [] },
            { _type: "span", text: "MARK_BOLD", marks: ["strong"] },
            { _type: "span", text: " body.", marks: [] },
        ]},
        { _type: "checkboxGroup", renderMode: "all", items: [
            { state: "x", label: "OPZ_PRE_A" },
            { state: "_", label: "OPZ_PRE_B" },
        ]},
    ];
    // Topic senza underscore (normalizeTopic traduce _ → space lato URL)
    const topic = "PtPreview-" + Date.now().toString(36);
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            type: "lab",
            subject: "MAT",
            indirizzo: "sc",
            classe: "2s",
            topic,
            title: "PT preview test",
            visibility: "published",
            metadata: JSON.stringify({ body_pt: ptAst }),
        }).toString(),
    });
    expect(create.ok(), `create ${create.status()}`).toBeTruthy();
    const id = (await create.json()).id;

    try {
        const url = `/studio/lab/sc/2s/MAT/${encodeURIComponent(topic)}`;
        const resp = await page.request.get(url);
        expect(resp.ok(), `studio page ${resp.status()}`).toBeTruthy();
        const html = await resp.text();

        expect(html, "data-source=pt wrapper").toContain('data-source="pt"');
        expect(html, "PT_PREVIEW_HEADER renderizzato").toContain("PT_PREVIEW_HEADER");
        expect(html, "MARK_BOLD strong").toMatch(/<strong>MARK_BOLD<\/strong>/);
        expect(html, "OPZ_PRE_A item").toContain("OPZ_PRE_A");
        expect(html, "OPZ_PRE_B item").toContain("OPZ_PRE_B");
        expect(html, "checkbox state ☑ per A").toMatch(/☑[^<]*<\/span>\s*OPZ_PRE_A/);
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});
