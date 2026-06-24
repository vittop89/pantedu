/**
 * G22.S25 — Granularità share: grants espliciti per istituto/docente/gruppo.
 *
 * Scenario:
 *  1. Operatore crea un grant 'teacher' diretto a Marco su esercizio #58.
 *  2. shared_with_pool=0, Marco vede #58 nel pool (via grant esplicito).
 *  3. Operatore revoca il grant (setGrants con array vuoto). Marco non vede più.
 *
 *  + verifica creazione gruppo + share via gruppo.
 */
import { test, expect } from "@playwright/test";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";
const MARCO_PASS    = (process.env.E2E_TEACHER_PASS || "");
const MARCO_ID      = 140;

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

async function jsonPost(page, url, body) {
    const tok = await csrf(page);
    return page.request.post(url, {
        headers: { "X-CSRF-Token": tok, "Content-Type": "application/json" },
        data: JSON.stringify({ ...body, _csrf: tok }),
    });
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

test.describe("G22.S25 granular share grants", () => {

    test("grant teacher diretto: Marco vede esercizio anche con shared_with_pool=0", async ({ browser }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

        const vCtx = await browser.newContext();
        const vPage = await vCtx.newPage();
        await login(vPage, VITTORIO_USER, VITTORIO_PASS);

        // Baseline: rimuove shared_with_pool sul #58 + verifica abbinata (propagation rimuove anche #105).
        await setShared(vPage, 58, false);

        // Crea grant esplicito target=teacher Marco per esercizio #58.
        const g1 = await jsonPost(vPage, "/api/teacher/share/grants/teacher_content/58", {
            grants: [{ target_type: "teacher", target_id: MARCO_ID }],
        });
        expect(g1.ok()).toBeTruthy();
        const g1j = await g1.json();
        expect(g1j.count).toBe(1);

        // Marco context
        const mCtx = await browser.newContext();
        const mPage = await mCtx.newPage();
        await login(mPage, "marco.rossi", MARCO_PASS);

        const r1 = await mPage.request.get("/api/teacher/pool/materials?content_type=esercizio");
        expect(r1.ok()).toBeTruthy();
        const j1 = await r1.json();
        const sees58 = (j1.items || []).find(i => i.source === "teacher_content" && i.id === 58);
        expect(sees58, "Marco deve vedere #58 via grant teacher diretto").toBeTruthy();

        // Revoke: setGrants empty
        const g2 = await jsonPost(vPage, "/api/teacher/share/grants/teacher_content/58", { grants: [] });
        expect(g2.ok()).toBeTruthy();

        const r2 = await mPage.request.get("/api/teacher/pool/materials?content_type=esercizio");
        const j2 = await r2.json();
        const stillSees = (j2.items || []).find(i => i.source === "teacher_content" && i.id === 58);
        expect(stillSees, "post revoke, Marco non deve vedere #58").toBeFalsy();

        // CLEANUP: ripristina shared_with_pool=1 (baseline)
        await setShared(vPage, 58, true);

        await vCtx.close();
        await mCtx.close();
    });

    test("group: Operatore crea gruppo con Marco + grant target=group → Marco vede", async ({ browser }) => {
        if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

        const vCtx = await browser.newContext();
        const vPage = await vCtx.newPage();
        await login(vPage, VITTORIO_USER, VITTORIO_PASS);

        await setShared(vPage, 58, false);

        // Crea gruppo
        const cg = await jsonPost(vPage, "/api/teacher/share/groups", {
            name: `e2e-test-${Date.now()}`,
        });
        const groupId = (await cg.json()).id;
        expect(groupId).toBeGreaterThan(0);

        // Aggiungi Marco al gruppo
        const sm = await jsonPost(vPage, `/api/teacher/share/groups/${groupId}/members`, {
            member_ids: [MARCO_ID],
        });
        expect((await sm.json()).count).toBe(1);

        // Grant esercizio #58 al gruppo
        await jsonPost(vPage, "/api/teacher/share/grants/teacher_content/58", {
            grants: [{ target_type: "group", target_id: groupId }],
        });

        // Marco vede
        const mCtx = await browser.newContext();
        const mPage = await mCtx.newPage();
        await login(mPage, "marco.rossi", MARCO_PASS);
        const r = await mPage.request.get("/api/teacher/pool/materials?content_type=esercizio");
        const j = await r.json();
        const sees = (j.items || []).find(i => i.source === "teacher_content" && i.id === 58);
        expect(sees, "Marco deve vedere #58 via grant group").toBeTruthy();

        // Cleanup
        await jsonPost(vPage, `/api/teacher/share/groups/${groupId}/delete`, {});
        await setShared(vPage, 58, true);

        await vCtx.close();
        await mCtx.close();
    });
});
