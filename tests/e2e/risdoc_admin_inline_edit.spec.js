// =============================================================================
// ADR-026 #3 (2026-05-28) — TUTTI I test(...) IN QUESTO FILE SONO test.skip().
// Motivo: usano querySelector("fm-risdoc-template") + shadowRoot del motore
// legacy ELIMINATO. Da migrare a fm-pt-document (light DOM) o eliminare.
// =============================================================================
/**
 * Phase 24.59 — admin schema edit inline su sezioni.
 *
 * Verifica che con admin-edit=1 ogni sezione (escluso header) abbia
 * overlay con bottoni edit/move/duplicate/delete che mutano _schema
 * in memoria.
 */
const { test, expect } = require("@playwright/test");

const ADMIN_USER = "superadmin";
const ADMIN_PASS = (process.env.E2E_TEACHER_PASS || "");

async function loginAdmin(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test.skip("admin-edit mostra overlay edit per ogni body section", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);

    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;
    expect(tplId).toBeTruthy();

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    const r = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        const sr = wc?.shadowRoot;
        const wraps = [...(sr?.querySelectorAll(".fm-admin-section-wrap") || [])];
        const overlays = [...(sr?.querySelectorAll(".fm-admin-section-overlay") || [])];
        const btns = overlays.flatMap(o => [...o.querySelectorAll(".fm-risdoc-btn")]);
        return {
            wrapCount: wraps.length,
            overlayCount: overlays.length,
            btnCountPerOverlay: overlays.length > 0 ? overlays[0].querySelectorAll(".fm-risdoc-btn").length : 0,
            firstWrapHasInner: !!wraps[0]?.querySelector("div"),
        };
    });
    expect(r.wrapCount, "almeno 1 sezione body").toBeGreaterThanOrEqual(1);
    expect(r.overlayCount).toBe(r.wrapCount);
    expect(r.btnCountPerOverlay, "5 bottoni per overlay (✎/↑/↓/⎘/🗑)").toBe(5);
    expect(r.firstWrapHasInner, "wrap contiene il rendering originale").toBeTruthy();
});

test.skip("admin-edit delete section muta _schema in memoria", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    // Skip confirm dialog
    page.on("dialog", async (d) => { await d.accept(); });

    const before = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc._schema?.sections?.length || 0;
    });

    // Click delete sulla 2a sezione body (idx=1, evita header)
    await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template").shadowRoot;
        const overlays = [...sr.querySelectorAll(".fm-admin-section-overlay")];
        const target = overlays[1] || overlays[0];
        const delBtn = [...target.querySelectorAll(".fm-risdoc-btn")].pop();
        delBtn?.click();
    });
    await page.waitForTimeout(300);

    const after = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc._schema?.sections?.length || 0;
    });
    expect(after, "schema in memoria diminuito di 1").toBe(before - 1);
});

test.skip("Phase 24.60 — form-builder + Aggiungi sezione mostra bottoni 5 type", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    const r = await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template").shadowRoot;
        const adder = sr.querySelector(".fm-admin-add-section");
        const btns = [...(adder?.querySelectorAll(".fm-risdoc-btn") || [])];
        return {
            hasAdder: !!adder,
            btnCount: btns.length,
            btnLabels: btns.map(b => b.textContent.trim()),
        };
    });
    expect(r.hasAdder, "block + Aggiungi sezione").toBeTruthy();
    expect(r.btnCount, "5 bottoni type").toBe(5);
});

test.skip("Phase 24.60 — adminAddSection text-section appende skeleton al schema", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    page.on("dialog", async (d) => { await d.accept("Sezione di test"); });

    const before = await page.evaluate(() => {
        return document.querySelector("fm-risdoc-template")._schema?.sections?.length || 0;
    });

    await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template").shadowRoot;
        const btn = sr.querySelector(".fm-admin-add-section .fm-risdoc-btn");
        btn?.click();
    });
    await page.waitForTimeout(300);

    const after = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        const last = wc._schema.sections[wc._schema.sections.length - 1];
        return {
            count: wc._schema.sections.length,
            lastType: last?.type,
            lastTitle: last?.title,
        };
    });
    expect(after.count).toBe(before + 1);
    expect(after.lastType).toBe("text-section");
    expect(after.lastTitle).toBe("Sezione di test");
});

test.skip("admin-edit duplicate section muta _schema in memoria", async ({ page }) => {
    test.setTimeout(60_000);
    await loginAdmin(page);
    const list = await (await page.request.get("/api/risdoc/templates?origin=risdoc")).json();
    const tplId = (list.templates || [])[0]?.id;

    await page.goto(`/risdoc/view/${tplId}?admin_edit=1`);
    await page.waitForLoadState("domcontentloaded");
    await page.waitForTimeout(2000);

    const before = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc._schema?.sections?.length || 0;
    });

    // Duplicate prima sezione body
    await page.evaluate(() => {
        const sr = document.querySelector("fm-risdoc-template").shadowRoot;
        const overlay = sr.querySelector(".fm-admin-section-overlay");
        const btns = [...overlay.querySelectorAll(".fm-risdoc-btn")];
        // ordine: ✎, ↑, ↓, ⎘, 🗑 → idx=3 è duplicate
        btns[3]?.click();
    });
    await page.waitForTimeout(300);

    const after = await page.evaluate(() => {
        const wc = document.querySelector("fm-risdoc-template");
        return wc._schema?.sections?.length || 0;
    });
    expect(after, "schema in memoria aumentato di 1").toBe(before + 1);
});
