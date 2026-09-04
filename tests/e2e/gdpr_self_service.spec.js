/**
 * Phase 25.C14 — E2E test GDPR self-service endpoints (Art. 7, 16, 17, 20).
 *
 * Coverage:
 *   1. Consents — grant + listActive + revoke
 *   2. Deletion request — full lifecycle (request → confirm → cooling_off → cancel)
 *   3. Profile rettifica (Art. 16) — PATCH first_name
 *   4. Data export (Art. 20) — JSON download with user data
 *
 * Test user: marco.rossi (teacher non-super-admin per evitare side effect
 * sull'admin path). Cleanup deletion request alla fine.
 */
const { test, expect } = require("@playwright/test");

const TEACHER = "marco.rossi";
const PASS    = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

async function getCsrf(page) {
    return (await (await page.request.get("/auth/csrf")).json()).token;
}

test.describe.configure({ mode: "serial" });

test("Phase 25.C — GET /me/consents lista vuota iniziale", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);

    const r = await page.request.get("/me/consents");
    expect(r.ok()).toBeTruthy();
    const j = await r.json();
    expect(j.ok).toBeTruthy();
    expect(Array.isArray(j.active)).toBeTruthy();
    expect(j.current_version).toBeTruthy();
    expect(j.available_types).toContain("art9_bes_dsa");
    expect(j.available_types).toContain("analytics");
});

test("Phase 25.C — POST /me/consents/grant + revoke roundtrip", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);

    const csrf = await getCsrf(page);

    // Grant analytics
    const grant = await page.request.post("/me/consents/grant", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, type: "analytics" }).toString(),
    });
    expect(grant.ok()).toBeTruthy();
    const gj = await grant.json();
    expect(gj.ok).toBeTruthy();
    expect(gj.consent_id).toBeTruthy();

    // Verify in list
    const list = await (await page.request.get("/me/consents")).json();
    const found = list.active.find(c => c.consent_type === "analytics");
    expect(found, "analytics consent in active list").toBeTruthy();

    // Revoke
    const revoke = await page.request.post("/me/consents/revoke", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, type: "analytics" }).toString(),
    });
    expect(revoke.ok()).toBeTruthy();
    const rj = await revoke.json();
    expect(rj.revoked).toBe(true);

    // Verify NOT in active list
    const list2 = await (await page.request.get("/me/consents")).json();
    const found2 = list2.active.find(c => c.consent_type === "analytics");
    expect(found2, "analytics rimosso da active").toBeFalsy();
});

test("Phase 25.C — POST grant rifiuta type invalido", async ({ page }) => {
    test.setTimeout(30_000);
    await login(page);
    const csrf = await getCsrf(page);

    const r = await page.request.post("/me/consents/grant", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, type: "invalid_type_xyz" }).toString(),
    });
    expect(r.status()).toBe(400);
    const j = await r.json();
    expect(j.error).toBe("invalid_type");
    expect(j.allowed).toContain("art9_bes_dsa");
});

test("Phase 25.C — Deletion lifecycle: request → confirm → cool-off → cancel", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    const csrf = await getCsrf(page);

    // Step 1: request
    const reqResp = await page.request.post("/me/request-deletion", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, reason: "Test cancellazione E2E" }).toString(),
    });
    expect(reqResp.ok()).toBeTruthy();
    const reqJ = await reqResp.json();
    expect(reqJ.ok).toBeTruthy();
    expect(reqJ.cooling_off_days).toBe(30);
    // In dev/test mode, debug_token è restituito per il test
    const token = reqJ.debug_token;
    expect(token, "debug_token presente in dev").toBeTruthy();

    // Step 2: status pending_confirm
    const status1 = await (await page.request.get("/me/deletion-status")).json();
    expect(status1.pending).toBe(true);
    expect(status1.request.status).toBe("pending_confirm");

    // Step 3: confirm via token
    const confirm = await page.request.get(`/me/confirm-deletion?token=${token}`);
    expect(confirm.ok()).toBeTruthy();
    const confJ = await confirm.json();
    expect(confJ.ok).toBeTruthy();
    expect(confJ.status).toBe("cooling_off");

    // Step 4: status cooling_off
    const status2 = await (await page.request.get("/me/deletion-status")).json();
    expect(status2.request.status).toBe("cooling_off");
    expect(status2.request.execute_after).toBeTruthy();

    // Step 5: cancel
    const cancel = await page.request.post("/me/cancel-deletion", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf }).toString(),
    });
    expect(cancel.ok()).toBeTruthy();
    const canJ = await cancel.json();
    expect(canJ.ok).toBe(true);

    // Step 6: status no longer pending
    const status3 = await (await page.request.get("/me/deletion-status")).json();
    expect(status3.pending).toBe(false);
});

test("Phase 25.C — Confirm token invalido = 400", async ({ page }) => {
    test.setTimeout(30_000);
    await login(page);

    const r = await page.request.get("/me/confirm-deletion?token=invalid_xxxxxxx");
    expect(r.status()).toBe(400);
    const j = await r.json();
    expect(j.error).toBe("token_invalid_or_expired");
});

test("Phase 25.C — PATCH /me/profile rettifica (Art. 16)", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);
    const csrf = await getCsrf(page);

    // Get current profile pre-test (per ripristino)
    const userInfo = await (await page.request.get("/auth/user-info")).json();
    const originalName = userInfo.first_name || "Marco";

    try {
        const r = await page.request.post("/me/profile", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, first_name: "TestRettifica" }).toString(),
        });
        expect(r.ok()).toBeTruthy();
        const j = await r.json();
        expect(j.ok).toBeTruthy();
        expect(j.updated_fields).toBeGreaterThanOrEqual(1);
    } finally {
        // Ripristina
        await page.request.post("/me/profile", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({ _csrf: csrf, first_name: originalName }).toString(),
        });
    }
});

test("Phase 25.C — PATCH email invalida = 400", async ({ page }) => {
    test.setTimeout(30_000);
    await login(page);
    const csrf = await getCsrf(page);

    const r = await page.request.post("/me/profile", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf, email: "not-a-valid-email" }).toString(),
    });
    expect(r.status()).toBe(400);
    const j = await r.json();
    expect(j.error).toBe("invalid_email");
});

test("Phase 25.C — GET /me/export-data (Art. 20) ritorna JSON portabilità", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page);

    const r = await page.request.get("/me/export-data");
    expect(r.ok()).toBeTruthy();
    expect(r.headers()["content-disposition"]).toMatch(/attachment.*export/);

    const j = await r.json();
    expect(j.export_format_version).toBeTruthy();
    expect(j.generated_at).toBeTruthy();
    expect(j.data_subject).toBeTruthy();
    expect(j.data_subject.username).toBe(TEACHER);
    // Password hash NON deve essere nell'export
    expect(j.data_subject.password_hash).toBeUndefined();
    expect(Array.isArray(j.consents)).toBeTruthy();
    expect(Array.isArray(j.teacher_content)).toBeTruthy();
    expect(Array.isArray(j.risdoc_overrides)).toBeTruthy();
});
