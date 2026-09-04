/**
 * Phase PDF-Import — E2E pagina /teacher/pdf-import.
 *
 * Smoke test robusto rispetto alla config (PDF_IMPORT_ENABLED / provider /
 * rasterizer): verifica che la pagina del tool si carichi per il docente e che
 * il pulsante topbar (data-fm-action="pdf-import") sia presente.
 *
 * Il flusso completo (upload PDF → poll → review → insert) richiede una chiave
 * LLM reale + rasterizer installato + PDF_IMPORT_ENABLED=true: è documentato
 * come test.skip qui sotto (richiede tests/fixtures/sample-exercises.pdf).
 *
 * Credenziali: studio-eser-helpers::loginTeacher (TEACHER + E2E_TEACHER_PASS da
 * .env.e2e.local). Console log catturati per convenzione di progetto.
 */
const { test, expect } = require("@playwright/test");
const { loginTeacher, trackJsErrors, gotoEser } = require("./studio-eser-helpers");

test.describe("PDF Import — /teacher/pdf-import", () => {
    test("la pagina del tool si carica per il docente", async ({ page }) => {
        const logs = [];
        page.on("console", (m) => logs.push(`[${m.type()}] ${m.text()}`));
        const errors = trackJsErrors(page);

        await loginTeacher(page);
        const resp = await page.goto("/teacher/pdf-import", { waitUntil: "domcontentloaded" });
        expect(resp?.status(), "HTTP status pagina").toBeLessThan(400);

        await expect(page.locator(".fm-pdfimport__title")).toContainText(/Importa esercizi/i);

        // O il form di upload (feature ON + provider) O un avviso (feature OFF /
        // nessun provider). In entrambi i casi la pagina è renderizzata.
        const hasForm = await page.locator("[data-fm-extract]").count();
        const hasNotice = await page.locator(".fm-pdfimport__notice").count();
        expect(hasForm + hasNotice, "form upload o notice presente").toBeGreaterThan(0);

        console.log(`[pdf-import-e2e] form=${hasForm} notice=${hasNotice}`);
        console.log("[pdf-import-e2e] console:\n" + logs.join("\n"));

        expect(errors, "nessun errore JS reale\n" + errors.join("\n")).toEqual([]);
    });

    test("il pulsante topbar pdf-import è iniettato nell'editor eser", async ({ page }) => {
        await loginTeacher(page);
        await gotoEser(page);
        // Iniettato via topbar-modern.js relocateVerTitle() dentro la
        // .fm-scelte-verifica-wrapper del topbar attivo.
        await expect(page.locator('#fm-topbar [data-fm-action="pdf-import"]'))
            .toHaveCount(1, { timeout: 15000 });
    });

    // Flusso completo — richiede PDF_IMPORT_ENABLED=true, una chiave provider
    // valida e il rasterizer (Imagick/pdftoppm) installato. Abilitare solo in
    // un ambiente attrezzato con il fixture PDF.
    test.skip("flusso completo upload → estrazione → inserimento bozza", async ({ page }) => {
        await loginTeacher(page);
        await page.goto("/teacher/pdf-import");
        await page.setInputFiles("[data-fm-file]", "tests/fixtures/sample-exercises.pdf");
        await page.click("[data-fm-extract]");
        // attende che il poll popoli la tabella
        await expect(page.locator("[data-fm-tbody] tr")).not.toHaveCount(0, { timeout: 120000 });
        // seleziona tutte le righe e inserisce
        await page.check("[data-fm-selall]");
        await page.click("[data-fm-insert]");
        // l'esercizio bozza è stato creato
        const res = await page.request.get("/api/teacher/content?type=esercizio");
        expect(res.ok()).toBeTruthy();
    });
});
