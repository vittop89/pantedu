// @ts-check
/**
 * Sfondi — problemi B (fascia scura su #fm-content in tema scuro) e M
 * (sidebar quasi indistinguibile dal contenuto). Analisi in
 * docs/analysis (giro 2, id B-fm-content-scuro e M-palette-sfondi).
 */
const { test, expect } = require("../support/test");

/**
 * Attiva il tema scuro PRIMA che la pagina carichi: lo script anti-FOUC
 * (views/partials/head.php) legge `fm_dark_mode` da localStorage al primo
 * script del <head>, sincrono, prima del primo paint. Impostarlo con
 * `addInitScript` (eseguito prima di ogni script della pagina) è l'unico
 * modo per vedere il tema scuro già al caricamento, senza passare dal
 * comando dell'interfaccia.
 * @param {import("@playwright/test").Page} pagina
 */
async function attivaTemaScuro(pagina) {
    await pagina.addInitScript(() => {
        localStorage.setItem("fm_dark_mode", "1");
    });
}

/**
 * Legge `background-color` calcolato di un selettore.
 * @param {import("@playwright/test").Page} pagina
 * @param {string} selettore
 */
async function sfondoCalcolato(pagina, selettore) {
    return pagina.evaluate((sel) => {
        const el = document.querySelector(sel);
        if (!el) return null;
        return getComputedStyle(el).backgroundColor;
    }, selettore);
}

test.describe("Qualità — #fm-content non ha più il fondo scuro nel layout shell (problema B)", () => {
    // Le cinque pagine di views/layout/shell.php citate nell'analisi:
    // niente sidebar, niente #bottom-bar, solo una card centrata.
    const PAGINE_SHELL = [
        { percorso: "/login", nome: "accesso" },
        { percorso: "/accesso-classe", nome: "accesso della classe" },
        { percorso: "/register", nome: "iscrizione" },
        { percorso: "/password/forgot", nome: "password dimenticata" },
        { percorso: "/me/account/email/conferma", nome: "conferma email" },
    ];

    for (const { percorso, nome } of PAGINE_SHELL) {
        test(`${nome} (${percorso}): #fm-content è trasparente in tema scuro`, async ({ page }) => {
            await attivaTemaScuro(page);
            const risposta = await page.goto(percorso, { waitUntil: "domcontentloaded" });
            expect(risposta, `${percorso} non ha risposto`).toBeTruthy();
            expect(risposta?.status(), `${percorso} ha risposto ${risposta?.status()}`).toBeLessThan(400);

            await expect(page.locator("body"), "il tema scuro è attivo").toHaveClass(/fm-dark/);

            const sfondo = await sfondoCalcolato(page, "#fm-content");
            expect(sfondo, "#fm-content è in pagina").not.toBeNull();
            // Trasparente: getComputedStyle lo riporta come rgba(0, 0, 0, 0).
            // Prima della correzione era rgb(15, 18, 24) — --fm-c-bg scuro —
            // su tutte e cinque queste pagine (misurato in DevTools).
            expect(sfondo, "niente più fascia scura dietro/attorno alla card").toBe("rgba(0, 0, 0, 0)");
        });
    }
});

/** Luminanza relativa WCAG (0..1) da una stringa "rgb(r, g, b)"/"rgba(r, g, b, a)". */
function luminanzaDaRgb(/** @type {string} */ rgb) {
    const m = /rgba?\(\s*(\d+),\s*(\d+),\s*(\d+)/.exec(rgb);
    if (!m) throw new Error(`colore non riconosciuto: ${rgb}`);
    const lin = (/** @type {number} */ c) => {
        const cs = c / 255;
        return cs <= 0.04045 ? cs / 12.92 : ((cs + 0.055) / 1.055) ** 2.4;
    };
    const r = lin(Number(m[1]));
    const g = lin(Number(m[2]));
    const b = lin(Number(m[3]));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrasto(/** @type {number} */ l1, /** @type {number} */ l2) {
    const [chiaro, scuro] = l1 >= l2 ? [l1, l2] : [l2, l1];
    return (chiaro + 0.05) / (scuro + 0.05);
}

/** Soglia scelta per il problema M: vedi css/tokens.css, commento su --fm-c-sidebar-bg. */
const SOGLIA_SIDEBAR_CONTENUTO = 1.5;

test.describe("Qualità — la sidebar si distingue dal contenuto (problema M)", () => {
    for (const [nomeTema, scuro] of /** @type {[string, boolean][]} */ ([["chiaro", false], ["scuro", true]])) {
        test(`tema ${nomeTema}: sidebar vs contenuto >= ${SOGLIA_SIDEBAR_CONTENUTO}:1`, async ({ page }) => {
            if (scuro) await attivaTemaScuro(page);
            // La home pubblica (senza login) ha la sidebar pubblica.
            await page.goto("/?home=1", { waitUntil: "domcontentloaded" });
            await expect(page.locator(".sidebar, .fm-sidebar").first(), "la sidebar è in pagina").toBeVisible();
            if (scuro) await expect(page.locator("body")).toHaveClass(/fm-dark/);

            const sfondoSidebar = await sfondoCalcolato(page, ".sidebar, .fm-sidebar");
            // Il colore "del contenuto" che l'utente vede è quello di <body>,
            // non quello di #fm-content: #fm-content non ha uno sfondo proprio
            // in tema chiaro (solo body.fm-dark #fm-content lo imposta, vedi
            // css/modules/_dark-mode.css) — leggerlo direttamente darebbe
            // "rgba(0, 0, 0, 0)" in chiaro, letto come nero puro dalla
            // luminanza, un falso negativo verificato con una pagina statica
            // prima di scrivere questa prova.
            const sfondoContenuto = await sfondoCalcolato(page, "body");
            expect(sfondoSidebar, "sfondo sidebar leggibile").not.toBeNull();
            expect(sfondoContenuto, "sfondo contenuto (body) leggibile").not.toBeNull();

            const rapporto = contrasto(
                luminanzaDaRgb(/** @type {string} */ (sfondoSidebar)),
                luminanzaDaRgb(/** @type {string} */ (sfondoContenuto)),
            );
            expect(
                rapporto,
                `sidebar ${sfondoSidebar} vs contenuto ${sfondoContenuto}: rapporto ${rapporto.toFixed(2)}:1`,
            ).toBeGreaterThanOrEqual(SOGLIA_SIDEBAR_CONTENUTO);
        });
    }
});
