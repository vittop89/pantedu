/**
 * Phase 25.E4 — X-Request-ID correlation header.
 *
 * Coverage:
 *   1. Response GET include X-Request-ID auto-generato (UUID v4)
 *   2. Client può forzare trace ID via header → echo-back
 *   3. Header malformato (caratteri non-alphanum) → ignored, generato nuovo
 *   4. Multiple requests = ID diversi (no leak / cache)
 */
const { test, expect } = require("@playwright/test");

test("Phase 25.E4 — X-Request-ID auto-generato come UUID v4", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/security");
    expect(r.ok()).toBeTruthy();
    const rid = r.headers()["x-request-id"];
    expect(rid).toBeTruthy();
    // UUID v4 format: 8-4-4-4-12 hex
    expect(rid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});

test("Phase 25.E4 — Client X-Request-ID echo-back", async ({ page }) => {
    test.setTimeout(30_000);
    const traceId = "client-trace-12345-abc";
    const r = await page.request.get("/security", {
        headers: { "X-Request-ID": traceId },
    });
    expect(r.headers()["x-request-id"]).toBe(traceId);
});

test("Phase 25.E4 — Header malformato (caratteri non-alphanum) = nuovo UUID generato", async ({ page }) => {
    test.setTimeout(30_000);
    const malformed = "evil<script>alert(1)</script>";
    const r = await page.request.get("/security", {
        headers: { "X-Request-ID": malformed },
    });
    const rid = r.headers()["x-request-id"];
    expect(rid).not.toBe(malformed);
    // Should be UUID v4 fallback
    expect(rid).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
});

test("Phase 25.E4 — Multiple requests = ID diversi", async ({ page }) => {
    test.setTimeout(30_000);
    const r1 = await page.request.get("/security");
    const r2 = await page.request.get("/security");
    const r3 = await page.request.get("/privacy/your-data");
    const rids = [r1, r2, r3].map(r => r.headers()["x-request-id"]);
    const unique = [...new Set(rids)];
    expect(unique.length).toBe(3);
});
