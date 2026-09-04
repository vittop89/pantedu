/**
 * Phase 24.45 — verifica che metadata.layout condiziona lo scaffolding
 * server-side di /studio/{type}/{ind}/{cls}/{subj}/{topic}.
 *
 *   layout=custom    → no #header_page, no .fm-draggable-container; .fm-pt-custom-page wrap.
 *   layout=exercises → scaffolding standard #header_page + .fm-draggable-container.
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

const BODY_PT = [
    { _type: "sectionHeader", level: 1, text: "Esercizi per studenti" },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "TEST_EX_BODY", marks: [] }] },
];

test("layout=custom → studio page senza #header_page e fm-draggable-container", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const topic = "LayoutCustom-" + Date.now().toString(36);
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "risdoc", subject: "MAT",
            indirizzo: "sc", classe: "2s",
            topic, title: "Layout custom test", visibility: "published",
            metadata: JSON.stringify({ layout: "custom", body_pt: BODY_PT }),
        }).toString(),
    });
    expect(create.ok(), `create ${create.status()}`).toBeTruthy();
    const id = (await create.json()).id;
    try {
        const resp = await page.request.get(`/studio/risdoc/sc/2s/MAT/${encodeURIComponent(topic)}`);
        expect(resp.ok()).toBeTruthy();
        const html = await resp.text();
        expect(html, "no #header_page").not.toContain('id="header_page"');
        expect(html, "no fm-draggable-container").not.toContain("fm-draggable-container");
        expect(html, ".fm-pt-custom-page wrap").toContain("fm-pt-custom-page");
        expect(html, 'data-layout="custom"').toContain('data-layout="custom"');
        expect(html, "PT body renderizzato").toContain("TEST_EX_BODY");
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});

test("layout=exercises → studio page mantiene #header_page e fm-draggable-container", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const topic = "LayoutExer-" + Date.now().toString(36);
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "risdoc", subject: "MAT",
            indirizzo: "sc", classe: "2s",
            topic, title: "Layout exer test", visibility: "published",
            metadata: JSON.stringify({ layout: "exercises", body_pt: BODY_PT }),
        }).toString(),
    });
    expect(create.ok()).toBeTruthy();
    const id = (await create.json()).id;
    try {
        const resp = await page.request.get(`/studio/risdoc/sc/2s/MAT/${encodeURIComponent(topic)}`);
        const html = await resp.text();
        expect(html, "ha #header_page").toContain('id="header_page"');
        expect(html, "ha fm-draggable-container").toContain("fm-draggable-container");
        expect(html, "no .fm-pt-custom-page").not.toContain("fm-pt-custom-page");
        expect(html, "PT body renderizzato comunque").toContain("TEST_EX_BODY");
    } finally {
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});
