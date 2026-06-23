/**
 * Phase 25.B7 — Concurrent isolation test (4 contexts).
 *
 * Verifica end-to-end l'isolamento sotto concorrenza:
 *
 *   1. RACE — 2 sessioni dello stesso teacher creano un'istanza con
 *      stesso label "Piano annuale" simultaneamente. Phase 25.B2 (atomic
 *      ON DUPLICATE KEY UPDATE) deve produrre 2 instance_key DIVERSI.
 *      Vecchio bug: entrambe ricevevano stesso key, una INSERT IGNORE'd.
 *
 *   2. CROSS-TEACHER — superadmin (super_admin+teacher) e marco.rossi
 *      (teacher) creano istanze del medesimo template_id in parallelo.
 *      Verifica:
 *        - listInstances per vittorio mostra SOLO le sue istanze
 *        - listInstances per marco mostra SOLO le sue istanze
 *        - I due set di instance_key NON si sovrappongono nella view
 *          dell'altro utente (anche se i key potrebbero collidere
 *          letteralmente — sono scoped per teacher_id).
 *
 *   3. OVERRIDE PARALLEL — vittorio salva override sull'istanza A,
 *      marco salva override sull'istanza B (stesso template). Nessuno
 *      sovrascrive l'altro (UNIQUE constraint Phase 24.58 include
 *      teacher_id + instance_key).
 *
 *   4. INSTITUTIONAL DUAL-PATH — vittorio super_admin modifica baseline
 *      institutional, marco vede la baseline aggiornata MA i suoi
 *      override teacher restano intatti (3-layer resolver verifica).
 */
const { test, expect } = require("@playwright/test");

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

async function pickTemplateId(page) {
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    return (list.templates || [])[0]?.id;
}

test.describe.configure({ mode: "serial" });

test("Phase 25.B7 — RACE: 2 INSERT concorrenti con stesso label → 2 instance_key DIVERSI", async ({ browser }) => {
    test.setTimeout(60_000);

    // 2 sessioni parallele dello stesso teacher (vittorio).
    const ctxA = await browser.newContext();
    const ctxB = await browser.newContext();
    const pageA = await ctxA.newPage();
    const pageB = await ctxB.newPage();

    try {
        await Promise.all([login(pageA, SUPER), login(pageB, SUPER)]);

        const tplId = await pickTemplateId(pageA);
        expect(tplId, "almeno 1 template").toBeTruthy();

        const [csrfA, csrfB] = await Promise.all([getCsrf(pageA), getCsrf(pageB)]);
        const sameLabel = "Piano annuale RACE " + Date.now().toString(36);

        // Fire 2 POST in parallelo (stesso label, stesso teacher).
        const [resA, resB] = await Promise.all([
            pageA.request.post(`/api/risdoc/templates/${tplId}/instances`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfA, instance_label: sameLabel }).toString(),
            }),
            pageB.request.post(`/api/risdoc/templates/${tplId}/instances`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfB, instance_label: sameLabel }).toString(),
            }),
        ]);

        const [jsonA, jsonB] = await Promise.all([resA.json(), resB.json()]);

        expect(resA.ok(), "POST A ok").toBeTruthy();
        expect(resB.ok(), "POST B ok").toBeTruthy();
        expect(jsonA.ok && jsonB.ok, "entrambe le response success").toBeTruthy();
        expect(jsonA.instance_key, "A ha key").toBeTruthy();
        expect(jsonB.instance_key, "B ha key").toBeTruthy();
        // Phase 25.B2 — keys MUST be different (no silent collision).
        expect(jsonA.instance_key).not.toBe(jsonB.instance_key);

        // Verifica che entrambe le istanze siano persistite.
        const list = await (await pageA.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const keys = (list.instances || []).map(i => i.instance_key);
        expect(keys, "entrambe le istanze in lista").toEqual(expect.arrayContaining([jsonA.instance_key, jsonB.instance_key]));

        // Cleanup
        const csrfClean = await getCsrf(pageA);
        for (const k of [jsonA.instance_key, jsonB.instance_key]) {
            await pageA.request.post(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(k)}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfClean }).toString(),
            });
        }
    } finally {
        await ctxA.close();
        await ctxB.close();
    }
});

test("Phase 25.B7 — CROSS-TEACHER: vittorio + marco listInstances scoped", async ({ browser }) => {
    test.setTimeout(60_000);

    const ctxV = await browser.newContext();
    const ctxM = await browser.newContext();
    const pageV = await ctxV.newPage();
    const pageM = await ctxM.newPage();

    try {
        await Promise.all([login(pageV, SUPER), login(pageM, MARCO)]);

        const tplId = await pickTemplateId(pageV);
        const [csrfV, csrfM] = await Promise.all([getCsrf(pageV), getCsrf(pageM)]);

        const labelV = "VITTORIO_TEST_" + Date.now().toString(36);
        const labelM = "MARCO_TEST_" + Date.now().toString(36);

        // Crea in parallelo
        const [resV, resM] = await Promise.all([
            pageV.request.post(`/api/risdoc/templates/${tplId}/instances`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfV, instance_label: labelV }).toString(),
            }),
            pageM.request.post(`/api/risdoc/templates/${tplId}/instances`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfM, instance_label: labelM }).toString(),
            }),
        ]);
        expect(resV.ok() && resM.ok(), "entrambe le creazioni success").toBeTruthy();
        const { instance_key: keyV } = await resV.json();
        const { instance_key: keyM } = await resM.json();

        try {
            // Vittorio NON deve vedere l'istanza di marco
            const listV = await (await pageV.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
            const labelsV = (listV.instances || []).map(i => i.instance_label);
            expect(labelsV, "vittorio vede SOLO sue istanze").toContain(labelV);
            expect(labelsV, "vittorio NO istanze di marco").not.toContain(labelM);

            // Marco NON deve vedere l'istanza di vittorio
            const listM = await (await pageM.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
            const labelsM = (listM.instances || []).map(i => i.instance_label);
            expect(labelsM, "marco vede SOLO sue istanze").toContain(labelM);
            expect(labelsM, "marco NO istanze di vittorio").not.toContain(labelV);

            // GET file con instance_key di marco da pageV → 404 / not found / vuoto
            const detail = await (await pageV.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
            const htmlPath = detail.template?.html_file;
            const fileFromVByMarcoKey = await pageV.request.get(
                `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}&instance_key=${keyM}`
            );
            // Vittorio chiamando con la key di marco non ottiene il body di marco
            // (instance_key passato ma teacher_id resolver = vittorio → no override match).
            const fileJson = await fileFromVByMarcoKey.json();
            // Resolver fallback al source file disk (nessun teacher override per
            // vittorio + keyM esiste). Verifica: source !== "teacher" né body con keyM marker.
            expect(fileJson.source).not.toBe("teacher");
        } finally {
            // Cleanup
            await pageV.request.post(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(keyV)}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfV }).toString(),
            });
            await pageM.request.post(`/api/risdoc/templates/${tplId}/instances/${encodeURIComponent(keyM)}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfM }).toString(),
            });
        }
    } finally {
        await ctxV.close();
        await ctxM.close();
    }
});

test("Phase 25.B7 — OVERRIDE PARALLEL: vittorio + marco salvano override su stesso template, no clobbering", async ({ browser }) => {
    test.setTimeout(60_000);

    const ctxV = await browser.newContext();
    const ctxM = await browser.newContext();
    const pageV = await ctxV.newPage();
    const pageM = await ctxM.newPage();

    try {
        await Promise.all([login(pageV, SUPER), login(pageM, MARCO)]);

        const tplId = await pickTemplateId(pageV);
        const detail = await (await pageV.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
        const htmlPath = detail.template?.html_file;
        expect(htmlPath, "template ha html_file").toBeTruthy();

        const [csrfV, csrfM] = await Promise.all([getCsrf(pageV), getCsrf(pageM)]);
        const SENT_V = `VITTORIO_OVR_${Date.now().toString(36)}`;
        const SENT_M = `MARCO_OVR_${Date.now().toString(36)}`;

        // Save override su istanza base ('') in parallelo
        const [saveV, saveM] = await Promise.all([
            pageV.request.post(`/api/risdoc/templates/${tplId}/override`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({
                    _csrf: csrfV, kind: "html", path: htmlPath,
                    body: `<!-- ${SENT_V} -->`,
                }).toString(),
            }),
            pageM.request.post(`/api/risdoc/templates/${tplId}/override`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({
                    _csrf: csrfM, kind: "html", path: htmlPath,
                    body: `<!-- ${SENT_M} -->`,
                }).toString(),
            }),
        ]);
        expect(saveV.ok() && saveM.ok(), "entrambi save success").toBeTruthy();

        try {
            // Vittorio leggendo deve vedere SENT_V (NON SENT_M)
            const fileV = await (await pageV.request.get(
                `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
            )).json();
            expect(fileV.body, "vittorio vede il SUO override").toContain(SENT_V);
            expect(fileV.body, "vittorio NON vede override di marco").not.toContain(SENT_M);

            // Marco leggendo deve vedere SENT_M (NON SENT_V)
            const fileM = await (await pageM.request.get(
                `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
            )).json();
            expect(fileM.body, "marco vede il SUO override").toContain(SENT_M);
            expect(fileM.body, "marco NON vede override di vittorio").not.toContain(SENT_V);
        } finally {
            // Cleanup overrides per entrambi
            await pageV.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfV, kind: "html", path: htmlPath }).toString(),
            });
            await pageM.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfM, kind: "html", path: htmlPath }).toString(),
            });
        }
    } finally {
        await ctxV.close();
        await ctxM.close();
    }
});

test("Phase 25.B7 — INSTITUTIONAL DUAL-PATH: super_admin modifica baseline, teacher override resta intatto", async ({ browser }) => {
    test.setTimeout(60_000);

    const ctxV = await browser.newContext();
    const ctxM = await browser.newContext();
    const pageV = await ctxV.newPage();
    const pageM = await ctxM.newPage();

    try {
        await Promise.all([login(pageV, SUPER), login(pageM, MARCO)]);

        const tplId = await pickTemplateId(pageV);
        const detail = await (await pageV.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
        const htmlPath = detail.template?.html_file;

        const [csrfV, csrfM] = await Promise.all([getCsrf(pageV), getCsrf(pageM)]);
        const SENT_INST = `INST_${Date.now().toString(36)}`;
        const SENT_M_OVR = `MARCO_OVR_${Date.now().toString(36)}`;

        // Step 1: marco salva il SUO teacher override
        const saveM = await pageM.request.post(`/api/risdoc/templates/${tplId}/override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfM, kind: "html", path: htmlPath,
                body: `<!-- ${SENT_M_OVR} -->`,
            }).toString(),
        });
        expect(saveM.ok()).toBeTruthy();

        // Step 2: vittorio (super_admin) salva institutional override (baseline)
        const saveInst = await pageV.request.post(`/api/risdoc/templates/${tplId}/institutional-override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrfV, kind: "html", path: htmlPath,
                content: `<!-- ${SENT_INST} -->`,
            }).toString(),
        });
        expect(saveInst.ok()).toBeTruthy();

        try {
            // Step 3: marco rilegge → DEVE ancora vedere il proprio override
            // (resolver 3-layer: teacher override > institutional > source).
            const fileM = await (await pageM.request.get(
                `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
            )).json();
            expect(fileM.source, "marco resolver source = override (teacher override prevale)").toBe("override");
            expect(fileM.body, "marco vede il SUO override Phase 21").toContain(SENT_M_OVR);
            expect(fileM.body, "marco NON deve vedere institutional override").not.toContain(SENT_INST);

            // Step 4: un teacher SENZA proprio override (vittorio path teacher)
            // riceve la baseline institutional aggiornata.
            // (Vittorio ha doppio path: senza override teacher leggerà la baseline.
            // Per testare puro path institutional, dobbiamo eliminare prima eventuali
            // override teacher di vittorio.)
            await pageV.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfV, kind: "html", path: htmlPath }).toString(),
            });
            const fileV = await (await pageV.request.get(
                `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
            )).json();
            expect(fileV.source, "vittorio resolver source = institutional").toBe("institutional");
            expect(fileV.body).toContain(SENT_INST);
        } finally {
            // Cleanup
            await pageV.request.post(`/api/risdoc/templates/${tplId}/institutional-override/del`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfV, kind: "html", path: htmlPath }).toString(),
            });
            await pageM.request.post(`/api/risdoc/templates/${tplId}/override/del`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrfM, kind: "html", path: htmlPath }).toString(),
            });
        }
    } finally {
        await ctxV.close();
        await ctxM.close();
    }
});
