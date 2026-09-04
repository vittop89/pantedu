/**
 * G20.7 — Modal Carica Print Info: card layout + edit inline.
 *
 * Verifica:
 *  - Ogni record mostra tutti i campi (anno, tempo, NOR/DSA/DIS, versione,
 *    studente, flag bes, titolo).
 *  - Click ✎ Modifica espande il form inline con input editabili.
 *  - Save persiste su /api/teacher/print-info; il record viene re-fetched
 *    e mostrato col valore aggiornato.
 *  - Cleanup del record creato.
 */
const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Modal Carica Print Info: card + edit inline", async ({ page }) => {
    test.setTimeout(60000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    // Setup: salva un record con tutti i campi
    const stamp = Date.now().toString(36);
    const matCode = "MOD" + stamp.slice(-4);
    await page.request.post("/api/teacher/print-info", {
        data: {
            indirizzo: "ar", classe: "9", materia: matCode,
            sezione: "Z", istituto: "TestEdit", anno: "2099",
            verTime: "55 min", nPrint: "5", nPrintDSA: "2", nPrintDIS: "1",
            versione: "v1", nome: "Mario", cognome: "Rossi",
            compensa: "1", dsa: "0", griglie: "1", misure: "0",
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });

    // Vai a una pagina dove il modal funziona, poi apri programmaticamente
    await page.goto("/studio/esercizio/sc/3/MAT/1");
    await page.waitForLoadState("networkidle");
    await page.waitForTimeout(800);

    await page.evaluate(() => window.FM?.VerificaScelte ? null : null);
    // Apri modal direttamente
    await page.evaluate(async () => {
        // Click programmatic su loadPrintInfoBtn
        const btn = document.getElementById("loadPrintInfoBtn");
        if (btn) btn.click();
    });
    await page.waitForTimeout(1500);

    const modal = page.locator("#fm-load-printinfo-modal");
    await expect(modal).toBeVisible();

    // Trova la nostra card per matCode
    const card = modal.locator(`.fm-pi-card:has(.fm-pi-tag--mat:has-text("${matCode}"))`);
    await expect(card).toHaveCount(1);

    // Verifica che TUTTI i campi siano visibili nella card (compatto view)
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Anno");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Tempo");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("NOR");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("DSA");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("DIS");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Versione");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Compensa");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Griglie");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Studente");
    // Valori specifici (verTitle/Prefix NON sono piu' parte di PrintInfo dopo G20.7)
    await expect(card.locator(".fm-pi-card-grid")).toContainText("55 min");
    await expect(card.locator(".fm-pi-card-grid")).toContainText("Mario Rossi");

    // Click Modifica → form inline visibile
    await card.locator(".fm-edit-row").click();
    const form = card.locator("form.fm-pi-card-edit");
    await expect(form).toBeVisible();

    // Cambia il verTime + togli compensa
    await form.locator("input[name='verTime']").fill("90 min");
    await form.locator("input[name='compensa']").uncheck();

    // Submit
    await form.locator("button[type='submit']").click();
    await page.waitForTimeout(1500);

    // Re-cerca la card (rerendered)
    const cardAfter = page.locator("#fm-load-printinfo-modal")
        .locator(`.fm-pi-card:has(.fm-pi-tag--mat:has-text("${matCode}"))`);
    await expect(cardAfter).toContainText("90 min");
    // Compensa ora dovrebbe essere "—"
    const compensaCell = cardAfter.locator(".fm-pi-card-grid > div:has(dt:has-text('Compensa')) dd");
    await expect(compensaCell).toHaveText("—");

    // Cleanup: cancella il record via API
    await page.request.post("/api/teacher/print-info/delete", {
        data: { indirizzo: "ar", classe: "9", materia: matCode, sezione: "Z", istituto: "TestEdit" },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
});
