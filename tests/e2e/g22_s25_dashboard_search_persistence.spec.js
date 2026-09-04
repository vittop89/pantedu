/**
 * G22.S25 — Verifica che i filtri "Ricerca contenuti" nella dashboard
 * Panoramica persistano per sessione (sessionStorage).
 */
import { test, expect } from "@playwright/test";

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
}

test("Filtri Ricerca persistono dopo reload", async ({ page }) => {
    if (!VITTORIO_PASS) test.skip(true, "PLAYWRIGHT_TEST_PASSWORD non set");

    await login(page, VITTORIO_USER, VITTORIO_PASS);
    await page.goto("/teacher/dashboard#panoramica");
    // Rimuovi cookie modal overlay che intercetta i click.
    await page.evaluate(() => document.getElementById('fm-modal-overlay')?.remove());
    await page.waitForSelector("#fm-ex-form");

    // Aspetta che i dropdown curriculum siano popolati.
    await page.waitForFunction(() => {
        const sel = document.querySelector('select[name="subject"]');
        return sel && sel.options.length > 1;
    }, { timeout: 5000 });

    // Imposta filtri
    await page.selectOption('#fm-ex-form select[name="type"]', 'esercizio');
    await page.selectOption('#fm-ex-form select[name="subject"]', 'MAT');
    await page.fill('#fm-ex-form input[name="q"]', 'sistemi');
    await page.check('#fm-ex-form input[name="archived"]', { force: true });

    // Verifica che sessionStorage abbia salvato
    const saved = await page.evaluate(() =>
        sessionStorage.getItem('fm-dash-ex-search-filters')
    );
    console.log("→ saved state:", saved);
    expect(saved).toBeTruthy();
    const state = JSON.parse(saved);
    expect(state.type).toBe('esercizio');
    expect(state.subject).toBe('MAT');
    expect(state.q).toBe('sistemi');
    expect(state.archived).toBe('1');

    // Reload
    await page.reload();
    await page.waitForSelector("#fm-ex-form");
    await page.waitForFunction(() => {
        const sel = document.querySelector('select[name="subject"]');
        return sel && sel.options.length > 1;
    }, { timeout: 5000 });

    // Aspetta un po' per assicurarsi che applyState sia eseguito
    await page.waitForTimeout(500);

    // Verifica restore
    const restored = await page.evaluate(() => ({
        type: document.querySelector('#fm-ex-form select[name="type"]').value,
        subject: document.querySelector('#fm-ex-form select[name="subject"]').value,
        q: document.querySelector('#fm-ex-form input[name="q"]').value,
        archived: document.querySelector('#fm-ex-form input[name="archived"]').checked,
        storage: sessionStorage.getItem('fm-dash-ex-search-filters'),
    }));
    console.log("→ after reload:", JSON.stringify(restored, null, 2));

    expect(restored.type).toBe('esercizio');
    expect(restored.subject).toBe('MAT');
    expect(restored.q).toBe('sistemi');
    expect(restored.archived).toBe(true);
});
