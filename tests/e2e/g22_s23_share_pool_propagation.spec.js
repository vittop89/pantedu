/**
 * G22.S23 — Propagazione toggle Condividi tra esercizio↔verifica abbinati.
 *
 * Quando Vittorio apre un esercizio "Sistemi lineari", la pagina renderizza
 * sia "Esercizi per studenti" (teacher_content #58 content_type='esercizio')
 * sia "Esercizi per costruire la verifica" (teacher_content #105 content_type
 * ='verifica', topic='Sistemi lineari'). Il toggle Condividi deve agire su
 * entrambi.
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

async function fetchShared(page, id) {
    const r = await page.request.get(`/api/teacher/content/${id}`);
    const j = await r.json();
    return !!(j.content?.shared_with_pool);
}

async function togglePool(page, id, enabled) {
    const tok = await csrf(page);
    const fd = new URLSearchParams();
    fd.set("enabled", String(enabled ? 1 : 0));
    fd.set("_csrf", tok);
    const r = await page.request.post(`/api/teacher/content/${id}/share-pool`, {
        headers: {
            "X-CSRF-Token": tok,
            "Content-Type": "application/x-www-form-urlencoded",
        },
        data: fd.toString(),
    });
    return await r.json();
}

test.describe("G22.S23 share-pool propagation esercizio↔verifica", () => {

    test("share su esercizio (#58) propaga su verifica abbinata (#105) e viceversa", async ({ page }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
        await login(page, VITTORIO_USER, VITTORIO_PASS);

        // Reset baseline: esercizio=1 (già shared), verifica=0
        await togglePool(page, 105, false);

        // Step 1: share esercizio #58 → verifica #105 deve diventare shared
        const r1 = await togglePool(page, 58, true);
        expect(r1.ok).toBeTruthy();
        expect(r1.shared_with_pool).toBe(true);
        expect(r1.counterpart_id, "deve avere counterpart").toBe(105);
        expect(r1.counterpart_type).toBe("verifica");
        expect(await fetchShared(page, 105), "#105 propagated true").toBeTruthy();

        // Step 2: unshare verifica #105 → esercizio #58 deve diventare unshared
        const r2 = await togglePool(page, 105, false);
        expect(r2.ok).toBeTruthy();
        expect(r2.shared_with_pool).toBe(false);
        expect(r2.counterpart_id).toBe(58);
        expect(r2.counterpart_type).toBe("esercizio");
        expect(await fetchShared(page, 58), "#58 propagated false").toBeFalsy();

        // Restore: share esercizio (status precedente)
        await togglePool(page, 58, true);
    });
});
