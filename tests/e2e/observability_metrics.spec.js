/**
 * Phase 25.E4.2 — /metrics Prometheus endpoint.
 *
 * Coverage:
 *   1. GET /metrics senza auth = 401
 *   2. GET /metrics con Bearer token valido = 200 + Prometheus format
 *   3. GET /metrics con Bearer invalido = 401
 *   4. GET /metrics?bearer=TOKEN (query fallback) = 200
 *   5. Metriche presenti: pantedu_users_total, app_info
 *   6. Format Prometheus exposition (HELP + TYPE annotations)
 *   7. Header Content-Type text/plain version=0.0.4
 *
 * NB: token configurato in .env.local come EXPOSE_DELETION_DEBUG_TOKEN.
 * Per E2E usiamo metric_bearer disponibile via env (vedi .env.local).
 */
const { test, expect } = require("@playwright/test");

const TOKEN = "test_metrics_secret_phase_25_e4_2";

test("Phase 25.E4.2 — GET /metrics senza auth = 401", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/metrics");
    expect(r.status()).toBe(401);
    const j = await r.json();
    expect(j.error).toBe("unauthorized");
});

test("Phase 25.E4.2 — GET /metrics con Bearer = 200 + Prometheus format", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/metrics", {
        headers: { "Authorization": `Bearer ${TOKEN}` },
    });
    expect(r.ok()).toBeTruthy();
    expect(r.headers()["content-type"]).toMatch(/text\/plain.*version=0\.0\.4/);
    const body = await r.text();
    // Prometheus exposition format: HELP + TYPE + metric lines
    expect(body).toMatch(/^# HELP pantedu_app_info/m);
    expect(body).toMatch(/^# TYPE pantedu_app_info gauge/m);
    expect(body).toMatch(/^pantedu_app_info\{version="phase-25"\} 1/m);
});

test("Phase 25.E4.2 — Bearer invalido = 401", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/metrics", {
        headers: { "Authorization": "Bearer wrong_token_xyz" },
    });
    expect(r.status()).toBe(401);
});

test("Phase 25.E4.2 — Query string bearer fallback = 200", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get(`/metrics?bearer=${TOKEN}`);
    expect(r.ok()).toBeTruthy();
    const body = await r.text();
    expect(body).toContain("pantedu_users_total");
});

test("Phase 25.E4.2 — Metriche aspettate presenti", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get(`/metrics?bearer=${TOKEN}`);
    const body = await r.text();
    // Core metrics
    expect(body).toContain("pantedu_app_info");
    expect(body).toContain("pantedu_users_total");
    expect(body).toContain("pantedu_teacher_content_total");
    // GDPR metrics (alcune potrebbero essere vuote ma il TYPE comment c'è)
    expect(body).toContain("pantedu_consents_active_total");
    expect(body).toContain("pantedu_deletion_requests_total");
    expect(body).toContain("pantedu_dpo_requests_total");
    expect(body).toContain("pantedu_parent_consents_total");
    // Crypto metrics
    expect(body).toContain("pantedu_crypto_keys_total");
    // Audit metrics
    expect(body).toContain("pantedu_privileged_access_24h_total");
});

test("Phase 25.E4.2 — Metriche utenti hanno labels role+status", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get(`/metrics?bearer=${TOKEN}`);
    const body = await r.text();
    // Es: pantedu_users_total{role="teacher",status="approved"} N
    expect(body).toMatch(/pantedu_users_total\{role="[^"]+",status="[^"]+"\} \d+/);
});
