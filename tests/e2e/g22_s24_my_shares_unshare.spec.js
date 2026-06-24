/**
 * G22.S24 — "I miei contenuti condivisi" + bulk unshare.
 *
 * Verifica che:
 *  1. Operatore legge /api/teacher/pool/my-shares e ottiene i suoi
 *     teacher_content + verifica_documents shared_with_pool=1.
 *  2. Bulk unshare POST /api/teacher/pool/unshare rimuove la condivisione
 *     e i contenuti spariscono dalla lista.
 */
import { test, expect } from "@playwright/test";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";

async function login(page, u, p) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', u);
    await page.fill('input[name="password"]', p);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function csrf(page) {
    const r = await page.request.get("/auth/csrf");
    const j = await r.json();
    return j.token || "";
}

test.describe("G22.S24 my shares + bulk unshare", () => {

    test("Operatore elenca shared + bulk unshare rimuove dalla lista", async ({ page }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
        await login(page, VITTORIO_USER, VITTORIO_PASS);

        // Setup: share esercizio #58 (propaga verifica #105)
        const tok = await csrf(page);
        const fd = new URLSearchParams();
        fd.set("enabled", "1");
        fd.set("_csrf", tok);
        await page.request.post("/api/teacher/content/58/share-pool", {
            headers: { "X-CSRF-Token": tok, "Content-Type": "application/x-www-form-urlencoded" },
            data: fd.toString(),
        });

        // 1. Lista my-shares deve contenere almeno l'esercizio #58 + verifica #105
        const r1 = await page.request.get("/api/teacher/pool/my-shares");
        expect(r1.ok()).toBeTruthy();
        const j1 = await r1.json();
        const sharedIds = (j1.items || []).map(i => `${i.source}:${i.id}`);
        expect(sharedIds, "deve contenere esercizio #58").toContain("teacher_content:58");
        expect(sharedIds, "deve contenere verifica abbinata #105 (propagation)").toContain("teacher_content:105");

        // 2. Bulk unshare di esercizio + verifica
        const items = [
            { source: "teacher_content", id: 58 },
            { source: "teacher_content", id: 105 },
        ];
        const tok2 = await csrf(page);
        const r2 = await page.request.post("/api/teacher/pool/unshare", {
            headers: { "X-CSRF-Token": tok2, "Content-Type": "application/json" },
            data: JSON.stringify({ items, _csrf: tok2 }),
        });
        expect(r2.ok()).toBeTruthy();
        const j2 = await r2.json();
        expect(j2.ok).toBeTruthy();
        expect(j2.total).toBeGreaterThanOrEqual(2);

        // 3. Verifica lista vuota (o senza #58/#105)
        const r3 = await page.request.get("/api/teacher/pool/my-shares");
        const j3 = await r3.json();
        const stillIds = (j3.items || []).map(i => `${i.source}:${i.id}`);
        expect(stillIds).not.toContain("teacher_content:58");
        expect(stillIds).not.toContain("teacher_content:105");

        // CLEANUP: ripristina share=1 su #58 (stato originale baseline per altri test)
        const tokR = await csrf(page);
        const fdR = new URLSearchParams();
        fdR.set("enabled", "1");
        fdR.set("_csrf", tokR);
        await page.request.post("/api/teacher/content/58/share-pool", {
            headers: { "X-CSRF-Token": tokR, "Content-Type": "application/x-www-form-urlencoded" },
            data: fdR.toString(),
        });
    });
});
