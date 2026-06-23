/**
 * Phase 24.63 — verifica ISOLAMENTO modifiche teacher (override personale,
 * istanza base '') vs admin schema (institutional override).
 *
 * superadmin è teacher + super_admin. Quando agisce come docente
 * (/risdoc/view/{id}) le sue modifiche markup vanno in
 * `risdoc_teacher_overrides(teacher_id=77, instance_key='')`.
 *
 * Quando agisce come admin (/risdoc/view/{id}?admin_edit=1) le sue
 * modifiche schema vanno in `risdoc_institutional_overrides`.
 *
 * I due path sono indipendenti — modificare uno non altera l'altro.
 */
const { test, expect } = require("@playwright/test");

const SUPER = "superadmin";
const SUPER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function loginSuper(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', SUPER);
    await page.fill('input[name="password"]', SUPER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test("Phase 24.63 — teacher override + admin schema su stesso template sono isolati", async ({ page }) => {
    test.setTimeout(60_000);
    await loginSuper(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();
    const detail = await (await page.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
    const htmlPath = detail.template?.html_file;
    const schemaPath = detail.template?.schema_path;

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;

    const TEACHER_SENTINEL = `TEACHER_${Date.now().toString(36)}`;
    const SCHEMA_SENTINEL = `SCHEMA_${Date.now().toString(36)}`;

    // Step 1: vittorio come docente modifica markup HTML (instance_key='')
    const teacherSave = await page.request.post(`/api/risdoc/templates/${tplId}/override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "html", path: htmlPath,
            body: `<!-- ${TEACHER_SENTINEL} -->`,
            instance_key: "",
        }).toString(),
    });
    expect(teacherSave.ok()).toBeTruthy();

    // Step 2: vittorio come admin modifica schema (institutional override)
    const beforeSchema = await (await page.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
    const schemaObj = JSON.parse(beforeSchema);
    const schemaModified = { ...schemaObj, _admin_dual_test_marker: SCHEMA_SENTINEL };
    const adminSave = await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "schema", path: schemaPath,
            content: JSON.stringify(schemaModified),
        }).toString(),
    });
    expect(adminSave.ok()).toBeTruthy();

    try {
        // Verifica isolation:
        // GET /file?kind=html → ritorna teacher override (TEACHER_SENTINEL)
        const fileRes = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(fileRes.source).toBe("override");
        expect(fileRes.body).toContain(TEACHER_SENTINEL);
        expect(fileRes.body).not.toContain(SCHEMA_SENTINEL);

        // GET /schema → ritorna admin institutional (SCHEMA_SENTINEL)
        const schemaRes = await (await page.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
        expect(schemaRes).toContain(SCHEMA_SENTINEL);
        expect(schemaRes).not.toContain(TEACHER_SENTINEL);

        // Crucially: i due path NON si sovrappongono
        // (kind=html teacher override / kind=schema institutional override).
    } finally {
        // Cleanup
        await page.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf, kind: "html", path: htmlPath, instance_key: "",
            }).toString(),
        });
        await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, kind: "schema", path: schemaPath }).toString(),
        });
    }
});

test("Phase 24.63 — admin schema mod NON sovrascrive override teacher di un'istanza fork", async ({ page }) => {
    test.setTimeout(60_000);
    await loginSuper(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    const detail = await (await page.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
    const htmlPath = detail.template?.html_file;
    const schemaPath = detail.template?.schema_path;

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;

    // Crea istanza fork del docente
    const create = await page.request.post(`/api/risdoc/templates/${tplId}/instances`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, instance_label: "Dual test instance" }).toString(),
    });
    const { instance_key: instKey } = await create.json();

    const INST_SENTINEL = `INST_${instKey}_${Date.now().toString(36)}`;
    const SCHEMA_SENTINEL2 = `SCHEMA_${Date.now().toString(36)}`;

    // Override markup nell'istanza fork
    await page.request.post(`/api/risdoc/templates/${tplId}/override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "html", path: htmlPath,
            body: `<!-- ${INST_SENTINEL} -->`,
            instance_key: instKey,
        }).toString(),
    });

    // Modifica schema admin
    const beforeSchema = await (await page.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
    const modified = { ...JSON.parse(beforeSchema), _dual_test_marker_2: SCHEMA_SENTINEL2 };
    await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "schema", path: schemaPath,
            content: JSON.stringify(modified),
        }).toString(),
    });

    try {
        // Istanza fork conserva il proprio override markup
        const instFile = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}&instance_key=${instKey}`
        )).json();
        expect(instFile.body, "istanza preserva override markup").toContain(INST_SENTINEL);

        // Schema istituzionale è la versione admin modificata
        const schemaText = await (await page.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
        expect(schemaText).toContain(SCHEMA_SENTINEL2);

        // Istanza base ('' instance_key) NON ha il sentinel istanza fork
        const baseFile = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(baseFile.body || "", "istanza base isolata da fork").not.toContain(INST_SENTINEL);
    } finally {
        await page.request.post(`/api/risdoc/templates/${tplId}/instances/${instKey}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, kind: "schema", path: schemaPath }).toString(),
        });
    }
});
