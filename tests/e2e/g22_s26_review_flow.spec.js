// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * G22.S26 — End-to-end review flow per modifiche di collaboratori risdoc.
 *
 * Verifica:
 *   1. Vittorio (super-admin) accede a /admin/templates#risdoc
 *   2. Vede tab "🛡 Modifiche in revisione" con badge count
 *   3. Apre il diff card pending → 4 modalità rendering (Unificato,
 *      Affiancato, Pretty-print, Anteprima iframe)
 *   4. Screenshot di ogni modalità + verifica DOM strutturale
 *
 * Stato DB atteso: pending #1 di Marco (teacher_id=140) su template 16
 * (Piano_annuale_(docente)), status='pending'. Setup pre-test richiesto.
 */
import { test, expect } from "@playwright/test";
import fs from "fs";
import path from "path";

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
    // Rimuovi eventuale cookie modal overlay
    await page.evaluate(() => document.getElementById('fm-modal-overlay')?.remove());
}

const SCREEN_DIR = "tests/e2e-results/g22_s26_review";

function ensureDir() {
    fs.mkdirSync(SCREEN_DIR, { recursive: true });
}

test.skip("Admin review flow: tab pending + 4 diff modes con screenshot", async ({ page }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");
    ensureDir();

    // 1. Pre-check: API ritorna almeno 1 pending
    await login(page, VITTORIO_USER, VITTORIO_PASS);
    const apiRes = await page.request.get("/api/admin/risdoc/pending?status=pending");
    expect(apiRes.ok(), "API pending list deve rispondere ok").toBeTruthy();
    const apiJson = await apiRes.json();
    expect(apiJson.count_pending, "almeno 1 pending nel DB").toBeGreaterThan(0);
    console.log(`→ pending count: ${apiJson.count_pending}`);
    const firstPending = (apiJson.pending || [])[0];
    expect(firstPending).toBeTruthy();
    console.log(`→ first pending: #${firstPending.id} kind=${firstPending.kind} by=${firstPending.submitter_username}`);

    // 2. Naviga admin templates page
    await page.goto("/admin/templates#risdoc");
    // Rimuovi qualsiasi modal cookie/overlay che intercetta i click.
    await page.evaluate(() => {
        document.getElementById('fm-modal-overlay')?.remove();
        document.getElementById('fm-cookie-modal')?.remove();
    });
    await page.waitForSelector("#fm-ar-root", { timeout: 8000 });

    // 3. Click tab "🛡 Modifiche in revisione"
    const pendingTab = page.locator('.fm-ar-tab[data-tab="pending"]');
    await expect(pendingTab, "tab pending deve esistere").toBeVisible();
    await pendingTab.click();

    // Verifica badge count
    const badge = page.locator("#fm-ar-pending-badge");
    await expect(badge).toBeVisible();
    const badgeText = await badge.textContent();
    console.log(`→ badge text: "${badgeText}"`);
    expect(parseInt(badgeText || "0", 10)).toBeGreaterThan(0);

    // 4. Attendi card pending renderizzata
    await page.waitForSelector(".fm-ar-pending-card", { timeout: 5000 });
    const cards = await page.locator(".fm-ar-pending-card").count();
    console.log(`→ pending cards visibili: ${cards}`);
    expect(cards).toBeGreaterThan(0);

    await page.screenshot({
        path: path.join(SCREEN_DIR, "01_tab_pending_list.png"),
        fullPage: true,
    });

    // 5. Click "Mostra contenuto" sulla prima card
    const firstCard = page.locator(".fm-ar-pending-card").first();
    await firstCard.locator('[data-action="preview"]').click();
    await page.waitForSelector(".fm-ar-diff", { timeout: 5000 });

    // Verifica stats +/-
    const stats = await firstCard.locator(".fm-ar-diff-stat").allTextContents();
    console.log(`→ diff stats: ${stats.join(" ")}`);

    await page.screenshot({
        path: path.join(SCREEN_DIR, "02_diff_unified.png"),
        fullPage: false,
    });

    // 6. Tab "Affiancato"
    await firstCard.locator('button[data-diff-mode="side"]').click();
    await expect(firstCard.locator('.fm-ar-diff-body--side')).toBeVisible();
    await expect(firstCard.locator('.fm-ar-diff-body--unified')).toBeHidden();
    await page.screenshot({
        path: path.join(SCREEN_DIR, "03_diff_side_by_side.png"),
        fullPage: false,
    });

    // 7. Tab "Pretty-print"
    await firstCard.locator('button[data-diff-mode="full"]').click();
    await expect(firstCard.locator('.fm-ar-diff-body--full')).toBeVisible();
    await page.screenshot({
        path: path.join(SCREEN_DIR, "04_diff_pretty.png"),
        fullPage: false,
    });

    // 8. Tab "🖼 Anteprima" (iframe lazy-load)
    const previewBtn = firstCard.locator('button[data-diff-mode="rendered"]');
    if (await previewBtn.count() > 0) {
        await previewBtn.click();
        await expect(firstCard.locator('.fm-ar-diff-body--rendered')).toBeVisible();

        // Aspetta che l'iframe carichi (src assegnato lazy → poi network)
        const iframe = firstCard.locator(".fm-ar-diff-iframe");
        await expect(iframe).toBeVisible();
        await page.waitForTimeout(2000); // attendi WC mount

        // Verifica che iframe abbia src set
        const iframeSrc = await iframe.getAttribute("src");
        console.log(`→ iframe src: ${iframeSrc}`);
        expect(iframeSrc, "iframe src deve essere assegnato dopo click").toBeTruthy();
        expect(iframeSrc).toContain("/admin/risdoc/pending/");

        // Frame content: verifica che NON sia un error page (preview banner presente)
        const frame = page.frameLocator(".fm-ar-diff-iframe");
        try {
            await expect(frame.locator(".preview-banner")).toBeVisible({ timeout: 5000 });
            console.log("→ iframe banner visibile");
        } catch {
            console.warn("→ iframe banner NON visibile (frame potrebbe non aver finito mount)");
        }

        // Attendi che il Web Component renderizzi il form (mount Lit + schema
        // fetch + sub-components custom elements register).
        await page.waitForTimeout(3000);
        const wcMount = await page.evaluate(() => {
            const iframe = document.querySelector(".fm-ar-diff-iframe");
            if (!iframe?.contentDocument) return { ok: false, reason: "no_iframe_doc" };
            const wc = iframe.contentDocument.querySelector("fm-risdoc-template");
            if (!wc?.shadowRoot) return { ok: false, reason: "no_shadow" };
            const sh = wc.shadowRoot.innerHTML;
            return {
                ok: sh.includes("fm-risdoc-admin-layout") && !sh.includes("Failed to fetch"),
                hasError: sh.includes('class="error"'),
                preview: sh.slice(0, 200),
            };
        });
        console.log("→ WC mount:", JSON.stringify(wcMount));
        expect(wcMount.ok, "iframe deve renderizzare il template senza errori").toBeTruthy();

        await page.screenshot({
            path: path.join(SCREEN_DIR, "05_diff_rendered_preview.png"),
            fullPage: false,
        });

        // Bonus: screenshot dell'iframe content (boundbox del iframe element)
        const iframeBoundingBox = await iframe.boundingBox();
        if (iframeBoundingBox) {
            await page.screenshot({
                path: path.join(SCREEN_DIR, "05b_diff_rendered_preview_clip.png"),
                clip: iframeBoundingBox,
            });
        }
    } else {
        console.log("→ Bottone Anteprima non presente (kind probabilmente non renderizzabile)");
    }

    console.log(`✅ Screenshot salvati in ${SCREEN_DIR}/`);
});
