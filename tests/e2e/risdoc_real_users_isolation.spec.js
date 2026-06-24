/**
 * Phase 24.65 — test seri end-to-end con utenti reali (no API direct):
 *
 * Flow:
 *   1. superadmin (super_admin) modifica schema institutional → globale.
 *   2. marco.rossi forka template + edit override teacher → solo suo.
 *   3. docente1 modifica i propri override teacher → solo suo.
 *   4. Verifica isolamento: ogni utente vede solo i propri override; lo
 *      schema institutional è la baseline comune.
 */
const { test, expect, request } = require("@playwright/test");

const SUPER = "superadmin";
const MARCO = "marco.rossi";
const PASS  = (process.env.E2E_TEACHER_PASS || "");

async function login(page, username) {
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

async function getCsrf(page) {
    return (await (await page.request.get("/auth/csrf")).json()).token;
}

test("Phase 24.65 — admin schema institutional + 2 teachers override isolation", async ({ browser }) => {
    test.setTimeout(120_000);

    const ctxV = await browser.newContext();
    const pageV = await ctxV.newPage();
    await login(pageV, SUPER);

    const ctxM = await browser.newContext();
    const pageM = await ctxM.newPage();
    await login(pageM, MARCO);

    // Pick template id 16 (Piano annuale)
    const tplList = await (await pageV.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (tplList.templates || []).find(t => t.id === 16)?.id || tplList.templates[0]?.id;
    expect(tplId).toBeTruthy();
    const detail = await (await pageV.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
    const htmlPath = detail.template?.html_file;
    const schemaPath = detail.template?.schema_path;

    const csrfV = await getCsrf(pageV);
    const csrfM = await getCsrf(pageM);

    const ADMIN_SCHEMA_TAG = `ADMIN_${Date.now().toString(36)}`;
    const VITTORIO_TEACHER_TAG = `VITT_${Date.now().toString(36)}`;
    const MARCO_INST_LABEL = "Piano annuale 3A " + Date.now().toString(36);
    const MARCO_TEACHER_TAG = `MARCO_${Date.now().toString(36)}`;

    let marcoInstKey;

    try {
        // Step 1: docente1 (super_admin) salva institutional schema override
        const beforeSchema = JSON.parse(await (await pageV.request.get(`/api/risdoc/templates/${tplId}/schema`)).text());
        const newSchema = { ...beforeSchema, _admin_test: ADMIN_SCHEMA_TAG };
        const sSave = await pageV.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfV, kind: "schema", path: schemaPath,
                content: JSON.stringify(newSchema),
            }).toString(),
        });
        expect(sSave.ok(), "docente1 admin schema save").toBeTruthy();

        // Step 2: VERIFY entrambi vedono lo schema modificato (baseline)
        const schemaForV = await (await pageV.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
        const schemaForM = await (await pageM.request.get(`/api/risdoc/templates/${tplId}/schema`)).text();
        expect(schemaForV).toContain(ADMIN_SCHEMA_TAG);
        expect(schemaForM, "marco vede schema admin").toContain(ADMIN_SCHEMA_TAG);

        // Step 3: docente1 salva override teacher (instance base '')
        const vSave = await pageV.request.post(`/api/risdoc/templates/${tplId}/override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfV, kind: "html", path: htmlPath,
                body: `<!-- ${VITTORIO_TEACHER_TAG} -->`,
                instance_key: "",
            }).toString(),
        });
        expect(vSave.ok()).toBeTruthy();

        // Step 4: marco forka istanza (solo sua)
        const mFork = await pageM.request.post(`/api/risdoc/templates/${tplId}/instances`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfM, instance_label: MARCO_INST_LABEL,
            }).toString(),
        });
        const mForkJ = await mFork.json();
        expect(mFork.ok(), `marco fork ${mFork.status()}`).toBeTruthy();
        marcoInstKey = mForkJ.instance_key;

        // Step 5: marco salva override sulla sua istanza
        const mSave = await pageM.request.post(`/api/risdoc/templates/${tplId}/override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfM, kind: "html", path: htmlPath,
                body: `<!-- ${MARCO_TEACHER_TAG} -->`,
                instance_key: marcoInstKey,
            }).toString(),
        });
        expect(mSave.ok(), "marco override save").toBeTruthy();

        // Step 6: ISOLATION CHECKS
        // 6a. docente1 file?kind=html (instance '') → vede VITTORIO_TEACHER_TAG, NON MARCO
        const vFile = await (await pageV.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(vFile.body, "docente1 vede solo i propri override").toContain(VITTORIO_TEACHER_TAG);
        expect(vFile.body || "").not.toContain(MARCO_TEACHER_TAG);
        expect(vFile.source).toBe("override");

        // 6b. marco file?kind=html&instance_key=X → vede MARCO_TEACHER_TAG, NON OPERATORE
        const mFile = await (await pageM.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}&instance_key=${marcoInstKey}`
        )).json();
        expect(mFile.body, "marco istanza vede proprio override").toContain(MARCO_TEACHER_TAG);
        expect(mFile.body || "").not.toContain(VITTORIO_TEACHER_TAG);
        expect(mFile.source).toBe("override");

        // 6c. marco file?kind=html (instance base '') → NON ha override, vede file disco
        const mFileBase = await (await pageM.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(mFileBase.source, "marco instance base no override").toBe("file");
        expect(mFileBase.body || "").not.toContain(VITTORIO_TEACHER_TAG);
        expect(mFileBase.body || "").not.toContain(MARCO_TEACHER_TAG);

        // 6d. docente1 /instances per template → NON vede l'istanza di marco
        const vInsts = await (await pageV.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const vSeesMarco = (vInsts.instances || []).some(i => i.instance_key === marcoInstKey);
        expect(vSeesMarco, "docente1 non vede istanze di marco").toBeFalsy();

        // 6e. marco /instances → vede SOLO la sua
        const mInsts = await (await pageM.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const mSeesOwn = (mInsts.instances || []).some(i => i.instance_key === marcoInstKey);
        expect(mSeesOwn, "marco vede la propria istanza").toBeTruthy();
    } finally {
        // Cleanup
        if (marcoInstKey) {
            await pageM.request.post(`/api/risdoc/templates/${tplId}/instances/${marcoInstKey}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfM }).toString(),
            });
        }
        await pageV.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfV, kind: "html", path: htmlPath, instance_key: "",
            }).toString(),
        });
        await pageV.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfV, kind: "schema", path: schemaPath,
            }).toString(),
        });
        await ctxV.close();
        await ctxM.close();
    }
});

test("Phase 24.65 — marco non può salvare institutional override (forbidden)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, MARCO);
    const tplList = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (tplList.templates || [])[0]?.id;
    const csrf = await getCsrf(page);

    const r = await page.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, kind: "schema", path: "schemas/risdoc/test.json",
            content: JSON.stringify({ hacked: true }),
        }).toString(),
    });
    expect(r.status(), "marco NON è super_admin").toBe(403);
});

test("Phase 24.65 — marco non può listare istanze di un altro docente", async ({ browser }) => {
    test.setTimeout(60_000);
    const ctxV = await browser.newContext();
    const pageV = await ctxV.newPage();
    await login(pageV, SUPER);
    const ctxM = await browser.newContext();
    const pageM = await ctxM.newPage();
    await login(pageM, MARCO);

    const tplList = await (await pageV.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (tplList.templates || [])[0]?.id;
    const csrfV = await getCsrf(pageV);

    // docente1 crea una sua istanza
    const vFork = await pageV.request.post(`/api/risdoc/templates/${tplId}/instances`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrfV, instance_label: "VittorioOnly " + Date.now() }).toString(),
    });
    const vInst = (await vFork.json()).instance_key;

    try {
        // marco fa GET istanze del template — vede solo le sue
        const mInsts = await (await pageM.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const seesV = (mInsts.instances || []).some(i => i.instance_key === vInst);
        expect(seesV, "marco non vede istanze di docente1").toBeFalsy();
    } finally {
        await pageV.request.post(`/api/risdoc/templates/${tplId}/instances/${vInst}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrfV }).toString(),
        });
        await ctxV.close();
        await ctxM.close();
    }
});
