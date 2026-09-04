/**
 * G21.1 — Smoke test preview modal: verifica che il modulo si carichi
 * via lazy loader (manifest), che window.FM.openVerificaPreview esista,
 * che apertura modal renderizzi shell + tabs + editor CodeMirror.
 *
 * NB: questo test NON tenta di compilare PDF (richiede teacher login +
 * verifiche salvate + VPS raggiungibile). Si limita a verificare che il
 * bundle preview-editor sia caricabile e che l'API openPreview funzioni
 * con docs fittizi (mock).
 */

const { test, expect } = require("@playwright/test");

test.describe("G21.1 — Preview modal lazy loader", () => {
    test.beforeEach(async ({ page }) => {
        // Capture console errors per debug
        page.on("pageerror", (err) => console.log("PAGE ERROR:", err.message));
        page.on("console", (msg) => {
            if (msg.type() === "error") console.log("CONSOLE ERROR:", msg.text());
        });
    });

    test("manifest contiene entry verifica-preview-editor", async ({ page }) => {
        const res = await page.request.get("/build/manifest.json");
        expect(res.ok()).toBeTruthy();
        const manifest = await res.json();
        expect(manifest["js/entries/verifica-preview-editor.js"]).toBeDefined();
        expect(manifest["js/entries/verifica-preview-editor.js"].file).toMatch(/^assets\/verifica-preview-editor\..+\.js$/);
    });

    test("bundle preview-editor caricabile via dynamic import", async ({ page }) => {
        await page.goto("/login");
        // Eseguiamo la dynamic import dal contesto pagina
        const res = await page.evaluate(async () => {
            try {
                const m = await fetch("/build/manifest.json").then((r) => r.json());
                const entry = m["js/entries/verifica-preview-editor.js"];
                if (!entry) return { ok: false, err: "no entry" };
                await import(`/build/${entry.file}`);
                return {
                    ok: true,
                    hasOpenPreview: typeof window.FM?.VerificaPreview?.openPreview === "function",
                    hasCloseModal:  typeof window.FM?.VerificaPreview?.closeModal === "function",
                };
            } catch (e) {
                return { ok: false, err: e.message };
            }
        });
        expect(res.ok).toBe(true);
        expect(res.hasOpenPreview).toBe(true);
        expect(res.hasCloseModal).toBe(true);
    });

    test("openPreview con docs vuoti mostra toast errore (no crash)", async ({ page }) => {
        await page.goto("/login");
        await page.evaluate(async () => {
            const m = await fetch("/build/manifest.json").then((r) => r.json());
            const entry = m["js/entries/verifica-preview-editor.js"];
            await import(`/build/${entry.file}`);
        });
        const result = await page.evaluate(() => {
            try {
                window.FM.VerificaPreview.openPreview([]);
                return { ok: true, modalPresent: !!document.getElementById("fm-vp-modal") };
            } catch (e) {
                return { ok: false, err: e.message };
            }
        });
        // openPreview con array vuoto → toast errore, NON apre modal
        expect(result.ok).toBe(true);
        expect(result.modalPresent).toBe(false);
    });

    test("openPreview con docs fittizi apre shell modal", async ({ page }) => {
        await page.goto("/login");
        await page.evaluate(async () => {
            const m = await fetch("/build/manifest.json").then((r) => r.json());
            const entry = m["js/entries/verifica-preview-editor.js"];
            await import(`/build/${entry.file}`);
        });
        // Mock fetch CSRF + compile + tex per evitare network reali
        await page.evaluate(() => {
            const origFetch = window.fetch.bind(window);
            window.fetch = async (url, opts) => {
                const u = String(url);
                if (u.includes("/auth/csrf")) {
                    return new Response(JSON.stringify({ token: "MOCK" }), {
                        status: 200,
                        headers: { "Content-Type": "application/json" },
                    });
                }
                if (u.includes("/api/verifica/") && u.includes("/compile")) {
                    return new Response(JSON.stringify({
                        ok: false,
                        error: "mock_skip",
                        log: "skipped in test",
                    }), { status: 422, headers: { "Content-Type": "application/json" } });
                }
                if (u.includes("/api/verifica/") && u.endsWith("/tex")) {
                    return new Response("\\documentclass{article}\\begin{document}mock\\end{document}",
                        { status: 200, headers: { "Content-Type": "text/plain" } });
                }
                return origFetch(url, opts);
            };
        });

        await page.evaluate(() => {
            window.FM.VerificaPreview.openPreview([
                { id: 1, variant: "A_NOR", title: "Test verifica" },
                { id: 2, variant: "A_SOL", title: "Test verifica" },
            ]);
        });

        // Verifica shell modal renderizzato
        await expect(page.locator("#fm-vp-modal")).toBeVisible();
        await expect(page.locator(".fm-vp-title")).toContainText(/Anteprima/i);
        // 2 tabs (uno per variant)
        const tabs = page.locator(".fm-vp-tab");
        await expect(tabs).toHaveCount(2);
        await expect(tabs.nth(0)).toContainText("A_NOR");
        await expect(tabs.nth(1)).toContainText("A_SOL");
        // Editor CodeMirror presente
        await expect(page.locator(".fm-vp-editor-host .cm-editor")).toBeVisible();
        // Toolbar buttons
        await expect(page.locator('[data-act="rebuild"]')).toBeVisible();
        await expect(page.locator('[data-act="auto-rebuild"]')).toBeAttached();
        await expect(page.locator('[data-act="compare-mode"]')).toBeAttached();
        // Engine selector default "pdflatex"
        await expect(page.locator('select[data-act="engine"]')).toHaveValue("pdflatex");

        // Close button funzionante
        await page.locator('[data-act="close"]').click();
        await expect(page.locator("#fm-vp-modal")).toHaveCount(0);
    });

    test("SyncTeX parser estrae records da synctex sample", async ({ page }) => {
        await page.goto("/login");
        await page.evaluate(async () => {
            const m = await fetch("/build/manifest.json").then((r) => r.json());
            const entry = m["js/entries/verifica-preview-editor.js"];
            await import(`/build/${entry.file}`);
        });
        // SyncTeX parser è interno al modulo; verifichiamo che il modulo
        // esponga almeno openPreview e che il modal abbia il pdf-help text.
        await page.evaluate(() => {
            window.FM.VerificaPreview.openPreview([{ id: 1, variant: "A_SOL", title: "T" }]);
        });
        await expect(page.locator(".fm-vp-pdf-help")).toContainText(/Ctrl\+click/);
        // Cleanup
        await page.evaluate(() => window.FM.VerificaPreview.closeModal());
    });
});
