/**
 * Phase 25.E8 — Trust pages pubbliche per trasparenza GDPR + sicurezza.
 *
 * Coverage:
 *   1. /security: panoramica architettura sicurezza con misure tecniche
 *   2. /privacy/your-data: hub diritti GDPR con link self-service
 *   3. /privacy/informativa: render markdown informativa.md → HTML
 *   4. Cross-page navigation footer presente su tutte
 */
const { test, expect } = require("@playwright/test");

test("Phase 25.E8 — GET /security panoramica misure tecniche", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/security");
    expect(r.ok()).toBeTruthy();
    const html = await r.text();
    expect(html).toContain("Sicurezza tecnica");
    expect(html).toContain("AES-256-GCM");
    expect(html).toContain("Crypto-shredding");
    expect(html).toContain("bcrypt cost 12");
    expect(html).toContain("HSTS");
    expect(html).toContain("Art. 32 GDPR");
    expect(html).toContain("/dpo-contact");
});

test("Phase 25.E8 — GET /privacy/your-data hub diritti", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/privacy/your-data");
    expect(r.ok()).toBeTruthy();
    const html = await r.text();
    expect(html).toContain("I tuoi dati");
    // GDPR Art. 15-22 menzionati (compact non-auth view) o per articolo (auth view)
    expect(html).toMatch(/Art\.\s*(15|17|20|15-22)/);
    // Link al Garante
    expect(html).toContain("garanteprivacy.it");
});

test("Phase 25.E8 — GET /privacy/informativa render markdown", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/privacy/informativa");
    expect(r.ok()).toBeTruthy();
    const html = await r.text();
    expect(html).toContain("<h1>");  // markdown # rendered
    expect(html).toContain("Informativa Privacy");
    expect(html).toContain("Art. 8");  // sezione minori
});

test("Phase 25.E8 — footer navigation cross-page presente", async ({ page }) => {
    test.setTimeout(30_000);
    for (const path of ["/security", "/privacy/your-data"]) {
        const r = await page.request.get(path);
        const html = await r.text();
        // Footer deve linkare alle altre trust pages
        expect(html).toContain('href="/security"');
        expect(html).toContain('href="/privacy/your-data"');
        expect(html).toContain('href="/dpo-contact"');
    }
});

test("Phase 25.E8 — your-data: utente non auth vede invito al login", async ({ page }) => {
    test.setTimeout(30_000);
    const r = await page.request.get("/privacy/your-data");
    const html = await r.text();
    // No session attiva → mostra invito al login
    expect(html).toContain("/login");
});
