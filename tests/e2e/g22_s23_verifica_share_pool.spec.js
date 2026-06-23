/**
 * G22.S23 — Share/recover di verifica_documents (vere verifiche TEX/PDF).
 *
 * Scenario:
 *  1. Vittorio condivide una verifica via POST /api/verifica/{id}/share-pool.
 *  2. Marco la vede nel pool con content_type='verifica_doc'.
 *  3. Vittorio la dis-condivide → Marco non la vede più.
 */
import { test, expect } from "@playwright/test";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";
const MARCO_USER    = "marco.rossi";
const MARCO_PASS    = (process.env.E2E_TEACHER_PASS || "");

async function login(page, username, password) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function csrfToken(page) {
    const r = await page.request.get("/auth/csrf");
    const j = await r.json();
    return j.token || "";
}

test.describe("G22.S23 verifica_documents share-pool", () => {

    test("Vittorio condivide una verifica → Marco la vede; ritira → Marco non la vede", async ({ browser }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

        // -- Vittorio context --
        const vCtx  = await browser.newContext();
        const vPage = await vCtx.newPage();
        await login(vPage, VITTORIO_USER, VITTORIO_PASS);
        const vList = await vPage.request.get("/api/verifica/list?materia=MAT");
        const vj    = await vList.json();
        const verifica = (vj.items || []).find(v => !v.shared_with_pool);
        expect(verifica, `Vittorio deve avere almeno una verifica MAT non shared (totali=${(vj.items||[]).length})`).toBeTruthy();

        // Attiva share-pool
        const tok = await csrfToken(vPage);
        const fd = new URLSearchParams();
        fd.set("enabled", "1");
        fd.set("_csrf", tok);
        const shareRes = await vPage.request.post(`/api/verifica/${verifica.id}/share-pool`, {
            headers: {
                "X-CSRF-Token": tok,
                "Content-Type": "application/x-www-form-urlencoded",
            },
            data: fd.toString(),
        });
        const shareBody = await shareRes.text();
        expect(shareRes.ok(), `share failed: ${shareRes.status()} body=${shareBody.slice(0, 200)}`).toBeTruthy();
        const sj = await shareRes.json();
        expect(sj.shared_with_pool).toBe(true);

        // -- Marco context --
        const mCtx  = await browser.newContext();
        const mPage = await mCtx.newPage();
        await login(mPage, MARCO_USER, MARCO_PASS);

        const mPool = await mPage.request.get("/api/teacher/pool/materials?content_type=verifica_doc");
        expect(mPool.ok()).toBeTruthy();
        const mj    = await mPool.json();
        const found = (mj.items || []).find(i => i.id === verifica.id && i.source === "verifica_documents");
        expect(found, `Marco deve vedere verifica #${verifica.id} nel pool come verifica_doc`).toBeTruthy();
        expect(found.owner_id).toBe(77);

        // Ritira share
        const fd2 = new URLSearchParams();
        fd2.set("enabled", "0");
        fd2.set("_csrf", tok);
        const unshareRes = await vPage.request.post(`/api/verifica/${verifica.id}/share-pool`, {
            headers: {
                "X-CSRF-Token": tok,
                "Content-Type": "application/x-www-form-urlencoded",
            },
            data: fd2.toString(),
        });
        expect(unshareRes.ok()).toBeTruthy();

        const mPool2 = await mPage.request.get("/api/teacher/pool/materials?content_type=verifica_doc");
        const mj2    = await mPool2.json();
        const stillThere = (mj2.items || []).find(i => i.id === verifica.id && i.source === "verifica_documents");
        expect(stillThere, "Dopo ritiro share, Marco non deve più vederla").toBeFalsy();

        await vCtx.close();
        await mCtx.close();
    });
});
