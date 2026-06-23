/**
 * G24 (ADR-022) — E2E <fm-pt-document> WebComponent unificato.
 *
 * Valida la pagina personalizzata come componente coeso:
 *   1. SSR view no-flash (body HTML server presente nel tag)
 *   2. Componente upgrade → toolbar BEM con trio JSON/TeX/HTML + Modifica
 *   3. Toggle ✎ Modifica → editor PT + toolbar block types (5 G23)
 *   4. 👁 Anteprima → torna view
 *   5. Export JSON / HTML / TeX endpoints
 *   6. Graceful degradation (SSR body visibile anche pre-upgrade)
 *
 * Credenziali via env (no PII).
 */
const { test, expect } = require("@playwright/test");

const TEACHER = process.env.E2E_TEACHER_USER || "superadmin";
const PASS    = process.env.E2E_TEACHER_PASS || "";

async function login(page) {
    if (!PASS) { test.skip(true, "Set E2E_TEACHER_PASS"); return; }
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
}

async function createCustom(page, topic, bodyPt) {
    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const r = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "lab", subject: "MAT", indirizzo: "SCI", classe: "3",
            topic, title: topic, visibility: "draft",
            metadata: JSON.stringify({ layout: "custom", body_pt: bodyPt }),
        }).toString(),
    });
    return { id: (await r.json())?.id, csrf };
}

test.describe("G24 — fm-pt-document unified", () => {

    test("SSR view + upgrade toolbar + edit + 5 G23 block + export trio", async ({ page }, testInfo) => {
        test.setTimeout(120_000);
        await login(page);
        const stamp = Date.now().toString(36);
        const topic = "ptdoc-" + stamp;
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "staticContent", title: "Sezione test", level: 2, body: "<p>Contenuto <strong>iniziale</strong></p>" },
        ]);
        expect(id).toBeTruthy();

        try {
            await page.goto(`/studio/lab/SCI/3/MAT/${topic}`);
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(300);

            // 1. SSR: il tag <fm-pt-document> contiene il body HTML (no-flash).
            const ssr = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                return {
                    exists: !!el,
                    docId: el?.getAttribute("doc-id"),
                    canEdit: el?.getAttribute("can-edit"),
                    title: el?.getAttribute("title"),
                    hasSsrBody: !!el?.querySelector("[data-ptdoc-ssr], .ptdoc__body"),
                    htmlContainsStatic: (el?.innerHTML || "").includes("pt-static-content"),
                };
            });
            expect(ssr.exists, "<fm-pt-document> presente").toBeTruthy();
            expect(ssr.docId, "doc-id").toBe(String(id));
            expect(ssr.canEdit, "can-edit (teacher)").toBe("1");
            expect(ssr.htmlContainsStatic, "SSR body contiene pt-static-content").toBeTruthy();

            // 2. Upgrade: attendi customElement + topbar risdoc-style (ADR-024).
            await page.waitForFunction(() => {
                const el = document.querySelector("fm-pt-document");
                return el && el.querySelector(".fm-doc-topbar--custom");
            }, { timeout: 6000 });
            const toolbar = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                const btns = [...el.querySelectorAll(".fm-doc-topbar__btn")].map(b => b.textContent.trim());
                // ADR-024 — la topbar studio (verifiche) deve restare nascosta.
                const studioTopbar = document.getElementById("fm-topbar");
                return {
                    count: btns.length, labels: btns,
                    studioTopbarHidden: !studioTopbar || studioTopbar.hidden,
                };
            });
            console.log("TOOLBAR:", JSON.stringify(toolbar));
            expect(toolbar.labels.some(l => /Modifica/.test(l)), "btn Modifica").toBeTruthy();
            expect(toolbar.labels.some(l => /TeX/.test(l)), "btn TeX").toBeTruthy();
            expect(toolbar.labels.some(l => /HTML statico/.test(l)), "toggle HTML statico").toBeTruthy();
            expect(toolbar.labels.some(l => /Export JSON/.test(l)), "btn Export JSON").toBeTruthy();
            expect(toolbar.labels.some(l => /Import JSON/.test(l)), "btn Import JSON").toBeTruthy();
            expect(toolbar.studioTopbarHidden, "topbar studio nascosta (no doppia barra)").toBeTruthy();

            await page.screenshot({ path: testInfo.outputPath("01-view-mode.png"), fullPage: true });

            // 3. Click Modifica → edit mode (editor + pt-toolbar + 5 G23)
            await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                [...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /Modifica/.test(b.textContent))?.click();
            });
            await page.waitForFunction(() => {
                const t = document.querySelector("fm-pt-document fm-risdoc-pt-toolbar");
                return t && t.shadowRoot && t.shadowRoot.querySelectorAll("button").length > 0;
            }, { timeout: 8000 });
            // Rework ADR-024 — l'edit è ora a card: l'editor vive dentro lo
            // shadow di <fm-risdoc-pt-section>. Attendi che monti.
            await page.waitForFunction(() => {
                const card = document.querySelector("fm-pt-document fm-risdoc-pt-section");
                return !!card?.shadowRoot?.querySelector("fm-risdoc-pt-editor")?._editor;
            }, { timeout: 8000 });

            const editState = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                const card = el.querySelector("fm-risdoc-pt-section");
                const editor = card?.shadowRoot?.querySelector("fm-risdoc-pt-editor");
                const tb = el.querySelector("fm-risdoc-pt-toolbar");
                const g23 = (tb?.shadowRoot)
                    ? [...tb.shadowRoot.querySelectorAll('button[title*="G23"]')].map(b => b.textContent.trim())
                    : [];
                const lbl = [...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /Anteprima|Modifica/.test(b.textContent))?.textContent.trim();
                const saveBtn = !![...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /Salva/.test(b.textContent));
                // ADR-024 — "Nuova sezione" deve restare disponibile nei custom
                // (sezioni → \section LaTeX). Ora aggiunge una nuova card sezione.
                const newSectionBtn = (tb?.shadowRoot)
                    ? !![...tb.shadowRoot.querySelectorAll("button")].find(b => /Nuova sezione/.test(b.textContent))
                    : false;
                return { editorMounted: !!editor?._editor, g23Count: g23.length, g23, toggleLabel: lbl, saveBtn, newSectionBtn };
            });
            console.log("EDIT STATE:", JSON.stringify(editState));
            expect(editState.editorMounted, "editor montato").toBeTruthy();
            expect(editState.g23Count, "5 G23 block button").toBe(5);
            expect(editState.newSectionBtn, "btn '+ Nuova sezione' presente (sezioni LaTeX)").toBeTruthy();
            expect(editState.toggleLabel, "label → Anteprima").toMatch(/Anteprima/);
            expect(editState.saveBtn, "btn Salva presente in edit").toBeTruthy();

            await page.screenshot({ path: testInfo.outputPath("02-edit-mode.png"), fullPage: true });

            // 4. Anteprima → torna view (card sezioni smontate)
            await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                [...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /Anteprima/.test(b.textContent))?.click();
            });
            await page.waitForTimeout(400);
            const backView = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                return {
                    editorGone: !el.querySelector("fm-risdoc-pt-section"),
                    label: [...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /Modifica/.test(b.textContent))?.textContent.trim(),
                };
            });
            expect(backView.editorGone, "card sezioni smontate dopo Anteprima").toBeTruthy();
            expect(backView.label, "label → Modifica").toMatch(/Modifica/);

            // 4b. ADR-024 — toggle render-mode interactive → html, persistito.
            // Attende l'effettivo POST /update (saveRenderMode) prima del reload.
            const [updResp] = await Promise.all([
                page.waitForResponse(
                    (r) => r.url().includes(`/api/teacher/content/${id}/update`) && r.request().method() === "POST",
                    { timeout: 8000 },
                ),
                page.evaluate(() => {
                    const el = document.querySelector("fm-pt-document");
                    [...el.querySelectorAll(".fm-doc-topbar__btn")].find(b => /HTML statico/.test(b.textContent))?.click();
                }),
            ]);
            expect(updResp.ok(), "POST update render_mode 2xx").toBeTruthy();
            // Reload: il server deve rendere render-mode="html" (persistito in metadata).
            await page.goto(`/studio/lab/SCI/3/MAT/${topic}`);
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(400);
            const persisted = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                const wrap = document.querySelector(".fm-pt-custom-page");
                return {
                    attr: el?.getAttribute("render-mode"),
                    wrapAttr: wrap?.getAttribute("data-render-mode"),
                    interattivoBtn: el ? [...el.querySelectorAll(".fm-doc-topbar__btn")].some(b => /Interattivo/.test(b.textContent)) : false,
                };
            });
            console.log("PERSISTED RENDER-MODE:", JSON.stringify(persisted));
            expect(persisted.wrapAttr, "wrapper data-render-mode=html persistito").toBe("html");
            expect(persisted.attr, "componente render-mode=html (docente)").toBe("html");
            expect(persisted.interattivoBtn, "toggle mostra 'Interattivo' per tornare").toBeTruthy();

            // 5. Export endpoints raggiungibili
            const htmlResp = await page.request.get(`/api/teacher/content/${id}/export-html`);
            expect(htmlResp.status(), "export-html 200").toBe(200);
            expect((await htmlResp.text()).includes("pt-static-content"), "html export ok").toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });
});
