/**
 * Phase 24.51 — admin UI per editare body_pt dei template istituzionali.
 *
 * Apre /admin/risdoc, clicca "📝 PT" su un template, verifica overlay
 * con <fm-pt-editor> + bottoni Salva/Pulisci, save round-trip.
 */
const { test, expect } = require("@playwright/test");

const ADMIN_USER = "superadmin";
const ADMIN_PASS = (process.env.E2E_TEACHER_PASS || "");

async function loginAdmin(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

const SEED = [
    { _type: "sectionHeader", level: 1, text: "ADMIN_UI_SEED_HEADER" },
    { _type: "block", style: "normal", children: [
        { _type: "span", text: "ADMIN_UI_SEED_BODY", marks: [] },
    ]},
];

test("admin /admin/risdoc → click 📝 PT → overlay con <fm-pt-editor>", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);
    await page.goto("/admin/risdoc");
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(1500); // attesa fetch templates

    // Almeno una riga template renderizzata
    const rowCount = await page.evaluate(() => {
        return document.querySelectorAll("#fm-ar-panel table.fm-ar-tbl tbody tr").length;
    });
    expect(rowCount, "almeno 1 template renderizzato").toBeGreaterThanOrEqual(1);

    // Click 📝 PT della prima riga
    await page.evaluate(() => {
        document.querySelector('button[data-action="edit-pt"]')?.click();
    });
    await page.waitForTimeout(2500); // lazy-load Tiptap

    const overlay = await page.evaluate(() => {
        const bd = document.querySelector(".fm-ar-pt-backdrop");
        const ed = bd?.querySelector("fm-pt-editor");
        return {
            hasBackdrop: !!bd,
            hasEditor: !!ed,
            hasSaveBtn: !!bd?.querySelector(".fm-ar-pt-save"),
            hasClearBtn: !!bd?.querySelector(".fm-ar-pt-clear"),
        };
    });
    expect(overlay.hasBackdrop, "overlay aperto").toBeTruthy();
    expect(overlay.hasEditor, "<fm-pt-editor> montato").toBeTruthy();
    expect(overlay.hasSaveBtn, "save btn presente").toBeTruthy();
    expect(overlay.hasClearBtn, "clear btn presente").toBeTruthy();
});

test("admin POST body-pt → GET teacher-side picker mostra il template", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    // Setup: prendi primo template
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId, "almeno 1 template").toBeTruthy();

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    // Salva body_pt come admin
    const save = await page.request.post(`/api/risdoc/templates/${tplId}/body-pt`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, body_pt: JSON.stringify(SEED) }).toString(),
    });
    expect(save.ok()).toBeTruthy();

    try {
        // Verifica round-trip via GET with_body_pt=1
        const get = await (await page.request.get("/api/risdoc/templates?origin=risdoc&with_body_pt=1")).json();
        const t = (get.templates || []).find((x) => x.id === tplId);
        expect(Array.isArray(t.body_pt), "body_pt array").toBeTruthy();
        expect(t.body_pt[0]?.text || t.body_pt[0]?.title).toBe("ADMIN_UI_SEED_HEADER");
    } finally {
        await page.request.post(`/api/risdoc/templates/${tplId}/body-pt`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, body_pt: "" }).toString(),
        });
    }
});
