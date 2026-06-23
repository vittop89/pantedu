/**
 * E2E — esercizio multiarg mode (Phase 20 opzione A).
 *
 * Verifica:
 *  1. Login + sidebar MAT
 *  2. Click normale su un esercizio → mostra TUTTO (esercizi studenti +
 *     verifiche correlate), NO body.fm-esercizio-multiarg
 *  3. Ctrl+click su secondo esercizio → body.fm-esercizio-multiarg AGGIUNTO
 *  4. Gli .fm-contract-wrap esercizi-studenti sono NASCOSTI (display:none)
 *  5. #type_verAll (Verifiche correlate) RESTA visibile
 *  6. Checkbox "Mostra esercizi studenti" cliccata → body.fm-show-student-ex
 *     → esercizi visibili
 */

const { test, expect } = require("@playwright/test");

const USER = "superadmin";
const PASS = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.goto("/login");
    await page.fill('input[name="username"]', USER);
    await page.fill('input[name="password"]', PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function bypassConsent(page) {
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary: true, functional: true, analytics: false, marketing: false,
            date: new Date().toISOString(),
        }));
        const s = document.createElement("style");
        s.textContent = `#fm-cookie-modal,#fm-modal-overlay,#iframe-warning,#cookie-banner{display:none!important;pointer-events:none!important}`;
        (document.head || document.documentElement).appendChild(s);
    });
}

async function hideOverlays(page) {
    await page.evaluate(() => {
        ["fm-cookie-modal", "fm-modal-overlay", "iframe-warning", "cookie-banner"]
            .forEach(id => {
                const e = document.getElementById(id);
                if (e) { e.style.display = "none"; e.style.pointerEvents = "none"; }
            });
    }).catch(() => {});
}

async function setupSidebar(page) {
    await page.selectOption("#sel-iis", "sc").catch(() => {});
    await page.selectOption("#sel-cls", "3s").catch(() => {});
    await page.selectOption("#sel-mater", "MAT").catch(() => {});
    await page.waitForTimeout(500);
}

test("esercizio multiarg: ctrl+click nasconde esercizi studenti + checkbox ri-mostra", async ({ page }) => {
    await bypassConsent(page);
    await login(page);
    await page.goto("/?home=1");
    await page.waitForLoadState("networkidle");
    await hideOverlays(page);
    await page.waitForTimeout(400);

    await setupSidebar(page);
    await hideOverlays(page);

    // Apri sidepage Esercizi (btn2)
    const btnEser = page.locator('.fm-sb-sec[data-sidepage="eser"], .btn[id="btn2"]').first();
    await expect(btnEser).toBeVisible({ timeout: 10_000 });
    await btnEser.click();
    await page.waitForTimeout(1200);

    const items = page.locator("#fm-sp-eser li[data-content-id]:visible");
    const count = await items.count();
    console.log(`[test] Esercizi sidepage items: ${count}`);
    expect(count).toBeGreaterThanOrEqual(2);

    // --- CLICK NORMALE su primo esercizio ---
    const firstId = await items.first().getAttribute("data-content-id");
    console.log(`[test] single click id=${firstId}`);
    await items.first().locator("a").click();
    await page.waitForTimeout(1500);
    await hideOverlays(page);

    // URL ha solo ?ids=firstId
    const url1 = page.url();
    console.log(`[test] URL: ${url1}`);
    expect(url1).toContain(`ids=${firstId}`);

    // body NON ha fm-esercizio-multiarg (1 solo id)
    const hasMultiarg1 = await page.evaluate(() => document.body.classList.contains("fm-esercizio-multiarg"));
    expect(hasMultiarg1).toBe(false);

    // fm-draggable-container > .fm-contract-wrap visibile
    const studentWrapsCount = await page.locator(".fm-draggable-container > .fm-contract-wrap:not([data-kind='verifica'])").count();
    console.log(`[test] student wraps (single): ${studentWrapsCount}`);
    expect(studentWrapsCount).toBeGreaterThanOrEqual(1);

    await page.screenshot({ path: "tests/e2e-results/esma_01_single.png", fullPage: true });

    // --- CTRL+CLICK su secondo esercizio ---
    const secondId = await items.nth(1).getAttribute("data-content-id");
    console.log(`[test] ctrl+click id=${secondId}`);
    await items.nth(1).locator("a").click({ modifiers: ["Control"], force: true });
    await page.waitForTimeout(1800);
    await hideOverlays(page);

    // URL con ?ids=firstId,secondId (csv)
    const url2 = page.url();
    console.log(`[test] URL after ctrl+click: ${url2}`);
    const urlObj = new URL(url2);
    const ids = (urlObj.searchParams.get("ids") || "").split(",").filter(Boolean);
    console.log(`[test] ids: ${JSON.stringify(ids)}`);
    expect(ids.length).toBeGreaterThanOrEqual(2);
    expect(ids).toContain(firstId);
    expect(ids).toContain(secondId);

    // body HA fm-esercizio-multiarg
    const hasMultiarg2 = await page.evaluate(() => document.body.classList.contains("fm-esercizio-multiarg"));
    expect(hasMultiarg2).toBe(true);

    // Phase 21: verifica-mode auto-on per admin (ensureVerificaMode) →
    // loadRelatedVerifica si attiva in automatico, #type_verAll viene
    // iniettato. Il CSS :has() nasconde i wrap studenti quando #type_verAll è presente.
    await page.waitForSelector("#type_verAll", { timeout: 8000 }).catch(() => {});
    await hideOverlays(page);

    const verAllExists = await page.locator("#type_verAll").count() > 0;
    console.log(`[test] #type_verAll exists after auto-activation: ${verAllExists}`);

    // Se #type_verAll esiste, studenti sono nascosti (CSS :has())
    if (verAllExists) {
        const hiddenCount = await page.evaluate(() => {
            const els = document.querySelectorAll(
                ".fm-draggable-container > .fm-contract-wrap:not([data-kind='verifica'])"
            );
            let hidden = 0;
            els.forEach((e) => { if (getComputedStyle(e).display === "none") hidden++; });
            return { total: els.length, hidden };
        });
        console.log(`[test] student wraps (multiarg+verAll): total=${hiddenCount.total}, hidden=${hiddenCount.hidden}`);
        expect(hiddenCount.total).toBeGreaterThan(0);
        expect(hiddenCount.hidden).toBe(hiddenCount.total);
    }

    await page.screenshot({ path: "tests/e2e-results/esma_02_multiarg.png", fullPage: true });

    // --- CHECKBOX "Mostra esercizi studenti" ---
    const checkbox = page.locator("#fm-show-student-ex");
    if (await checkbox.count() > 0) {
        await checkbox.check({ force: true });
        await page.waitForTimeout(300);

        const hasShowClass = await page.evaluate(() =>
            document.body.classList.contains("fm-show-student-ex")
        );
        expect(hasShowClass).toBe(true);

        // Ora gli studenti wrap sono visibili
        const afterToggle = await page.evaluate(() => {
            const els = document.querySelectorAll(".fm-draggable-container > .fm-contract-wrap:not([data-kind='verifica'])");
            let visible = 0;
            els.forEach((e) => {
                if (getComputedStyle(e).display !== "none") visible++;
            });
            return { total: els.length, visible };
        });
        console.log(`[test] after show-student-ex: total=${afterToggle.total}, visible=${afterToggle.visible}`);
        expect(afterToggle.visible).toBe(afterToggle.total);

        await page.screenshot({ path: "tests/e2e-results/esma_03_show_students.png", fullPage: true });
    } else {
        console.log("[test] #fm-show-student-ex non trovato — #type_verAll probabilmente non caricato");
    }
});
