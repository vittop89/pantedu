/**
 * RequiresAuditReason middleware — modalità `enforce`.
 *
 * Verifica:
 *   1. Mutazione admin SENZA motivazione → 400 audit_reason_required.
 *   2. `X-Audit-Reason` valida → passa.
 *   3. `_audit_reason` nel body funziona come alternativa all'header.
 *   4. Motivazione più corta di dieci caratteri → 400, come se mancasse.
 *
 * PERCHÉ QUESTE ASSERZIONI SONO CAMBIATE (2026-09-02)
 *
 * Fino a oggi il middleware girava in `warn`: i test 1 e 4 si aspettavano un
 * 200, perché la mutazione passava comunque e il registro annotava la stringa
 * MISSING_OR_INVALID_AUDIT_REASON al posto del motivo. In produzione questo
 * significava 178 righe su 216 senza motivazione — la forma del registro
 * conservata, il contenuto no. Chiuso il soft-launch: tutti i client del
 * pannello mandano l'header (js/modules/core/audit-reason.js) e
 * AUDIT_REASON_MODE è `enforce`.
 *
 * Se questi test tornassero a fallire con 200 al posto di 400, la causa più
 * probabile è un `AUDIT_REASON_MODE=warn` rimasto in `.env.local`
 * sull'ambiente di prova.
 */
const { test, expect } = require("@playwright/test");

const SUPER = "superadmin";
const PASS  = (process.env.E2E_TEACHER_PASS || "");

async function login(page, username) {
    await page.goto("/login");
    await page.fill('input[name="username"]', username);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

async function getCsrf(page) {
    return (await (await page.request.get("/auth/csrf")).json()).token;
}

async function pickInstitutionalTemplateId(page) {
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const inst = (list.templates || []).find(t => !t.owner_id);
    return inst?.id || null;
}

test("enforce — mutazione admin senza motivazione viene respinta", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, SUPER);

    const tplId = await pickInstitutionalTemplateId(page);
    const csrf = await getCsrf(page);

    const r = await page.request.post(`/api/admin/risdoc/templates/${tplId}/visibility-scope`, {
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            // Annulla il default di playwright.config.js: qui serve proprio
            // il caso in cui la motivazione non arriva.
            "X-Audit-Reason": "",
        },
        data: new URLSearchParams({ _csrf: csrf, scope: "public" }).toString(),
    });
    expect(r.status(), "senza motivazione → 400").toBe(400);
    const j = await r.json();
    expect(j.error).toBe("audit_reason_required");
});

test("enforce — X-Audit-Reason valida lascia passare la mutazione", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, SUPER);

    const tplId = await pickInstitutionalTemplateId(page);
    const csrf = await getCsrf(page);
    const reason = "Cambio scope per test E2E del registro motivazioni";

    const r = await page.request.post(`/api/admin/risdoc/templates/${tplId}/visibility-scope`, {
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Audit-Reason": reason,
        },
        data: new URLSearchParams({ _csrf: csrf, scope: "public" }).toString(),
    });
    expect(r.ok(), "POST con motivazione valida → 200").toBeTruthy();
});

test("enforce — _audit_reason nel body vale quanto l'header", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, SUPER);

    const tplId = await pickInstitutionalTemplateId(page);
    const csrf = await getCsrf(page);
    const reason = "Prova del campo nel body per la motivazione";

    const r = await page.request.post(`/api/admin/risdoc/templates/${tplId}/visibility-scope`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf,
            scope: "public",
            _audit_reason: reason,
        }).toString(),
    });
    expect(r.ok(), "POST con _audit_reason nel body → 200").toBeTruthy();
});

test("enforce — motivazione più corta di dieci caratteri vale come mancante", async ({ page }) => {
    test.setTimeout(60_000);
    await login(page, SUPER);

    const tplId = await pickInstitutionalTemplateId(page);
    const csrf = await getCsrf(page);

    const r = await page.request.post(`/api/admin/risdoc/templates/${tplId}/visibility-scope`, {
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Audit-Reason": "ok",  // < 10 caratteri
        },
        data: new URLSearchParams({ _csrf: csrf, scope: "public" }).toString(),
    });
    expect(r.status(), "motivazione troppo corta → 400").toBe(400);
});
