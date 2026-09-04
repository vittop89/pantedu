/**
 * G22.S15.bis Fase 5 — Dashboard docente: cartella locale + log sync + GitHub.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = "superadmin";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

test.describe("/teacher/dashboard sync sections", () => {
    test.beforeEach(async ({ page }) => {
        page.on("pageerror", e => console.log("[pageerror]", e.message));
        await page.goto("/login");
        await page.locator("input[name=username]").fill(USERNAME);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");
    });

    test("Dashboard mostra le 4 sezioni sync (Drive/Locale/GitHub/Log)", async ({ page }) => {
        await page.goto("/teacher/dashboard");
        await page.waitForTimeout(800);
        const cookieBtn = page.locator("#fm-cookie-modal button").first();
        if (await cookieBtn.count() > 0) await cookieBtn.click().catch(() => {});

        await expect(page.locator("#fm-drive-section")).toBeVisible();
        await expect(page.locator("#fm-local-section")).toBeVisible();
        await expect(page.locator("#fm-github-section")).toBeVisible();
        await expect(page.locator("#fm-sync-log-section")).toBeVisible();

        // Cartella locale: bottone pick presente
        await expect(page.locator("#fm-local-folder-pick")).toBeVisible();

        // G22.S15.bis Fase 5 — GitHub sync ora funzionante: bottone Configura
        // attivo + status pill che riflette config corrente (or 'Non configurato').
        await expect(page.locator("#fm-github-configure")).toBeEnabled();
        const githubText = await page.locator("#fm-github-section").textContent();
        expect(githubText).toContain("GitHub");
        expect(githubText).toContain("Personal Access Token");
    });

    test("Log sync legge da localStorage e mostra errori inseriti", async ({ page }) => {
        await page.goto("/teacher/dashboard");
        await page.waitForTimeout(500);
        const cookieBtn = page.locator("#fm-cookie-modal button").first();
        if (await cookieBtn.count() > 0) await cookieBtn.click().catch(() => {});

        // Inietta entry mock in localStorage
        await page.evaluate(() => {
            const entries = [
                { ts: "2026-05-08T10:00:00.000Z", target: "drive", kind: "error",
                  message: "verifica #42: quota_exceeded" },
                { ts: "2026-05-08T10:01:00.000Z", target: "local", kind: "ok",
                  message: "Fine. 12 OK · 0 errori" },
                { ts: "2026-05-08T10:02:00.000Z", target: "drive", kind: "error",
                  message: "mappa #99: timeout" },
            ];
            localStorage.setItem("fm:syncLog", JSON.stringify(entries));
            window.dispatchEvent(new CustomEvent("fm:sync-log-updated"));
        });
        await page.waitForTimeout(300);

        const list = await page.locator("#fm-sync-log-list").textContent();
        // Filtro default = "Solo errori" → mostra solo i 2 error entries
        expect(list).toContain("verifica #42");
        expect(list).toContain("mappa #99");
        expect(list).not.toContain("Fine. 12 OK"); // ok, escluso dal filtro

        // Cambio filtro a "Tutti"
        await page.locator("#fm-sync-log-filter").selectOption("all");
        await page.waitForTimeout(200);
        const listAll = await page.locator("#fm-sync-log-list").textContent();
        expect(listAll).toContain("Fine. 12 OK");

        // Pulisci log
        page.on("dialog", d => d.accept());
        await page.locator("#fm-sync-log-clear").click();
        await page.waitForTimeout(200);
        const listEmpty = await page.locator("#fm-sync-log-list").textContent();
        expect(listEmpty).toContain("Nessuna sincronizzazione");
    });

    test("Log sync mostra tutti e 3 i target: drive + local + github", async ({ page }) => {
        await page.goto("/teacher/dashboard");
        await page.waitForTimeout(500);
        const cookieBtn = page.locator("#fm-cookie-modal button").first();
        if (await cookieBtn.count() > 0) await cookieBtn.click().catch(() => {});

        // Inietta entries per i 3 target (ok + error per ognuno)
        await page.evaluate(() => {
            const entries = [
                { ts: "2026-05-08T08:00:00.000Z", target: "drive",  kind: "ok",
                  message: "Fine drive. 5 OK · 0 errori" },
                { ts: "2026-05-08T08:01:00.000Z", target: "drive",  kind: "error",
                  message: "verifica #11: rate_limited" },
                { ts: "2026-05-08T08:02:00.000Z", target: "local",  kind: "ok",
                  message: "Fine local. 8 OK · 0 errori" },
                { ts: "2026-05-08T08:03:00.000Z", target: "local",  kind: "error",
                  message: "intestazione.tex: write denied" },
                { ts: "2026-05-08T08:04:00.000Z", target: "github", kind: "ok",
                  message: "vittop89/pantedu-backup: 3 pushate, 2 invariate, 0 errori" },
                { ts: "2026-05-08T08:05:00.000Z", target: "github", kind: "error",
                  message: "verifica #99: github_pat_unauthorized" },
            ];
            localStorage.setItem("fm:syncLog", JSON.stringify(entries));
            window.dispatchEvent(new CustomEvent("fm:sync-log-updated"));
        });
        await page.waitForTimeout(300);

        // Filtro = "Tutti" per vedere tutti i 6 entry
        await page.locator("#fm-sync-log-filter").selectOption("all");
        await page.waitForTimeout(200);
        const listAll = await page.locator("#fm-sync-log-list").textContent();

        // Verifica TAG di ogni target presente nel render (formato: [drive], [local], [github])
        expect(listAll).toContain("[drive]");
        expect(listAll).toContain("[local]");
        expect(listAll).toContain("[github]");

        // Verifica messaggi specifici per ognuno
        expect(listAll).toContain("Fine drive. 5 OK");
        expect(listAll).toContain("rate_limited");
        expect(listAll).toContain("Fine local. 8 OK");
        expect(listAll).toContain("write denied");
        expect(listAll).toContain("pantedu-backup");
        expect(listAll).toContain("github_pat_unauthorized");

        // Filtro = "errore" → 3 entry (uno per target)
        await page.locator("#fm-sync-log-filter").selectOption("error");
        await page.waitForTimeout(200);
        const listErr = await page.locator("#fm-sync-log-list").textContent();
        expect(listErr).toContain("[drive]");
        expect(listErr).toContain("[local]");
        expect(listErr).toContain("[github]");
        expect(listErr).not.toContain("Fine drive. 5 OK"); // ok escluso
        expect(listErr).not.toContain("Fine local. 8 OK");
        expect(listErr).not.toContain("3 pushate");
    });
});
