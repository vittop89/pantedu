// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * G22.S26 — Full admin review flow (simula click utente):
 *   1. Login Vittorio
 *   2. Goto /admin/templates#risdoc
 *   3. Click tab "Modifiche in revisione"
 *   4. Click "Mostra contenuto" sulla card pending
 *   5. Click "🖼 Anteprima"
 *   6. Verifica checkbox state nell'iframe contro pending content
 */
import { test, expect } from "@playwright/test";
import fs from "fs";
import path from "path";

const VITTORIO_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const VITTORIO_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || "";

const SCREEN_DIR = "tests/e2e-results/g22_s26_full";

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
    await page.evaluate(() => {
        document.getElementById('fm-modal-overlay')?.remove();
        document.getElementById('fm-cookie-modal')?.remove();
    });
}

test.skip("Vittorio: full review flow checkbox visibili (cache-bust)", async ({ page }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
    fs.mkdirSync(SCREEN_DIR, { recursive: true });

    await login(page, VITTORIO_USER, VITTORIO_PASS);

    // Recupera ultimo pending + expected states
    const apiRes = await page.request.get("/api/admin/risdoc/pending?status=pending");
    const apiJson = await apiRes.json();
    expect(apiJson.count_pending).toBeGreaterThan(0);
    const pid = apiJson.pending[0].id;
    const schemaRes = await page.request.get(`/api/admin/risdoc/pending/${pid}/schema`);
    const schema = await schemaRes.json();
    const trip = schema.sections?.[1]?.default?.find(b =>
        b._type === "checkboxGroup" && b.items?.some(it => /poco corretto/i.test(it.label || "")),
    );
    expect(trip).toBeTruthy();
    const expected = Object.fromEntries(trip.items.map(it => [it.label, it.state === "x"]));
    console.log(`→ Pending #${pid} expected:`, JSON.stringify(expected));

    // 1. Navigate admin templates
    await page.goto("/admin/templates#risdoc");
    await page.evaluate(() => {
        document.getElementById('fm-modal-overlay')?.remove();
        document.getElementById('fm-cookie-modal')?.remove();
    });
    await page.waitForSelector("#fm-ar-root");

    // 2. Click tab pending
    await page.locator('.fm-ar-tab[data-tab="pending"]').click();
    await page.waitForSelector(".fm-ar-pending-card");

    // Filter console: only show G22.S26 logs to diagnose section-to-pt
    page.on("console", m => {
        const t = m.text();
        if (/G22\.S26|ANALISI/i.test(t)) console.log(`[console]`, t);
    });
    page.on("pageerror", e => console.log(`[pageerror]`, e.message));

    // 3. Click "Mostra contenuto"
    const card = page.locator(".fm-ar-pending-card").first();
    await card.locator('[data-action="preview"]').click();
    await page.waitForTimeout(2000);
    // Dump card content for debug
    const cardHTML = await card.innerHTML();
    console.log("→ card HTML after preview click (first 500):", cardHTML.slice(0, 500));
    await page.waitForSelector(".fm-ar-diff", { timeout: 10000 });

    // 4. Click tab "🖼 Anteprima"
    await card.locator('button[data-diff-mode="rendered"]').click();
    await expect(card.locator('.fm-ar-diff-body--rendered')).toBeVisible();

    // 5. Attendi iframe load + WC mount
    const iframe = card.locator(".fm-ar-diff-iframe");
    await page.waitForTimeout(1000);
    const srcUrl = await iframe.getAttribute("src");
    console.log(`→ iframe src: ${srcUrl}`);

    await page.waitForFunction(() => {
        const f = document.querySelector(".fm-ar-diff-iframe");
        const doc = f?.contentDocument;
        if (!doc) return false;
        const wc = doc.querySelector("fm-risdoc-template");
        return wc?.shadowRoot?.innerHTML?.includes("fm-risdoc-admin-layout");
    }, { timeout: 15000 });
    await page.waitForTimeout(4000);

    // 6. Estrai stati checkbox dall'iframe
    const actual = await page.evaluate(() => {
        const f = document.querySelector(".fm-ar-diff-iframe");
        const doc = f?.contentDocument;
        const found = {};
        const walk = (root) => {
            if (!root) return;
            const items = root.querySelectorAll?.(".pt-checkbox-item") || [];
            for (const it of items) {
                const cb = it.querySelector("input[type=checkbox]");
                const lbl = (it.querySelector(".pt-checkbox-label-input")?.value || "").trim();
                if (["corretto", "adeguato", "poco corretto non"].includes(lbl)
                    && !(lbl in found)) found[lbl] = cb?.checked === true;
            }
            const cs = root.querySelectorAll?.("*") || [];
            for (const c of cs) if (c.shadowRoot) walk(c.shadowRoot);
        };
        walk(doc);
        return found;
    });
    console.log(`→ Iframe actual:`, JSON.stringify(actual));

    await page.screenshot({
        path: path.join(SCREEN_DIR, "01_full_flow_preview.png"),
        fullPage: false,
    });

    // Verifica
    for (const [label, exp] of Object.entries(expected)) {
        if (actual[label] === undefined) continue;
        expect(actual[label], `${label}: exp=${exp} got=${actual[label]}`).toBe(exp);
    }
    console.log("✅ Full flow checkbox visibili come pending");
});
