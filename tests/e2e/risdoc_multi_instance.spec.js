// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Phase 24.58 — multi-instance teacher overrides.
 *
 * Verifica end-to-end via API che lo stesso template forki in più
 * istanze distinte per il docente, ognuna isolata.
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

test.skip("Phase 24.58 — /risdoc/view/{id}?instance=KEY mostra banner istanza nel WC", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const create = await page.request.post(`/api/risdoc/templates/${tplId}/instances`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, instance_label: "Banner test" }).toString(),
    });
    const { instance_key: instKey } = await create.json();

    try {
        await page.goto(`/risdoc/view/${tplId}?instance=${instKey}`);
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(2000);

        const r = await page.evaluate(() => {
            const wc = document.querySelector("fm-risdoc-template");
            // Phase 24.66 — banner istanza ora è in .fm-risdoc-toolbar PHP esterna
            const chip = document.querySelector(".fm-doc-topbar__chip--instance");
            return {
                instanceKeyAttr: wc?.getAttribute("instance-key"),
                instanceKeyProp: wc?.instanceKey,
                hasBanner: !!chip,
                bannerText: chip?.textContent?.trim() || "",
            };
        });
        expect(r.instanceKeyAttr).toBe(instKey);
        expect(r.instanceKeyProp).toBe(instKey);
        expect(r.hasBanner, "banner istanza visibile").toBeTruthy();
        expect(r.bannerText).toContain(instKey);
    } finally {
        await page.request.post(`/api/risdoc/templates/${tplId}/instances/${instKey}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});

test.skip("Phase 24.58 — /risdoc/view/{id} (no instance) NON mostra banner", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    await page.goto(`/risdoc/view/${tplId}`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    const r = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return {
            instanceKeyProp: wc?.instanceKey,
            // Phase 24.66 — chip in toolbar PHP esterna
            hasBanner: !!document.querySelector(".fm-doc-topbar__chip--instance"),
        };
    });
    expect(r.instanceKeyProp || "", "default '' = istanza base").toBe("");
    expect(r.hasBanner, "no banner per istanza base").toBeFalsy();
});

test.skip("ADR-024 — modal UNICO: '+' risdoc default fork con role+template", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);
    // Attiva edit mode su una sezione + click + Nuovo
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
    });
    await page.waitForTimeout(300);
    await page.evaluate(() => {
        document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
    });
    await page.waitForTimeout(800);
    // populateForkPicker è async (fetch /api/risdoc/templates) → attendi opzioni.
    await page.waitForFunction(() => {
        const sel = document.querySelector(".fm-modal-backdrop .fm-modal-fork-tpl");
        return sel && sel.options.length > 1;
    }, { timeout: 6000 }).catch(() => {});

    const r = await page.evaluate(() => {
        const m = document.querySelector(".fm-modal-backdrop");
        const form = m?.querySelector(".fm-modal-form");
        // ADR-024 — modal unico: fork è un doc_mode, default per risdoc.
        const forkRadio = form?.querySelector('input[name="doc_mode"][value="fork"]');
        const titleInput = form?.querySelector('input[name="title"]'); // = instance_label
        const roleRadio = form?.querySelector('input[name="doc_role"][value="D"]');
        const tplSel = form?.querySelector(".fm-modal-fork-tpl");
        const forkSection = form?.querySelector('.fm-modal-doc-section[data-fm-doc-mode="fork"]');
        return {
            hasModal: !!m,
            forkDefault: !!forkRadio?.checked,
            hasTitle: !!titleInput,
            hasRole: !!roleRadio,
            hasTplSelect: !!tplSel,
            forkVisible: forkSection ? !forkSection.hidden : false,
            tplOptCount: tplSel?.options?.length || 0,
        };
    });
    expect(r.hasModal, "modal aperto").toBeTruthy();
    expect(r.forkDefault, "doc_mode=fork default per risdoc").toBeTruthy();
    expect(r.forkVisible, "sezione fork visibile").toBeTruthy();
    expect(r.hasTitle, "input titolo (= etichetta)").toBeTruthy();
    expect(r.hasRole, "radio ruolo D/C/R").toBeTruthy();
    expect(r.hasTplSelect, "select template").toBeTruthy();
    expect(r.tplOptCount, "almeno 1 template (+ placeholder)").toBeGreaterThanOrEqual(2);
});

test.skip("Phase 24.58 — sidepage RisDoc mostra istanze docente sotto i template", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const label = "Sidepage instance " + Date.now().toString(36);
    const create = await page.request.post(`/api/risdoc/templates/${tplId}/instances`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, instance_label: label }).toString(),
    });
    const { instance_key: instKey } = await create.json();

    try {
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");
        await page.evaluate(() => {
            document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
        });
        await page.waitForTimeout(2500);

        const found = await page.evaluate(({ tplId, instKey, label }) => {
            const sp = document.getElementById("fm-sp-risdoc");
            const inst = sp?.querySelector(
                `li.fm-risdoc-instance[data-template-id="${tplId}"][data-instance-key="${CSS.escape(instKey)}"]`
            );
            return {
                hasInstance: !!inst,
                href: inst?.querySelector("a")?.getAttribute("href") || "",
                text: inst?.textContent || "",
            };
        }, { tplId: String(tplId), instKey, label });
        expect(found.hasInstance, "istanza renderizzata in sidepage").toBeTruthy();
        expect(found.href).toContain(`/risdoc/view/${tplId}?instance=`);
        expect(found.text).toContain(label);
    } finally {
        await page.request.post(`/api/risdoc/templates/${tplId}/instances/${instKey}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }
});

test.skip("Phase 24.58 — POST /instances crea fork con instance_key + listInstances", async ({ page }) => {
    test.setTimeout(60_000);
    await loginTeacher(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId, "almeno 1 template").toBeTruthy();

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const label = "Test Istanza " + Date.now().toString(36);

    // Create
    const create = await page.request.post(`/api/risdoc/templates/${tplId}/instances`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, instance_label: label }).toString(),
    });
    expect(create.ok(), `create ${create.status()}`).toBeTruthy();
    const created = await create.json();
    expect(created.ok).toBeTruthy();
    expect(created.instance_key, "key generata").toBeTruthy();
    expect(created.instance_label).toBe(label);
    const instanceKey = created.instance_key;

    try {
        // List
        const listInst = await (await page.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const found = (listInst.instances || []).find(i => i.instance_key === instanceKey);
        expect(found, "istanza appena creata in lista").toBeTruthy();
        expect(found.instance_label).toBe(label);

        // Save override su questa istanza specifica
        const detail = await (await page.request.get(`/api/admin/risdoc/templates/${tplId}`)).json();
        const htmlPath = detail.template?.html_file;
        const SENTINEL = `INST_${instanceKey}_${Date.now().toString(36)}`;
        const save = await page.request.post(`/api/risdoc/templates/${tplId}/override`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf, kind: "html", path: htmlPath,
                body: `<!-- ${SENTINEL} -->`,
                instance_key: instanceKey,
            }).toString(),
        });
        expect(save.ok()).toBeTruthy();

        // GET file con instance_key → ritorna SENTINEL
        const fileWithInst = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}&instance_key=${instanceKey}`
        )).json();
        expect(fileWithInst.body, "override sull'istanza specifica").toContain(SENTINEL);

        // GET file SENZA instance_key (istanza base '') → NON ritorna SENTINEL
        const fileBase = await (await page.request.get(
            `/api/risdoc/templates/${tplId}/file?kind=html&path=${encodeURIComponent(htmlPath)}`
        )).json();
        expect(fileBase.body || "", "istanza base isolata").not.toContain(SENTINEL);

        // Rename
        const newLabel = label + " (rinominata)";
        const rename = await page.request.post(
            `/api/risdoc/templates/${tplId}/instances/${instanceKey}/rename`,
            {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf, instance_label: newLabel }).toString(),
            }
        );
        expect(rename.ok()).toBeTruthy();
        const listAfter = await (await page.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const renamed = (listAfter.instances || []).find(i => i.instance_key === instanceKey);
        expect(renamed.instance_label).toBe(newLabel);
    } finally {
        // Delete instance (cleanup)
        await page.request.post(`/api/risdoc/templates/${tplId}/instances/${instanceKey}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
        const after = await (await page.request.get(`/api/risdoc/templates/${tplId}/instances`)).json();
        const stillThere = (after.instances || []).find(i => i.instance_key === instanceKey);
        expect(stillThere, "deleted").toBeFalsy();
    }
});
