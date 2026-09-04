/**
 * Phase 24.39 — full flow teacher dynamic page creation.
 *
 * Simula utente reale (superadmin):
 *   1. Login
 *   2. Open lab sidepage + edit mode
 *   3. Click ➕ Nuovo → modal
 *   4. Compila titolo + topic + visibility
 *   5. Apri sezione PT editor + insert blocks
 *   6. Submit form
 *   7. Verifica content creato in DB
 *   8. Apri /studio/lab/.../{topic} → verifica preview HTML
 *   9. Click 📥 export → verifica download success
 *   10. Cleanup
 *
 * Cattura tutti pageerrors + console errors lungo il flow.
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

// Phase 24.53 — il modal NON include più <fm-pt-editor>. Il body_pt
// si edita su /studio (inline). Questo test era end-to-end sul vecchio
// flow; il nuovo flow è coperto da:
//   - risdoc_pt_layout_choice (create body_pt seed da radio exercises)
//   - risdoc_pt_create_in_renamed_cat (full UI create via + Nuovo)
//   - risdoc_pt_template_picker (template picker visibility)
//   - risdoc_pt_studio_preview (PT body_pt rendering server-side)
test.skip("teacher full flow — create dynamic lab page via PT editor", async ({ page }) => {
    test.setTimeout(90_000);

    const errors = [];
    const consoleErrors = [];
    page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));
    page.on("console", (msg) => {
        if (msg.type() === "error") {
            const t = msg.text();
            // Skip warning ProseMirror noto (pre-fix in 24.38, può ancora apparire da bundle cache)
            if (!t.includes("ProseMirror") && !t.includes("favicon")) {
                consoleErrors.push(t);
            }
        }
    });

    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    // Setup: state dropdown sidebar (necessario per createContent)
    await page.evaluate(() => {
        const set = (id, v) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = v;
            el.dispatchEvent(new Event("change", { bubbles: true }));
        };
        set("sel-iis", "sc");
        set("sel-cls", "2s");
        set("sel-mater", "MAT");
    });
    await page.waitForTimeout(500);

    // Apri lab sidepage
    await page.evaluate(() => {
        const btn = document.querySelector('.fm-sb-sec[data-sidepage="lab"]');
        if (btn) btn.click();
    });
    await page.waitForTimeout(2500);

    // Verifica edit button + click
    const editClicked = await page.evaluate(() => {
        const btn = document.querySelector('#fm-sp-lab .js-edit-section');
        if (!btn) return false;
        btn.click();
        return true;
    });
    expect(editClicked, "edit btn lab presente").toBeTruthy();
    await page.waitForTimeout(500);

    // Click ➕ Nuovo (Phase 24.47 — per-section .fm-section-add)
    await page.evaluate(() => {
        document.querySelector("#fm-sp-lab .fm-section-add")?.click();
    });
    await page.waitForTimeout(500);

    const modalOpen = await page.evaluate(() => !!document.querySelector(".fm-modal-backdrop"));
    expect(modalOpen, "modal aperto").toBeTruthy();

    // Compila form
    const topic = "FullFlowTest-" + Date.now().toString(36);
    await page.fill('.fm-modal-form input[name="title"]', "Full flow PT test");
    await page.fill('.fm-modal-form input[name="topic"]', topic);

    // Phase 24.53 — il modal non include più <fm-pt-editor>.
    // Il body_pt si edita poi sulla pagina /studio del documento.
    // Qui testiamo solo i metadati salvati al create.
    // NB: type=lab non ha radio layout (solo risdoc/bes/strcomp), quindi
    // il body_pt non viene seedato dal modal; viene popolato manualmente
    // via API direct call PRIMA del submit per il test di full flow.
    await page.evaluate(() => {
        // Inietto un hidden body_pt nel form via JS (simula edit inline post-create
        // ma testiamo qui in sequenza per compatibilità storica).
        const form = document.querySelector(".fm-modal-form");
        const ptInput = document.createElement("input");
        ptInput.type = "hidden";
        ptInput.name = "_test_body_pt_inject";
        ptInput.value = JSON.stringify([
            { _type: "block", style: "normal", children: [
                { _type: "span", text: "FULL_FLOW_BODY contenuto test", marks: ["strong"] },
            ]},
            { _type: "checkboxGroup", renderMode: "all", items: [
                { state: "x", label: "FF_OPT_A" },
                { state: "_", label: "FF_OPT_B" },
            ]},
        ]);
        form?.appendChild(ptInput);
    });

    // Submit form
    await page.evaluate(() => {
        document.querySelector(".fm-modal-form")?.dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    });
    await page.waitForTimeout(2000);

    // Verifica content creato (cerca via API)
    const list = await page.request.get(`/api/teacher/content?type=lab&subject=MAT`);
    const lj = await list.json();
    const created = (lj.rows || lj.content || []).find((r) => r.topic === topic);
    expect(created, `content '${topic}' presente`).toBeTruthy();
    const id = created.id;

    try {
        // Verifica metadata.body_pt
        const get = await page.request.get(`/api/teacher/content/${id}`);
        const meta = (await get.json()).content?.metadata;
        const obj = typeof meta === "string" ? JSON.parse(meta) : meta;
        expect(obj?.body_pt, "body_pt nel content").toBeTruthy();
        expect(JSON.stringify(obj.body_pt), "FULL_FLOW_BODY presente").toContain("FULL_FLOW_BODY");

        // Studio preview
        const studioUrl = `/studio/lab/sc/2s/MAT/${encodeURIComponent(topic)}`;
        const studio = await page.request.get(studioUrl);
        expect(studio.ok()).toBeTruthy();
        const html = await studio.text();
        expect(html, "FULL_FLOW_BODY in studio").toContain("FULL_FLOW_BODY");
        expect(html, "fm-pt-rendered wrapper").toContain('class="fm-contract-wrap fm-pt-rendered"');
        expect(html, "FF_OPT_A item").toContain("FF_OPT_A");

        // Export ZIP
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const exp = await page.request.post(`/api/teacher/content/${id}/export`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, mode: "zip" }).toString(),
        });
        expect(exp.ok()).toBeTruthy();
        const ej = await exp.json();
        expect(ej.url).toBeTruthy();
    } finally {
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        await page.request.post(`/api/teacher/content/${id}/delete`, {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf }).toString(),
        });
    }

    // No pageerrors né console errors lungo il flow
    expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
    expect(consoleErrors, `console errors:\n${consoleErrors.join("\n")}`).toHaveLength(0);
});

test("teacher risdoc sidepage edit + create flow", async ({ page }) => {
    test.setTimeout(60_000);
    const errors = [];
    const consoleErrors = [];
    page.on("pageerror", (err) => errors.push(`[pageerror] ${err.message}`));
    page.on("console", (msg) => {
        if (msg.type() === "error") {
            const t = msg.text();
            if (!t.includes("ProseMirror") && !t.includes("favicon")) {
                consoleErrors.push(t);
            }
        }
    });

    await loginTeacher(page);
    await page.goto("/");
    await page.waitForLoadState("domcontentloaded");

    // Setup state
    await page.evaluate(() => {
        const set = (id, v) => {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = v;
            el.dispatchEvent(new Event("change", { bubbles: true }));
        };
        set("sel-iis", "sc"); set("sel-cls", "2s"); set("sel-mater", "MAT");
    });
    await page.waitForTimeout(500);

    // Apri risdoc sidepage (test issue principale)
    await page.evaluate(() => {
        document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
    });
    await page.waitForTimeout(2500);

    // Verifica edit btn presente + bound + click
    const r = await page.evaluate(() => {
        const sp = document.getElementById("fm-sp-risdoc");
        const btn = sp?.querySelector(".js-edit-section");
        if (!btn) return { hasBtn: false };
        btn.click();
        return {
            hasBtn: true,
            bound: btn.dataset.fmEditBound === "1",
            editActive: sp.dataset.editActive === "1",
        };
    });
    expect(r.hasBtn, "btn js-edit-section presente").toBeTruthy();
    expect(r.bound, "btn bound").toBeTruthy();
    expect(r.editActive, "edit mode attivato click").toBeTruthy();
    await page.waitForTimeout(400);

    // Phase 24.47 — toolbar globale .fm-edit-toolbar rimossa,
    // verifichiamo invece che .fm-section-add sia iniettato per-categoria.
    const toolbarOk = await page.evaluate(() => {
        return !!document.querySelector("#fm-sp-risdoc .fm-section-add");
    });
    expect(toolbarOk, ".fm-section-add iniettato per-categoria su risdoc").toBeTruthy();

    expect(errors, `pageerrors:\n${errors.join("\n")}`).toHaveLength(0);
    expect(consoleErrors, `console errors:\n${consoleErrors.join("\n")}`).toHaveLength(0);
});
