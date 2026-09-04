/**
 * Phase 24.50 — template picker istituzionale per layout=exercises.
 *
 *   - Super-admin POST /api/risdoc/templates/{id}/body-pt salva PT seed.
 *   - GET /api/risdoc/templates?with_body_pt=1 ritorna body_pt nei rows.
 *   - Modal teacher: radio "exercises" → select "Parti da template" appare;
 *     selezione → applica body_pt al PT editor.
 *
 * Setup: usa il primo risdoc template visibile per il teacher per il test.
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

const SEED_PT = [
    { _type: "sectionHeader", level: 1, text: "TPL_SEED_HEADER" },
    { _type: "block", style: "normal", children: [
        { _type: "span", text: "TPL_SEED_BODY", marks: [] },
    ]},
];

test("admin POST body-pt + GET with_body_pt + teacher modal applica seed", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    // Recupera primo template risdoc visibile
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const templates = list.templates || [];
    expect(templates.length, "almeno 1 template visibile").toBeGreaterThanOrEqual(1);
    const tplId = templates[0].id;

    // Salva body_pt come super-admin (superadmin è super_admin nel test env)
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const save = await page.request.post(`/api/risdoc/templates/${tplId}/body-pt`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            body_pt: JSON.stringify(SEED_PT),
        }).toString(),
    });
    expect(save.ok(), `save ${save.status()}`).toBeTruthy();

    try {
        // Verifica GET with_body_pt=1 ritorna body_pt come array
        const fetched = await (await page.request.get("/api/risdoc/templates?origin=risdoc&with_body_pt=1")).json();
        const t = (fetched.templates || []).find((x) => x.id === tplId);
        expect(t, "template ritornato").toBeTruthy();
        expect(Array.isArray(t.body_pt)).toBeTruthy();
        expect(t.body_pt[0]?.text || t.body_pt[0]?.title).toBe("TPL_SEED_HEADER");
    } finally {
        // Cleanup: pulisci body_pt
        await page.request.post(`/api/risdoc/templates/${tplId}/body-pt`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, body_pt: "" }).toString(),
        });
    }
});

// Phase 24.58 DEPRECATED — il template picker era nel modal teacher_content;
// ora il fork avviene via openInstanceModal (risdoc_multi_instance.spec.js).
test.skip("modal expose .fm-modal-template-pick quando layout=custom (Personalizzabile)", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Apri modal sotto MODELLI
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
    });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    await page.waitForTimeout(500);

    // Default radio = custom → picker VISIBILE (Phase 24.52: ora sotto Personalizzabile)
    let pickVisible = await page.evaluate(() => {
        const p = document.querySelector(".fm-modal-template-pick");
        return p && getComputedStyle(p).display !== "none";
    });
    expect(pickVisible, "picker visibile con layout=custom default").toBeTruthy();

    // Switch a exercises → picker hidden (lo "stile esercizi" è seed fisso)
    await page.evaluate(() => {
        const r = document.querySelector('input[name="layout"][value="exercises"]');
        if (r) { r.checked = true; r.dispatchEvent(new Event("change", { bubbles: true })); }
    });
    await page.waitForTimeout(150);
    pickVisible = await page.evaluate(() => {
        const p = document.querySelector(".fm-modal-template-pick");
        return p && getComputedStyle(p).display !== "none";
    });
    expect(pickVisible, "picker nascosto dopo switch exercises").toBeFalsy();

    // Switch back a custom → picker torna visibile
    await page.evaluate(() => {
        const r = document.querySelector('input[name="layout"][value="custom"]');
        if (r) { r.checked = true; r.dispatchEvent(new Event("change", { bubbles: true })); }
    });
    await page.waitForTimeout(150);
    pickVisible = await page.evaluate(() => {
        const p = document.querySelector(".fm-modal-template-pick");
        return p && getComputedStyle(p).display !== "none";
    });
    expect(pickVisible, "picker visibile di nuovo dopo back a custom").toBeTruthy();

    const hasSelect = await page.evaluate(() => {
        return !!document.querySelector(".fm-modal-template-select");
    });
    expect(hasSelect, "select template presente").toBeTruthy();
});

// Phase 24.58 DEPRECATED — picker template nel modal teacher_content rimosso.
test.skip("Phase 24.52 — picker mostra TUTTI i template raggruppati per category, no-PT disabled", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
    });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    await page.waitForTimeout(800);

    const r = await page.evaluate(() => {
        const sel = document.querySelector(".fm-modal-template-select");
        const optgroups = [...(sel?.querySelectorAll("optgroup") || [])].map((og) => ({
            label: og.label,
            count: og.children.length,
            disabled: [...og.children].filter((o) => o.disabled).length,
        }));
        const allOpts = [...(sel?.querySelectorAll("optgroup option") || [])];
        return {
            optgroupCount: optgroups.length,
            optgroups,
            totalOpts: allOpts.length,
            disabledOpts: allOpts.filter((o) => o.disabled).length,
        };
    });
    expect(r.optgroupCount, "almeno 1 optgroup").toBeGreaterThanOrEqual(1);
    expect(r.totalOpts, "almeno 1 template visibile").toBeGreaterThanOrEqual(1);
    // Senza body_pt, i template sono disabled (admin non ha ancora popolato).
    // Verifica: ogni optgroup ha almeno 1 option (sia disabled sia enabled OK).
    for (const og of r.optgroups) {
        expect(og.count, `optgroup ${og.label} non vuoto`).toBeGreaterThanOrEqual(1);
    }
});
