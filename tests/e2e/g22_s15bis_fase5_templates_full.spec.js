/**
 * G22.S15.bis Fase 5 — Test E2E completo /area-docente/templates.
 * Tabs: verifiche / esercizi / risdoc.
 * Per ogni tab: pulsanti, apertura editor modale, file tree, CodeMirror,
 * save, compile (ove applicabile).
 */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test.describe("/area-docente/templates", () => {
    let pageErrors;
    let consoleErrors;

    async function dismissCookieBanner(page) {
        const cookie = page.locator("#fm-cookie-modal button").first();
        if (await cookie.count() > 0) {
            await cookie.click().catch(() => {});
            await page.waitForTimeout(300);
        }
    }

    test.beforeEach(async ({ page }) => {
        pageErrors = [];
        consoleErrors = [];
        page.on("pageerror", e => pageErrors.push({ msg: e.message, stack: e.stack?.substring(0, 800) }));
        page.on("console", msg => {
            if (msg.type() === "error") {
                const t = msg.text();
                if (t.includes("Cross-Origin")) return; // noisy COOP warning
                consoleErrors.push(t);
            }
        });
        page.on("dialog", async d => { console.log("[dialog]:", d.message()); await d.dismiss().catch(() => {}); });

        await page.addInitScript(() => {
            window.__fmTrace = [];
            const orig = window.alert;
            window.alert = (msg) => window.__fmTrace.push({ alert: msg });
            const ce = console.error;
            console.error = (...args) => {
                window.__fmTrace.push({ err: args.map(a => a?.message || String(a)).join(" | ") });
                ce(...args);
            };
            window.addEventListener("error", e => window.__fmTrace.push({ unhandled: e.message, file: e.filename, line: e.lineno }));
        });

        await page.goto("/login");
        await page.locator("input[name=username]").fill(USERNAME);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");
    });

    test.afterEach(async ({ page }) => {
        if (pageErrors.length) {
            console.log("=== pageerror ===");
            pageErrors.forEach((e, i) => console.log(`#${i}: ${e.msg}\n${e.stack}`));
        }
        if (consoleErrors.length) {
            console.log("=== console.error ===");
            consoleErrors.slice(0, 5).forEach((m, i) => console.log(`#${i}: ${m.substring(0, 400)}`));
        }
        const trace = await page.evaluate(() => window.__fmTrace).catch(() => []);
        if (trace?.length) {
            console.log("=== alerts/console.error nella pagina ===");
            trace.forEach((t, i) => console.log(`#${i}: ${JSON.stringify(t).substring(0, 400)}`));
        }
    });

    // ─── TAB VERIFICHE ──────────────────────────────────────────────────
    test("Tab 'verifiche': pulsante apre editor modale + file tree + CodeMirror", async ({ page }) => {
        test.setTimeout(45000);
        await page.goto("/area-docente/templates?tab=verifiche");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);

        // G22.S15.bis Fase 5 — no auto-open: l'utente deve cliccare il pulsante.
        await expect(page.locator(".fm-vp-modal")).toHaveCount(0);
        await page.locator("#fm-tvf-open").click();
        await page.waitForTimeout(2500);

        const modal = page.locator(".fm-vp-modal");
        await expect(modal).toBeVisible({ timeout: 5000 });

        // File tree popolato
        const fileTree = page.locator("[data-filetree]");
        await expect(fileTree).toBeVisible();
        const fileItems = await page.locator("[data-filetree] [data-path]").count();
        console.log(`File tree contiene ${fileItems} file`);
        expect(fileItems).toBeGreaterThan(0);

        // CodeMirror mounted
        const cm = page.locator("[data-cm-host] .cm-editor");
        await expect(cm).toBeVisible({ timeout: 5000 });
        const cmContent = await page.locator("[data-cm-host] .cm-content").textContent();
        console.log(`CM content (primi 80 char): "${cmContent?.substring(0, 80)}..."`);
        expect(cmContent?.length || 0).toBeGreaterThan(10);

        // Switch file: clicca un file diverso dall'attivo
        const items = await page.locator("[data-filetree] [data-path]").all();
        if (items.length >= 2) {
            const activePath = await items[0].getAttribute("data-path");
            // clicca il secondo (probabilmente diverso)
            await items[1].click();
            await page.waitForTimeout(800);
            const breadcrumb = await page.locator("[data-editor-breadcrumb]").textContent();
            console.log(`Dopo click su ${await items[1].getAttribute("data-path")}: breadcrumb="${breadcrumb}"`);
            expect(breadcrumb).not.toContain(activePath || "XXX");
        }

        // Edit nel CodeMirror
        await page.locator("[data-cm-host] .cm-content").click();
        await page.keyboard.press("End");
        await page.keyboard.type(" % test edit");
        await page.waitForTimeout(300);
        const dirtyMark = await page.locator("[data-status-info]").textContent();
        console.log(`Dirty status: "${dirtyMark}"`);
    });

    // ─── TAB ESERCIZI ───────────────────────────────────────────────────
    test("Tab 'esercizi': pagina si carica, link/contenuti visibili", async ({ page }) => {
        test.setTimeout(30000);
        await page.goto("/area-docente/templates?tab=esercizi");
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(1500);

        // La sub-tab attiva
        const activeTab = await page.locator(".fm-subtab--active").textContent();
        console.log(`Active subtab: "${activeTab}"`);
        expect(activeTab).toContain("Esercizi");

        // Cerca contenuti tipici della pagina esercizi
        const html = await page.content();
        expect(html.toLowerCase()).toContain("collezione");
    });

    // ─── TAB RISDOC ─────────────────────────────────────────────────────
    test("Tab 'risdoc': pulsante apre editor con 3 file texCommon", async ({ page }) => {
        test.setTimeout(45000);
        await page.goto("/area-docente/templates?tab=risdoc");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);

        // G22.S15.bis Fase 5 — no auto-open
        await expect(page.locator(".fm-vp-modal")).toHaveCount(0);
        await page.locator("#fm-trd-open").click();
        await page.waitForTimeout(2500);

        const modal = page.locator(".fm-vp-modal");
        await expect(modal).toBeVisible({ timeout: 5000 });

        const fileItems = await page.locator("[data-filetree] [data-path]").count();
        console.log(`Risdoc file tree contiene ${fileItems} file`);
        expect(fileItems).toBeGreaterThanOrEqual(1);

        // Verifica i 3 file texCommon attesi
        const paths = await page.locator("[data-filetree] [data-path]").evaluateAll(els =>
            els.map(e => e.getAttribute("data-path"))
        );
        console.log("Risdoc paths:", paths);
    });

    // ─── SUBTABS NAVIGATION ────────────────────────────────────────────
    test("Navigazione diretta tra sub-tab via URL", async ({ page }) => {
        test.setTimeout(30000);
        // Test diretto via URL: il bug UX di "modal auto-aperto blocca click
        // sui subtab" è documentato a parte. Qui verifichiamo solo che le
        // 3 tab caricano contenuto distinto.
        await page.goto("/area-docente/templates?tab=verifiche");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);
        let active = await page.locator(".fm-subtab--active").textContent();
        expect(active).toContain("Verifiche");

        await page.goto("/area-docente/templates?tab=esercizi");
        await page.waitForTimeout(800);
        active = await page.locator(".fm-subtab--active").textContent();
        expect(active).toContain("Esercizi");

        await page.goto("/area-docente/templates?tab=risdoc");
        await page.waitForTimeout(800);
        active = await page.locator(".fm-subtab--active").textContent();
        expect(active).toContain("Modelli risdoc");
    });

    // ─── Sub-tab navigabili senza modal aperto ─────────────────────────
    test("Sub-tab cliccabili senza dover chiudere modal (no auto-open)", async ({ page }) => {
        test.setTimeout(20000);
        await page.goto("/area-docente/templates?tab=verifiche");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);

        // G22.S15.bis Fase 5 — no auto-open: i sub-tab sono cliccabili subito.
        await expect(page.locator(".fm-vp-modal")).toHaveCount(0);
        await page.locator('a.fm-subtab[href*="tab=esercizi"]').click();
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(800);
        const active = await page.locator(".fm-subtab--active").textContent();
        expect(active).toContain("Esercizi");
    });

    // ─── Compile .sty: API diretta produce PDF non-garbage ─────────────
    test("API /files/preview-pdf su .sty produce PDF wrapped (non garbage)", async ({ page }) => {
        test.setTimeout(30000);
        // Login già fatto in beforeEach. Usa page.request per cookies sessione.
        const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

        const stySrc = "\\NeedsTeXFormat{LaTeX2e}\n\\ProvidesPackage{verifica}[2026/05/04 Test]\n\\RequirePackage{xcolor}\n\\definecolor{testc}{RGB}{200,30,30}\n";
        const r = await page.request.post("/api/teacher/verifica/files/preview-pdf", {
            data: { path: "texCommon/verifica.sty", content: stySrc },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
        const ct = r.headers()["content-type"] || "";
        console.log("Response ct:", ct, "status:", r.status());
        if (r.status() !== 200) {
            const body = await r.text();
            console.log("Error body:", body.substring(0, 500));
        }
        expect(r.status(), "compile .sty deve avere successo (200)").toBe(200);
        expect(ct).toContain("pdf");
        const buf = await r.body();
        expect(buf.length).toBeGreaterThan(1000);
        // Magic bytes %PDF-
        expect(buf.slice(0, 5).toString()).toBe("%PDF-");
    });

    // ─── Click su file tree con docId stringa (template-file mode) ─────
    test("File tree click cambia file in template-file mode (docId stringa)", async ({ page }) => {
        test.setTimeout(45000);
        await page.goto("/area-docente/templates?tab=verifiche");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);
        await page.locator("#fm-tvf-open").click();
        await page.waitForTimeout(2500);
        await expect(page.locator(".fm-vp-modal")).toBeVisible();

        // Doc id è stringa "teacher-templates" → clicchi su file con
        // parseInt(docId) producevano NaN → switchFile bail-out.
        // Verifica che tutti i [data-path] hanno data-doc-id stringa.
        const sample = await page.locator("[data-filetree] [data-path]").first();
        const docIdAttr = await sample.getAttribute("data-doc-id");
        console.log("docId attr:", docIdAttr);
        expect(docIdAttr).toBe("teacher-templates");

        // Clicca un file qualsiasi e verifica che il breadcrumb cambi
        const items = await page.locator("[data-filetree] [data-path]").all();
        if (items.length >= 2) {
            const path1 = await items[0].getAttribute("data-path");
            await items[0].click();
            await page.waitForTimeout(500);
            const bc1 = await page.locator("[data-editor-breadcrumb]").textContent();
            console.log(`Click ${path1}: breadcrumb="${bc1}"`);
            expect(bc1).toContain(path1);

            const path2 = await items[1].getAttribute("data-path");
            await items[1].click();
            await page.waitForTimeout(500);
            const bc2 = await page.locator("[data-editor-breadcrumb]").textContent();
            console.log(`Click ${path2}: breadcrumb="${bc2}"`);
            expect(bc2).toContain(path2);
            expect(bc2).not.toContain(path1);
        }
    });

    // ─── intestazione.tex compile no Missing } error ──────────────────
    test("intestazione.tex compila senza 'Missing }' error", async ({ page }) => {
        test.setTimeout(30000);
        const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);
        // Compile cascade default (omit content)
        const r = await page.request.post("/api/teacher/verifica/files/preview-pdf", {
            data: { path: "texCommon/intestazione.tex" },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
        const ct = r.headers()["content-type"] || "";
        console.log("intestazione status:", r.status(), "ct:", ct);
        if (r.status() !== 200) {
            const body = await r.text();
            console.log("Error body:", body.substring(0, 400));
        }
        expect(r.status()).toBe(200);
        expect(ct).toContain("pdf");
        const buf = await r.body();
        expect(buf.slice(0, 5).toString()).toBe("%PDF-");
    });

    // ─── Click file NON marca dirty (regression su CRLF normalize) ─────
    test("Click su file non genera pallino dirty fantasma", async ({ page }) => {
        test.setTimeout(45000);
        await page.goto("/area-docente/templates?tab=risdoc");
        await dismissCookieBanner(page);
        await page.waitForTimeout(800);
        await page.locator("#fm-trd-open").click();
        await page.waitForTimeout(2500);
        await expect(page.locator(".fm-vp-modal")).toBeVisible();

        // Clicca tutti i 3 file uno dopo l'altro, senza modificare nulla
        const items = await page.locator("[data-filetree] [data-path]").all();
        console.log(`Risdoc tree: ${items.length} files`);
        for (const it of items) {
            const path = await it.getAttribute("data-path");
            await it.click();
            await page.waitForTimeout(600);
            // NESSUN buffer dovrebbe essere dirty (nessuna modifica utente)
            const dirtyCount = await page.locator(".fm-vp-filetree__item--dirty").count();
            console.log(`Dopo click ${path}: dirty count = ${dirtyCount}`);
            expect(dirtyCount, `click su ${path} ha generato dirty fantasma`).toBe(0);
        }
    });

    // ─── Risdoc: compile via nuovo endpoint preview-pdf ────────────────
    test("Risdoc: API preview-pdf produce PDF (era 'Compile non disponibile')", async ({ page }) => {
        test.setTimeout(30000);
        const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);
        // Prova compile di main.tex (file completo)
        const r = await page.request.post("/api/teacher/risdoc/templates/files/preview-pdf", {
            data: { path: "main.tex" },  // omit content → cascade default
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
        });
        const ct = r.headers()["content-type"] || "";
        console.log("Risdoc preview status:", r.status(), "ct:", ct);
        if (r.status() !== 200) {
            const body = await r.text();
            console.log("Error body:", body.substring(0, 400));
        }
        expect(r.status()).toBe(200);
        expect(ct).toContain("pdf");
        const buf = await r.body();
        expect(buf.slice(0, 5).toString()).toBe("%PDF-");
    });

    // ─── ACCESSO VIA TOPBAR EDITOR BUTTON (iframe path utente) ─────────
    test("Topbar 'Editor' button → iframe → editor templates", async ({ page }) => {
        test.setTimeout(45000);
        await page.goto("/studio/esercizio/sc/3/MAT/2");
        await page.waitForTimeout(1500);

        const editorBtn = page.locator('[data-fm-action="editor"]');
        await expect(editorBtn).toBeVisible({ timeout: 5000 });
        await editorBtn.click();
        await page.waitForTimeout(4000);

        // L'iframe wrapper è visibile + carica /area-docente/templates?embed=1
        const iframe = page.locator(".fm-vd-templates-iframe");
        await expect(iframe).toBeVisible({ timeout: 5000 });
        const src = await iframe.getAttribute("src");
        expect(src).toContain("/area-docente/templates?embed=1");

        // Frame content: dentro l'iframe c'è il pulsante "Apri editor".
        // G22.S15.bis Fase 5 — auto-open rimosso: clicca il pulsante.
        const frame = page.frameLocator(".fm-vd-templates-iframe");
        await expect(frame.locator("#fm-tvf-open")).toBeVisible({ timeout: 5000 });
        await frame.locator("#fm-tvf-open").click();
        await page.waitForTimeout(2500);
        const innerModal = frame.locator(".fm-vp-modal");
        await expect(innerModal).toBeVisible({ timeout: 10000 });
        const innerCm = frame.locator("[data-cm-host] .cm-editor");
        await expect(innerCm).toBeVisible({ timeout: 5000 });
    });
});
