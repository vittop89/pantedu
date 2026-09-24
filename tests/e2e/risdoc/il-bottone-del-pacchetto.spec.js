// @ts-check
/**
 * Il bottone del pacchetto manda quello che c'è nel documento.
 *
 * Il 21/9/2026 i bottoni «ZIP» e «VSCode» della barra chiamavano l'export
 * mandando solo il gettone e `mode=zip`: il server, non trovando `form_state`,
 * costruiva il pacchetto dal modello vuoto, e il docente si scaricava un
 * archivio senza una riga del suo lavoro.
 *
 * Nessuna prova l'aveva visto, e la ragione vale più del difetto: le spec del
 * pacchetto chiamano l'API, e il loro aiutante `form_state` lo aggiunge
 * sempre. Misuravano il server — che funziona — e mai il codice del bottone.
 * Questa spec preme il bottone e guarda che cosa esce dal browser.
 */
const { test, expect } = require("../support/test");

/** Il primo modello dell'Istituto che ha un sorgente TeX. */
async function modelloConTex(/** @type {any} */ teacherApi, /** @type {any} */ adminApi) {
    for (const modello of await teacherApi.risdoc.templates()) {
        const scheda = await adminApi.risdoc.detail(modello.id);
        if (scheda.tex_file) return modello.id;
    }
    throw new Error("nessun modello con sorgente TeX");
}

test.describe("Risdoc — il pacchetto TeX", () => {
    test("il bottone manda il documento, non il modello vuoto", async ({ teacherApi, adminApi, teacherPage }) => {
        const id = await modelloConTex(teacherApi, adminApi);
        await teacherPage.goto(`/risdoc/view/${id}`);

        const barra = teacherPage.locator(".fm-doc-topbar").first();
        await expect(barra).toBeVisible({ timeout: 30_000 });
        const pacchetto = barra.locator('[data-action="ptdoc-zip"]');
        await expect(pacchetto).toBeVisible();

        const inAscolto = teacherPage.waitForRequest(
            (r) => r.url().includes(`/api/risdoc/templates/${id}/export`) && r.method() === "POST",
            { timeout: 30_000 },
        );
        await pacchetto.click();
        const richiesta = await inAscolto;

        const corpo = new URLSearchParams(richiesta.postData() || "");
        const statoGrezzo = corpo.get("form_state");
        expect(statoGrezzo, "senza questo il pacchetto esce col modello vuoto").toBeTruthy();

        const stato = JSON.parse(String(statoGrezzo));
        expect(Array.isArray(stato.body_pt), "il documento viaggia col pacchetto").toBe(true);
        expect(stato.body_pt.length, "e non è vuoto").toBeGreaterThan(0);
        expect(stato.state, "insieme al contesto e alla spunta dell'intestazione").toBeTruthy();
        expect(Object.prototype.hasOwnProperty.call(stato.state, "includeHeader")).toBe(true);
    });

    test("l'indirizzo da cui si scaricava non esiste più", async ({ teacherApi }) => {
        // 21/9/2026 — il pacchetto non viene più scritto su disco, quindi non
        // c'è niente da servire: la rotta che lo consegnava a *un* docente
        // qualunque, e non al proprietario, è stata tolta. Questa è la guardia
        // perché non torni insieme a un file dimenticato.
        const risposta = await teacherApi.http.send("GET", "/api/risdoc/exports/doc-0123456789abcdef.zip");

        expect(risposta.status, "la rotta non c'è più").toBe(404);
    });
});
