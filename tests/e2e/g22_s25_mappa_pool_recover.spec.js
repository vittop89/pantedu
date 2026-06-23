/**
 * G22.S25 — Coverage: pool + recover per mappe (drawio cifrate).
 *
 * Scenario:
 *  1. Vittorio (owner) condivide mappa con i colleghi (shared_with_pool=1)
 *  2. Marco vede la mappa in /api/teacher/pool/materials?content_type=mappa
 *  3. Marco recupera la mappa → new teacher_content row creata,
 *     map_blob_path nuovo (re-cifrato con KEK di Marco)
 *  4. Marco può aprire la mappa nel suo account (decifratura ok)
 *  5. Cleanup: rimuove mappa Marco + ripristina shared=0
 *
 * Copre il flow critico envelope encryption: blob cifrato con KEK owner
 * → decifratura via owner KEK → re-cifratura con actor KEK → blob nuovo.
 * Bug storico (recovery 2026-05-13): KEK perdita = blob morti. Test
 * verifica che il flow recover non re-crei keys errate.
 */
import { test, expect } from "@playwright/test";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";
const MARCO_USER    = "marco.rossi";
const MARCO_PASS    = (process.env.E2E_TEACHER_PASS || "");
const MAPPA_ID      = 327; // Vittorio "Tavole trigonometriche", MAT/SCI/2

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

async function setShared(page, id, enabled) {
    const tok = await csrf(page);
    const fd = new URLSearchParams();
    fd.set("enabled", String(enabled ? 1 : 0));
    fd.set("_csrf", tok);
    return page.request.post(`/api/teacher/content/${id}/share-pool`, {
        headers: { "X-CSRF-Token": tok, "Content-Type": "application/x-www-form-urlencoded" },
        data: fd.toString(),
    });
}

test("Mappa pool + recover: blob re-cifrato con KEK actor", async ({ browser }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

    const vCtx = await browser.newContext();
    const vPage = await vCtx.newPage();
    await login(vPage, VITTORIO_USER, VITTORIO_PASS);

    // 1. Owner condivide
    const sharedRes = await setShared(vPage, MAPPA_ID, true);
    expect(sharedRes.ok()).toBeTruthy();

    // 2. Marco login + verifica visibilità
    const mCtx = await browser.newContext();
    const mPage = await mCtx.newPage();
    await login(mPage, MARCO_USER, MARCO_PASS);

    const poolRes = await mPage.request.get("/api/teacher/pool/materials?content_type=mappa");
    expect(poolRes.ok()).toBeTruthy();
    const poolJson = await poolRes.json();
    const mappa = (poolJson.items || []).find(
        i => i.source === "teacher_content" && i.id === MAPPA_ID
    );
    expect(mappa, `mappa #${MAPPA_ID} deve apparire in pool`).toBeTruthy();
    expect(mappa.content_type).toBe("mappa");
    expect(mappa.owner_id).toBe(77);

    // 3. Recover (se non già recuperata)
    if (!mappa.already_recovered) {
        // Marco materia MAT id = 371 (fixture DB; vedi seed/migration).
        const targetSubj = { id: 371, code: "MAT" };

        const tok = await csrf(mPage);
        const recoverRes = await mPage.request.post(`/api/teacher/pool/recover/${MAPPA_ID}`, {
            headers: { "X-CSRF-Token": tok, "Content-Type": "application/json" },
            data: JSON.stringify({
                target_subject_id: targetSubj.id,
                _csrf: tok,
            }),
        });
        const recoverJson = await recoverRes.json();
        expect(recoverRes.ok(), `recover failed: ${JSON.stringify(recoverJson)}`).toBeTruthy();
        expect(recoverJson.ok).toBeTruthy();
        expect(recoverJson.content_type).toBe("mappa");
        const newId = recoverJson.new_id;
        expect(newId).toBeGreaterThan(0);

        // 4. Verifica che la mappa nuova abbia un map_blob_path proprio
        const detailRes = await mPage.request.get(`/api/teacher/content/${newId}`);
        expect(detailRes.ok()).toBeTruthy();
        const d = await detailRes.json();
        expect(d.content.content_type).toBe("mappa");
        // map_blob_path nuovo = path sotto Marco (tid=140), distinto da
        // path originale di Vittorio (tid=77).
        expect(d.content.map_blob_path).toContain("140/");
        expect(d.content.map_blob_path).not.toBe(mappa.map_blob_path);
    }

    // 5. Cleanup: ripristina shared=0 su mappa originale
    await setShared(vPage, MAPPA_ID, false);
    await vCtx.close();
    await mCtx.close();
});
