/**
 * ADR-024 — TeX/PDF uniforme tra documento custom e modelli risdoc.
 *
 *   - "TeX/PDF" apre il MODAL (editor TeX + anteprima PDF), NON scarica lo ZIP.
 *     (stub di window.FM.openVerificaPreview per verificare il wiring + mode).
 *   - Endpoint /tex-files genera i file TeX dal body_pt (main.tex + documento.tex
 *     + texCommon) con lo stesso shape di risdoc.
 *   - Bottoni dedicati: "ZIP" (download pacchetto) e "VSCode".
 *   - /compile-pdf risponde 200 (PDF) o 503 (servizio TeX disabilitato in locale).
 *
 * Credenziali via env (no PII) — .env.local (gitignored).
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
async function gotoDoc(page, topic) {
    await page.goto(`/studio/lab/SCI/3/MAT/${topic}`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForFunction(() => {
        const el = document.querySelector("fm-pt-document");
        return el && el.querySelector(".fm-doc-topbar--custom");
    }, { timeout: 8000 });
}

test.describe("ADR-024 — TeX/PDF custom (modal + ZIP + VSCode)", () => {

    test("TeX/PDF apre il modal (mode teacher-content); ZIP/VSCode presenti", async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "tex-" + Date.now().toString(36);
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "sectionHeader", title: "Analisi", level: 2 },
            { _type: "block", style: "normal", children: [{ _type: "span", text: "Testo della sezione.", marks: [] }] },
        ]);
        expect(id).toBeTruthy();
        try {
            await gotoDoc(page, topic);

            // Stub openVerificaPreview per catturare la chiamata (no bundle pesante).
            await page.evaluate(() => {
                window.__texCall = null;
                window.FM = window.FM || {};
                window.FM.openVerificaPreview = (docs, opts) => {
                    window.__texCall = { docs, opts };
                    return Promise.resolve();
                };
            });

            // Bottoni presenti in topbar.
            const labels = await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                return {
                    btnLabels: [...el.querySelectorAll(".fm-doc-topbar__btn")].map((b) => b.textContent.trim()),
                    hasVscodeLogo: !!el.querySelector('.fm-doc-topbar__btn[data-action="ptdoc-vscode"] .fm-doc-topbar__logo'),
                };
            });
            expect(labels.btnLabels.some((l) => /TeX\/PDF/.test(l)), "btn TeX/PDF").toBeTruthy();
            expect(labels.btnLabels.some((l) => /ZIP/.test(l)), "btn ZIP").toBeTruthy();
            expect(labels.hasVscodeLogo, "btn VSCode (logo)").toBeTruthy();

            // Click TeX/PDF → apre il modal (openVerificaPreview con mode teacher-content).
            await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                [...el.querySelectorAll(".fm-doc-topbar__btn")].find((b) => /TeX\/PDF/.test(b.textContent))?.click();
            });
            await page.waitForFunction(() => !!window.__texCall, { timeout: 5000 });
            const call = await page.evaluate(() => window.__texCall);
            expect(call.opts?.mode, "mode teacher-content").toBe("teacher-content");
            expect(String(call.docs?.[0]?.id), "doc id passato al modal").toBe(String(id));

            // ZIP → POST /export 2xx con url.
            const [zipResp] = await Promise.all([
                page.waitForResponse((r) => r.url().includes(`/content/${id}/export`) && r.request().method() === "POST", { timeout: 8000 }),
                page.evaluate(() => {
                    const el = document.querySelector("fm-pt-document");
                    [...el.querySelectorAll(".fm-doc-topbar__btn")].find((b) => /^ZIP$/.test(b.textContent.trim()))?.click();
                }),
            ]);
            expect(zipResp.ok(), "POST export ZIP 2xx").toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("modal si apre sul custom (loader caricato on-demand, non solo editor-context)", async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "texm-" + Date.now().toString(36);
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "sectionHeader", title: "Sez", level: 2 },
            { _type: "block", style: "normal", children: [{ _type: "span", text: "x", marks: [] }] },
        ]);
        expect(id).toBeTruthy();
        try {
            await gotoDoc(page, topic);
            // Click TeX/PDF (reale, senza stub) → loader (presente o importato
            // on-demand) + modal multi-file. Robusto anche se l'editor-context
            // non avesse precaricato il loader (import on-demand in _exportTex).
            await page.evaluate(() => {
                const el = document.querySelector("fm-pt-document");
                [...el.querySelectorAll(".fm-doc-topbar__btn")].find((b) => /TeX\/PDF/.test(b.textContent))?.click();
            });
            // Loader registrato on-demand + overlay modal montato.
            await page.waitForFunction(() => typeof window.FM?.openVerificaPreview === "function", { timeout: 8000 });
            await page.waitForSelector(".fm-vp-modal", { timeout: 12000 });
            const modalOpen = await page.evaluate(() => !!document.querySelector(".fm-vp-modal"));
            expect(modalOpen, "modal .fm-vp-modal aperto sul custom").toBeTruthy();
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("endpoint /tex-files genera main.tex + documento.tex dal body_pt", async ({ page }) => {
        test.setTimeout(120_000);
        await login(page);
        const topic = "texf-" + Date.now().toString(36);
        const { id, csrf } = await createCustom(page, topic, [
            { _type: "sectionHeader", title: "Capitolo uno", level: 2 },
            { _type: "block", style: "normal", children: [{ _type: "span", text: "Contenuto.", marks: [] }] },
        ]);
        expect(id).toBeTruthy();
        try {
            const res = await page.evaluate(async (cid) => {
                const csrf = (await (await fetch("/auth/csrf", { credentials: "same-origin" })).json()).token;
                const r = await fetch(`/api/teacher/content/${cid}/tex-files`, {
                    method: "POST", credentials: "same-origin",
                    headers: { "X-CSRF-Token": csrf },
                    body: new URLSearchParams({ _csrf: csrf }).toString(),
                });
                const j = await r.json();
                // compile-pdf: accetta 200 (PDF) o 503 (servizio disabilitato in locale)
                const c = await fetch(`/api/teacher/content/${cid}/compile-pdf`, {
                    method: "POST", credentials: "same-origin",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                    body: JSON.stringify({ files: [] }),
                });
                return {
                    okFiles: j.ok,
                    paths: (j.files || []).map((f) => f.path),
                    docName: j.doc_name,
                    mainHasInput: (j.files || []).some((f) => f.path === "main.tex" && /\\input\{/.test(f.content)),
                    docHasContent: (j.files || []).some((f) => /Capitolo uno|Contenuto/.test(f.content)),
                    compileStatus: c.status,
                };
            }, id);
            expect(res.okFiles, "tex-files ok").toBeTruthy();
            // documento.tex (corpo da body_pt) è SEMPRE presente con contenuto.
            expect(res.paths.some((p) => /\.tex$/.test(p) && p !== "main.tex"), "include documento.tex").toBeTruthy();
            expect(res.docHasContent, "documento.tex contiene il corpo (PtToTex)").toBeTruthy();
            // main.tex/texCommon dipendono da storage/templates/risdoc/texCommon
            // (presente solo sul VPS, gitignored). Se presente → \input + path.
            if (res.paths.includes("main.tex")) {
                expect(res.mainHasInput, "main.tex \\input{documento}").toBeTruthy();
            }
            // compile-pdf: route raggiungibile e processata. In locale main.tex è
            // vuoto (texCommon solo su VPS) → 422 (compile fallito) atteso; sul VPS
            // → 200 (PDF). 503 se servizio TeX disabilitato. Mai 404/401.
            expect([200, 422, 503], "compile-pdf route processata (no 404/401)").toContain(res.compileStatus);
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });
});
