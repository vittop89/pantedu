/**
 * Phase 24.44 — radio "Modello documento" nel modal section-edit:
 *   - "exercises" → seed body_pt con sectionHeader Esercizi/Verifiche
 *   - "custom"    → PT editor vuoto, costruzione libera via toolbar
 * Verifica anche che metadata.layout venga persistito.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function loginTeacher(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

// Phase 24.58 DEPRECATED — modal radio layout non più presente per risdoc/bes
// (modal sostituito da openInstanceModal — vedi risdoc_multi_instance.spec.js).
test.skip("modal espone radio layout exercises|custom (default custom)", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    await page.evaluate(() => {
        document.querySelector('#fm-sp-risdoc .js-edit-section')?.click();
    });
    await page.waitForTimeout(400);
    await page.evaluate(() => {
        document.querySelector('#fm-sp-risdoc .fm-section-add, #fm-sp-risdoc .fm-cat-add')?.click();
    });
    await page.waitForTimeout(500);

    const r = await page.evaluate(() => {
        const radios = [...document.querySelectorAll('.fm-modal input[name="layout"]')];
        return {
            count: radios.length,
            values: radios.map((r) => r.value),
            checked: radios.find((r) => r.checked)?.value,
        };
    });
    expect(r.count, "due radio layout").toBe(2);
    expect(r.values.sort()).toEqual(["custom", "exercises"]);
    expect(r.checked, "default custom").toBe("custom");
});

test("create con layout=exercises persiste metadata.layout + body_pt seed", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const title = "Test layout exercises " + Date.now().toString(36);
    const seedPt = [
        { _type: "sectionHeader", level: 1, text: "Esercizi per studenti" },
        { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
        { _type: "sectionHeader", level: 1, text: "Verifiche" },
        { _type: "block", style: "normal", children: [{ _type: "span", text: "", marks: [] }] },
    ];
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "risdoc", subject: "MAT",
            indirizzo: "sc", classe: "2s",
            topic: "LayoutTest-" + Date.now().toString(36),
            title, visibility: "draft",
            metadata: JSON.stringify({
                category: "RISORSE",
                layout: "exercises",
                body_pt: seedPt,
            }),
        }).toString(),
    });
    expect(create.ok()).toBeTruthy();
    const id = (await create.json()).id;

    try {
        const row = await (await page.request.get(`/api/teacher/content/${id}`)).json();
        const meta = row.content?.metadata || (() => {
            try { return JSON.parse(row.content?.metadata_json || "{}"); }
            catch { return {}; }
        })();
        expect(meta.layout).toBe("exercises");
        expect(Array.isArray(meta.body_pt), "body_pt array").toBeTruthy();
        const headers = meta.body_pt
            .filter((b) => b._type === "sectionHeader")
            .map((b) => b.text);
        expect(headers).toContain("Esercizi per studenti");
        expect(headers).toContain("Verifiche");
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});
