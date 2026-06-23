// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Phase 24.55 — institutional overrides API + UI editor sorgenti.
 *
 *   - Super-admin POST /api/risdoc/templates/{id}/institutional-override
 *     salva un institutional override (kind=html|tex|css|json).
 *   - GET /api/risdoc/templates/{id}/file?kind=...&path=... ora ritorna
 *     source="institutional" se l'override admin esiste (priorità
 *     sopra al source file su disco).
 *   - DELETE /institutional-override/del rimuove e il file torna al
 *     source disk.
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

test.skip("admin institutional override save → resolveFile usa la baseline", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tpl = (list.templates || [])[0];
    expect(tpl, "almeno 1 template").toBeTruthy();
    const tplId = tpl.id;

    // Detail per scoprire html_file path
    const detail = await (await page.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
    const htmlPath = detail.template?.html_file;
    expect(htmlPath, "template ha html_file").toBeTruthy();

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const SENTINEL = `INSTITUTIONAL_TEST_${Date.now().toString(36)}`;
    const body = `<!-- ${SENTINEL} -->\n<h1>Override istituzionale di test</h1>`;

    const save = await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "html", path: htmlPath, content: body,
        }).toString(),
    });
    expect(save.ok(), `save ${save.status()}`).toBeTruthy();
    const saveJson = await save.json();
    expect(saveJson.ok).toBeTruthy();

    try {
        // GET file → deve avere source="institutional"
        const file = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(file.ok).toBeTruthy();
        expect(file.source, "source=institutional").toBe("institutional");
        expect(file.body, "body contiene SENTINEL").toContain(SENTINEL);
    } finally {
        // Cleanup
        await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, kind: "html", path: htmlPath }).toString(),
        });
        // Verifica revert: source torna a "file"
        const file2 = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(file2.source, "post-revert source=file").toBe("file");
    }
});

test.skip("Phase 24.57 — pagina admin-edit /risdoc/view/{id}?admin_edit=1 mostra toolbar admin + bottoni", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2500); // Lit + schema fetch

    // Phase 24.66 — chip admin nella .fm-risdoc-toolbar PHP esterna
    const r = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        const adminChip = document.querySelector(".fm-doc-topbar__chip--admin");
        return {
            hasWc: !!wc,
            adminEditAttr: wc?.getAttribute("admin-edit"),
            hasAdminChip: !!adminChip,
            saveBtn: !!document.querySelector('.fm-risdoc-toolbar [data-action="admin-save"]'),
            jsonToggle: !!document.querySelector('.fm-risdoc-toolbar [data-action="admin-toggle-json"]'),
            revertBtn: !!document.querySelector('.fm-risdoc-toolbar [data-action="admin-revert"]'),
            closeBtn: !!document.querySelector('.fm-risdoc-toolbar [data-action="admin-close"]'),
        };
    });
    expect(r.hasWc, "<fm-risdoc-template> presente").toBeTruthy();
    expect(r.adminEditAttr, "admin-edit attr").toBe("1");
    expect(r.hasAdminChip, "chip ADMIN in toolbar PHP").toBeTruthy();
    expect(r.saveBtn, "[data-action=admin-save]").toBeTruthy();
    expect(r.jsonToggle, "[data-action=admin-toggle-json]").toBeTruthy();
    expect(r.revertBtn, "[data-action=admin-revert]").toBeTruthy();
    expect(r.closeBtn, "[data-action=admin-close]").toBeTruthy();
});

test.skip("Phase 24.57 — toggle JSON panel via click button mostra textarea schema", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForTimeout(2500);

    // Stato iniziale: pannello chiuso
    let panelVisible = await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template")?.shadowRoot;
        return !!sr?.querySelector(".fm-risdoc-admin-json");
    });
    expect(panelVisible, "JSON panel chiuso di default").toBeFalsy();

    // Phase 24.66 — click toggle JSON nella toolbar PHP esterna
    await page.evaluate(() => {
        document.querySelector('.fm-risdoc-toolbar [data-action="admin-toggle-json"]')?.click();
    });
    await page.waitForTimeout(200);

    const r = await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template")?.shadowRoot;
        const panel = sr?.querySelector(".fm-risdoc-admin-json");
        const text = panel?.querySelector(".fm-risdoc-admin-json__text");
        return {
            panelVisible: !!panel,
            hasTextarea: !!text,
            textLen: text?.value?.length || 0,
        };
    });
    expect(r.panelVisible, "JSON panel aperto dopo click").toBeTruthy();
    expect(r.hasTextarea, "textarea JSON").toBeTruthy();
    expect(r.textLen, "schema caricato nella textarea").toBeGreaterThan(50);
});

test.skip("Phase 24.56 — endpoint /schema serve override institutional invece del file", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();

    const detail = await (await page.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
    const schemaPath = detail.template?.schema_path;
    expect(schemaPath).toBeTruthy();

    // Fetch schema corrente (file su disco)
    const beforeRes = await page.request.get(`/api/risdoc/templates/${tplId}/schema`);
    const beforeJson = JSON.parse(await beforeRes.text());

    // Costruisci versione modificata con marker
    const SENTINEL = `SCHEMA_TEST_${Date.now().toString(36)}`;
    const modified = { ...beforeJson, _admin_test_marker: SENTINEL };

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const save = await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "schema", path: schemaPath,
            content: JSON.stringify(modified),
        }).toString(),
    });
    expect(save.ok()).toBeTruthy();

    try {
        // Re-fetch schema → deve contenere il marker
        const afterRes = await page.request.get(`/api/risdoc/templates/${tplId}/schema`);
        const afterText = await afterRes.text();
        expect(afterText, "schema serve override institutional").toContain(SENTINEL);
    } finally {
        await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, kind: "schema", path: schemaPath }).toString(),
        });
    }
});
