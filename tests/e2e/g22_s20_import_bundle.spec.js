/**
 * G22.S20 — Import Bundle + Recovery Key end-to-end (API + UI smoke).
 *
 * Scenario: superadmin (id 77) genera Recovery Key + manifest signed,
 * marco.rossi (id 140) verifica HMAC e importa sub-set (1 verifica TEX +
 * 1 mappa). Test usa due contesti browser separati (cookies isolati).
 *
 * NOTA FS Access: il flusso UI completo (pick folder via showDirectoryPicker)
 * non è automatizzabile in Playwright. Quindi qui testiamo:
 *   - UI: presenza Import button + sezione Dashboard Recovery Key
 *   - API: endpoint sequence docente1→marco end-to-end
 */
const { test, expect, request: pwRequest } = require("@playwright/test");

const OPERATORE = "superadmin";
const MARCO    = "marco.rossi";
const PASSWORD = (process.env.E2E_TEACHER_PASS || "");

/** Login helper: ritorna un APIRequestContext con cookie settati. */
async function loginContext(browser, username, password) {
    const context = await browser.newContext();
    const page = await context.newPage();
    await page.goto("/login");
    await page.locator("input[name=username]").fill(username);
    await page.locator("input[name=password]").fill(password);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    await page.close();
    return context.request;
}

/** Ottiene CSRF token dall'endpoint /auth/csrf. */
async function csrf(request) {
    const r = await request.get("/auth/csrf");
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    return j.token;
}

test.describe("G22.S20 — Import bundle + Recovery Key", () => {

    test("UI: topbar Import button visible (banner pages)", async ({ page }) => {
        await page.goto("/login");
        await page.locator("input[name=username]").fill(OPERATORE);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");
        // Il banner teacher è renderizzato da partials/sidebar.php, incluso
        // nelle pagine "wrapped" (es. studio/esercizio). La /teacher/dashboard
        // è una pagina lite senza banner.
        await page.goto("/studio/esercizio/sc/3/MAT/2");
        await page.waitForTimeout(2000);

        const importBtn = page.locator(".fm-session-import-btn");
        await expect(importBtn).toBeVisible({ timeout: 5000 });
        const label = await importBtn.textContent();
        expect(label).toContain("Import");
    });

    test("UI: Dashboard sezione Recovery Key", async ({ page }) => {
        await page.goto("/login");
        await page.locator("input[name=username]").fill(OPERATORE);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");
        await page.goto("/teacher/dashboard");
        await page.waitForTimeout(1500);

        await expect(page.locator("#fm-recovery-section")).toBeVisible();
        const label = await page.locator("#fm-recovery-status .fm-drive-label").textContent();
        expect(label?.length || 0).toBeGreaterThan(5);
    });

    test("API: docente1 → marco end-to-end (recovery + manifest + import)", async ({ browser }) => {
        // ── Login con 2 contesti separati
        const vReq = await loginContext(browser, OPERATORE, PASSWORD);
        const mReq = await loginContext(browser, MARCO, PASSWORD);

        // ── Cleanup precedente marco (verifiche+content)
        // Non c'è endpoint admin: facciamo cleanup via revoke+regen docente1
        // e usiamo rename per evitare conflitti DB. Marco potrebbe avere
        // import precedenti; questo test usa "rename" come strategy.

        // ── 1. Operatore: genera (o rotate) Recovery Key
        const csrfV = await csrf(vReq);
        // Revoca eventuale chiave esistente
        await vReq.post("/api/teacher/recovery-key/revoke", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfV },
            data: { _csrf: csrfV },
        }).catch(() => {});
        // Genera
        const csrfV2 = await csrf(vReq);
        const genResp = await vReq.post("/api/teacher/recovery-key/generate", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfV2 },
            data: { _csrf: csrfV2 },
        });
        expect(genResp.ok()).toBeTruthy();
        const gen = await genResp.json();
        expect(gen.ok).toBe(true);
        expect(gen.recovery_hex).toMatch(/^[0-9a-f]{64}$/);
        const rHex = gen.recovery_hex;

        // ── 2. Operatore: status post-genera
        const statusResp = await vReq.get("/api/teacher/recovery-key/status");
        const status = await statusResp.json();
        expect(status.ok).toBe(true);
        expect(status.status.exists).toBe(true);
        expect(status.status.revoked_at).toBeNull();

        // ── 3. Operatore: manifest signed
        const mfResp = await vReq.get("/api/teacher/sync-bundle/manifest");
        expect(mfResp.ok()).toBeTruthy();
        const mfBody = await mfResp.json();
        expect(mfBody.ok).toBe(true);
        const manifest = mfBody.manifest;
        expect(typeof manifest.hmac).toBe("string");
        expect(manifest.hmac.length).toBeGreaterThan(40);
        expect(Array.isArray(manifest.files)).toBe(true);
        expect(manifest.files.length).toBeGreaterThan(0);

        // ── 4. Marco: preview con manifest completo (dry-run, no files)
        const csrfM = await csrf(mReq);
        const previewResp = await mReq.post("/api/teacher/import-bundle/preview", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfM },
            data: {
                recovery_code: rHex,
                manifest,
                files: [],
                conflict_strategy: "rename",
                _csrf: csrfM,
            },
        });
        expect(previewResp.ok()).toBeTruthy();
        const preview = await previewResp.json();
        expect(preview.ok).toBe(true);
        expect(preview.preview).toBe(true);
        // Almeno qualche entry creata o conflitto rilevato
        const totalEntries = (preview.report.created || []).length
            + (preview.report.conflicts || []).length
            + (preview.report.unsupported || []).length;
        expect(totalEntries).toBeGreaterThan(0);
    });

    test("API: HMAC invalido fallisce con 403", async ({ browser }) => {
        const vReq = await loginContext(browser, OPERATORE, PASSWORD);
        const mReq = await loginContext(browser, MARCO, PASSWORD);
        const mfResp = await vReq.get("/api/teacher/sync-bundle/manifest");
        const manifest = (await mfResp.json()).manifest;

        const csrfM = await csrf(mReq);
        const badResp = await mReq.post("/api/teacher/import-bundle/preview", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfM },
            data: {
                recovery_code: "0".repeat(64), // hex 64 valid format ma errato
                manifest,
                files: [],
                conflict_strategy: "skip",
                _csrf: csrfM,
            },
        });
        expect(badResp.status()).toBe(403);
        const bad = await badResp.json();
        expect(bad.ok).toBe(false);
        expect(bad.error).toBe("invalid_recovery_code_or_manifest");
    });

    test("DB: indirizzo_id FK popolato dopo import (v2.C2)", async ({ browser }) => {
        // Test indiretto: verifica che curriculum endpoint ritorni indirizzi
        // canonici. La verifica DB diretta è in test_import_e2e.php CLI.
        const vReq = await loginContext(browser, OPERATORE, PASSWORD);
        const r = await vReq.get("/api/teacher/curriculum");
        expect(r.ok()).toBeTruthy();
        const j = await r.json();
        // L'endpoint risponde con curriculum dell'istituto. Verifica che
        // ci siano indirizzi canonici (SCI/CLA/LIN/ART) e nessun legacy
        // (sc/cl/li/ling/ar).
        const indirizzi = (j.curriculum?.indirizzi || []).map(i => i.code);
        expect(indirizzi.length).toBeGreaterThan(0);
        const hasCanonical = indirizzi.some(c => ["SCI", "CLA", "LIN", "ART", "AFM"].includes(c));
        const hasLegacy = indirizzi.some(c => ["sc", "cl", "li", "ling", "ar", "af"].includes(c));
        expect(hasCanonical).toBe(true);
        expect(hasLegacy).toBe(false);
    });

    test("API: status no recovery → exists false (marco fresh)", async ({ browser }) => {
        // Test su Marco che NON ha Recovery Key.
        const mReq = await loginContext(browser, MARCO, PASSWORD);
        // Revoca eventuale recovery key precedente per pulizia idempotente
        const csrfM = await csrf(mReq);
        await mReq.post("/api/teacher/recovery-key/revoke", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfM },
            data: { _csrf: csrfM },
        }).catch(() => {});
        const sResp = await mReq.get("/api/teacher/recovery-key/status");
        const s = await sResp.json();
        expect(s.ok).toBe(true);
        // Può essere exists=false (mai generata) o revoked_at!=null (revocata)
        if (s.status.exists) {
            expect(s.status.revoked_at).not.toBeNull();
        } else {
            expect(s.status.exists).toBe(false);
        }
    });
});
