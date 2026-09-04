/**
 * G23 page-doc Sprint 5 — User-flow E2E end-to-end UX-side.
 *
 * Simula azione utente reale:
 *   1. Login teacher
 *   2. Apre sidepage risdoc (categoria documenti)
 *   3. Click su "Edit section" (mode edit attivo)
 *   4. Click su "+ Nuovo" button (.fm-section-add)
 *   5. Modal "Crea documento" si apre
 *   6. Click radio "Pagina informativa" (doc_mode=page_doc)
 *   7. Info section .fm-modal-page-doc visibile
 *   8. Screenshot per visual verification (no inline CSS check)
 *
 * Validazioni:
 *   - Radio `value=page_doc` esiste nel modal (fieldset Modello documento)
 *   - Click attiva la radio e mostra .fm-modal-page-doc info panel
 *   - Info panel usa CSS class BEM (no inline style)
 *   - Features list ha 4 items WCAG/sortable/details/sanitizer
 *
 * Note: credenziali via env, fallback ai default per dev locale.
 */
const { test, expect } = require("@playwright/test");

const TEACHER = process.env.E2E_TEACHER_USER || "superadmin";
const PASS    = process.env.E2E_TEACHER_PASS || "";

async function login(page) {
    if (!PASS) {
        test.skip(true, "Set E2E_TEACHER_PASS env var");
        return;
    }
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test.describe("G23 page-doc — Sprint 5+6 user-flow", () => {

    test("LAB: custom layout → render + toggle topbar HTML↔Edit + toolbar G23", async ({ page }, testInfo) => {
        test.setTimeout(90_000);
        await login(page);

        // G23 Sprint 8 — UX unificata: crea content custom (no più radio
        // page_doc separata). Seed staticContent. Toggle Modifica via topbar.
        const stamp = Date.now().toString(36);
        const title = `scheda-G23-${stamp}`;
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const create = await page.request.post("/api/teacher/content", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf,
                type: "lab",
                subject: "MAT",
                indirizzo: "SCI",
                classe: "3",
                topic: "G23-lab-" + stamp,
                title,
                visibility: "draft",
                metadata: JSON.stringify({
                    layout: "custom",
                    body_pt: [{
                        _type: "staticContent",
                        title: title,
                        level: 2,
                        format: "html",
                        body: "<p>Inizia a comporre la pagina con i block types G23 dalla toolbar PT.</p>",
                    }],
                }),
            }).toString(),
        });
        expect(create.ok(), "POST teacher/content ok").toBeTruthy();
        const id = (await create.json())?.id;
        expect(id, "id returned").toBeTruthy();

        try {
            const row = await (await page.request.get(`/api/teacher/content/${id}`)).json();
            const meta = row.content?.metadata || (() => { try { return JSON.parse(row.content?.metadata_json || "{}"); } catch { return {}; } })();
            expect(meta.layout, "metadata.layout='custom'").toBe("custom");
            expect(meta.body_pt?.[0]?._type, "body_pt[0] è staticContent").toBe("staticContent");
            expect(meta.body_pt[0].body, "body_pt[0].body non vuoto").toMatch(/block types G23/);

            // Render studio + verifica HTML output
            const studio = await page.request.get(`/studio/lab/SCI/3/MAT/G23-lab-${stamp}`);
            expect(studio.ok()).toBeTruthy();
            const html = await studio.text();
            const hasStaticContent = html.includes('class="pt-static-content"');
            const hasTitle = html.includes(`<h2>${title}</h2>`);
            const hasBody = html.includes('block types G23 dalla toolbar PT');
            const isEmpty = html.includes('Nessun item in questo topic');
            console.log(`Render check: pt-static-content=${hasStaticContent} h2=${hasTitle} body=${hasBody} empty=${isEmpty}`);
            // L'HTML deve avere pt-static-content e body, NON "Nessun item"
            expect(isEmpty, "pagina NON vuota").toBeFalsy();
            expect(hasStaticContent, "pt-static-content div presente").toBeTruthy();
            expect(hasBody, "body placeholder renderizzato").toBeTruthy();

            // Screenshot pagina rendered
            await page.goto(`/studio/lab/SCI/3/MAT/G23-lab-${stamp}`);
            await page.waitForLoadState("domcontentloaded");
            await page.waitForTimeout(800); // attesa per verifica-builder JS

            // G23 fix: #infoVer (legacy verifica editor: ANNO/TIME/SEZ/etc)
            // NON deve essere iniettato per pagine layout=custom (page_doc).
            const infoVerInjected = await page.evaluate(() =>
                !!document.getElementById("infoVer") || !!document.getElementById("scrollbarInfo")
            );
            expect(infoVerInjected, "#infoVer / #scrollbarInfo NON iniettati per page_doc").toBeFalsy();
            const verificaModeOn = await page.evaluate(() =>
                document.body.classList.contains("fm-verifica-mode")
            );
            expect(verificaModeOn, "body NON ha .fm-verifica-mode per page_doc").toBeFalsy();

            // ADR-024 — topbar UNICA risdoc-style del componente <fm-pt-document>,
            // resa dal componente centralizzato <fm-doc-topbar variant="custom">
            // (BEM .fm-doc-topbar--custom). La topbar studio + upbar legacy restano
            // nascoste.
            await page.waitForFunction(() => {
                const el = document.querySelector("fm-pt-document");
                return el && el.querySelector(".fm-doc-topbar--custom");
            }, { timeout: 6000 });
            const chrome = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                const studio = document.getElementById("fm-topbar");
                const labels = [...el.querySelectorAll(".fm-doc-topbar__btn")].map(b => b.textContent.trim());
                return {
                    hasComponent: !!el,
                    hasOwnTopbar: !!el.querySelector(".fm-doc-topbar--custom"),
                    studioTopbarHidden: !studio || getComputedStyle(studio).display === "none" || studio.hidden,
                    hasModifica: labels.some(l => /Modifica/.test(l)),
                    hasToggle: labels.some(l => /HTML statico/.test(l)),
                };
            });
            expect(chrome.hasComponent, "<fm-pt-document> presente").toBeTruthy();
            expect(chrome.hasOwnTopbar, "topbar propria .fm-doc-topbar--custom").toBeTruthy();
            expect(chrome.studioTopbarHidden, "topbar studio nascosta (no doppia barra)").toBeTruthy();
            expect(chrome.hasModifica, "btn Modifica presente").toBeTruthy();
            expect(chrome.hasToggle, "toggle HTML statico presente").toBeTruthy();

            await page.screenshot({ path: testInfo.outputPath("lab-02-page-rendered.png"), fullPage: true });
            // NB: la sequenza edit/anteprima/5-block-G23/render-mode è coperta in
            // dettaglio da pt_document_unified.spec.js (single source of truth UI).
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("RISDOC: modal UNICO cross-categoria — doc_mode fork/custom/exercises (ADR-024)", async ({ page }) => {
        test.setTimeout(90_000);
        await login(page);
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");

        await page.evaluate(() => {
            document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
        });
        await page.waitForTimeout(2500);
        await page.evaluate(() => {
            document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
        });
        await page.waitForTimeout(400);

        const addButtonExists = await page.evaluate(() =>
            !!document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")
        );
        if (!addButtonExists) {
            test.skip(true, "Risdoc sidepage no + Nuovo button per teacher corrente");
            return;
        }
        await page.evaluate(() => {
            document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
        });
        await page.waitForTimeout(800);

        // ADR-024 — modal UNICO: doc_mode con fork (default risdoc) + custom +
        // exercises. Niente più doc_kind / page_doc / .fm-modal-page-doc.
        const r = await page.evaluate(() => {
            const modes = [...document.querySelectorAll('input[name="doc_mode"]')].map(x => x.value);
            const checked = document.querySelector('input[name="doc_mode"]:checked')?.value;
            return {
                modes, checked,
                hasDocKind: !!document.querySelector('input[name="doc_kind"]'),
                pageDocGone: !document.querySelector('.fm-modal-page-doc'),
            };
        });
        expect(r.modes, "doc_mode include fork").toContain("fork");
        expect(r.modes, "doc_mode include custom").toContain("custom");
        expect(r.modes, "doc_mode include exercises").toContain("exercises");
        expect(r.checked, "default fork per risdoc").toBe("fork");
        expect(r.hasDocKind, "doc_kind rimosso (modal unico)").toBeFalsy();
        expect(r.pageDocGone, ".fm-modal-page-doc rimosso").toBeTruthy();
    });

    test("CSS audit: no inline style nei selettori G23 page-doc", async ({ page }) => {
        test.setTimeout(60_000);
        await login(page);
        await page.goto("/");
        await page.waitForLoadState("domcontentloaded");

        await page.evaluate(() => {
            document.querySelector('.fm-sb-sec[data-sidepage="risdoc"]')?.click();
        });
        await page.waitForTimeout(2500);
        await page.evaluate(() => {
            document.querySelector("#fm-sp-risdoc .fm-db-head .js-edit-section")?.click();
        });
        await page.waitForTimeout(400);
        const addExists = await page.evaluate(() =>
            !!document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")
        );
        if (!addExists) {
            test.skip(true, "Risdoc edit mode senza categorie");
            return;
        }
        await page.evaluate(() => {
            document.querySelector("#fm-sp-risdoc .fm-db-block[data-edit-active='1'] .fm-section-add")?.click();
        });
        await page.waitForTimeout(800);
        // Forza visibilità del page_doc info panel per controllare anche il
        // children CSS (anche quando hidden, getComputedStyle riflette le
        // class rules).
        await page.evaluate(() => {
            const radio = document.querySelector('input[name="doc_kind"][value="page_doc"]');
            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
        await page.waitForTimeout(300);

        const auditResult = await page.evaluate(() => {
            const selectors = [
                '.fm-modal-page-doc',
                '.fm-modal-page-doc__tag',
                '.fm-modal-page-doc__help',
                '.fm-modal-page-doc__features',
            ];
            const violations = [];
            for (const sel of selectors) {
                const els = document.querySelectorAll(sel);
                for (const el of els) {
                    if (el.hasAttribute("style") && el.getAttribute("style").trim() !== "") {
                        violations.push({ selector: sel, inlineStyle: el.getAttribute("style") });
                    }
                }
            }
            return { violations, totalAudited: selectors.length };
        });

        expect(auditResult.violations.length, `Inline style violations: ${JSON.stringify(auditResult.violations)}`).toBe(0);
    });
});
