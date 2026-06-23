/**
 * Helper condivisi per i test della pagina studio/esercizio.
 *
 * Note sul contesto (vedi memoria reference_esercizio_pdf_pipeline):
 *  - Un cookie-banner `.fm-cookie-cat` occlude i click reali → usare
 *    `dispatchEvent('click')` per i pulsanti e `el.focus()` per i campi.
 *  - Gli handler dei controlli quesito/gruppo sono bindati da
 *    `window.FM.bindCheckinHandlers()` (lo richiamiamo per sicurezza).
 *  - Upbar e verifiche correlate si caricano DINAMICAMENTE → attendere.
 *  - L'editor del quesito si apre con dispatchEvent su .fm-single-modifica-btn.
 */
const { expect } = require("@playwright/test");

const TEACHER = "superadmin";
const DEFAULT_IDS = "1291";
const eserUrl = (ids = DEFAULT_IDS) => `/studio/esercizio/SCI/3/MAT/7?ids=${ids}`;

async function loginTeacher(page) {
    await page.addInitScript(() => {
        try {
            localStorage.setItem("cookieConsent", JSON.stringify({
                necessary: true, functional: true, analytics: false, marketing: false, date: "2026-01-01",
            }));
        } catch (_) { /* storage non disponibile pre-navigazione: ignora */ }
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER);
    await page.fill('input[name="password"]', process.env.E2E_TEACHER_PASS || "");
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

/** Cattura errori JS reali (esclude rumore di rete 401/404). */
function trackJsErrors(page) {
    const errors = [];
    page.on("pageerror", (e) => errors.push("[pageerror] " + e.message));
    page.on("console", (m) => {
        if (m.type() !== "error") return;
        const t = m.text();
        if (/Failed to load resource|net::ERR|favicon|gas-client|tikzjax/i.test(t)) return;
        errors.push("[console] " + t);
    });
    return errors;
}

/** Naviga alla pagina esercizio, attende i caricamenti dinamici, chiude il cookie banner. */
async function gotoEser(page, ids = DEFAULT_IDS) {
    await page.goto(eserUrl(ids), { waitUntil: "domcontentloaded" });
    // attende il bootstrap + l'upbar dinamica + le verifiche correlate
    await page.waitForFunction(() => !!window.FM && document.querySelectorAll(".fm-groupcollex").length > 0, { timeout: 20000 }).catch(() => {});
    await page.waitForSelector(".fm-upbar", { state: "attached", timeout: 15000 }).catch(() => {});
    await page.waitForTimeout(2500); // assestamento render dinamico
    await page.evaluate(() => {
        window.FM?.bindCheckinHandlers?.();
        document.querySelectorAll("#fm-cookie-modal,#cookie-banner,.fm-cookie-cat,#fm-modal-overlay,.fm-modal-overlay,[class*='cookie-banner'],[class*='cookie-cat']").forEach((e) => e.remove());
        document.querySelectorAll(".fm-collapsible").forEach((c) => c.classList.add("active"));
    });
    await page.waitForTimeout(400);
}

/** Click "reale" che bypassa l'occlusione (cookie/overlay): dispatch diretto sull'elemento. */
async function dispatchClick(locator) {
    await locator.first().dispatchEvent("click");
}

/** Apre l'editor inline del quesito #index (0-based) e attende il pannello. */
async function openQuesitoEditor(page, index = 0) {
    const item = page.locator(".fm-collection__item").nth(index);
    await item.locator(".fm-single-modifica-btn").first().dispatchEvent("click");
    await page.waitForFunction(() => !!document.querySelector(".fm-editor-panel .fm-editor-field"), { timeout: 20000 });
    await page.waitForTimeout(800);
    return page.locator(".fm-editor-panel").last();
}

/** Chiude l'editor quesito (flush autosave). */
async function closeQuesitoEditor(page) {
    await page.evaluate(() => {
        const panel = document.querySelector(".fm-editor-panel");
        const btn = panel && Array.from(panel.querySelectorAll("button")).find((b) => /Chiudi/i.test(b.textContent || b.title));
        btn?.dispatchEvent(new MouseEvent("click", { bubbles: true }));
    });
    await page.waitForTimeout(600);
}

/** Focus + scrive testo in un campo contenteditable (l'occlusione blocca i click reali). */
async function typeInField(page, fieldLocator, text) {
    await fieldLocator.evaluate((el) => el.focus());
    await page.waitForTimeout(150);
    await page.keyboard.type(text, { delay: 6 });
}

module.exports = {
    TEACHER, DEFAULT_IDS, eserUrl,
    loginTeacher, trackJsErrors, gotoEser, dispatchClick,
    openQuesitoEditor, closeQuesitoEditor, typeInField, expect,
};
