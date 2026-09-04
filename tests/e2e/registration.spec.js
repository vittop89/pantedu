/**
 * Flow registrazione docente:
 *   - submit /register → pending
 *   - admin approve via dashboard
 *   - login con credenziali approvate
 *   - teacher dashboard visibile
 *   - teacher print panel iniettato su verifiche page
 */

const { test, expect } = require("@playwright/test");
const { loginAs, logout, registerTeacher, approvePendingByEmail } = require("./helpers");

// Il regex Registration rifiuta numeri nei nomi; usiamo solo lettere +
// un suffisso codificato in lettere per unicità, ma teniamo numeri solo
// nell'email.
const UNIQ = `t${Date.now()}`;
const TEACHER = {
    firstName: "DocenteEEdueE",
    lastName:  "EsportatoreCheCognome",
    email:     `${UNIQ}@example.it`,
    password:  "prova12345",
};

let approvedUsername;

test.describe.serial("teacher registration + print", () => {
    test("submit registrazione finisce nella pending queue", async ({ page }) => {
        await registerTeacher(page, TEACHER);
        await expect(page).toHaveURL(/\/register\?ok=1/);
    });

    test("admin approva dalla dashboard e utente è salvato", async ({ page }) => {
        approvedUsername = await approvePendingByEmail(page, TEACHER.email);
        expect(approvedUsername).toMatch(/^docente/i);
    });

    test("login teacher funziona dopo l'approvazione", async ({ page }) => {
        await loginAs(page, approvedUsername, TEACHER.password);
        await expect(page).toHaveURL(/\/teacher(\/dashboard)?/);
        await expect(page.locator("h1")).toContainText(/Area Docente/i);
    });

    test("teacher dashboard mostra 4 tile (Mappe/Eser/Lab/Verifiche)", async ({ page }) => {
        await loginAs(page, approvedUsername, TEACHER.password);
        await page.goto("/teacher/dashboard");
        const tiles = await page.locator(".fm-tile h3").allTextContents();
        expect(tiles.join(" ")).toMatch(/Mappe/);
        expect(tiles.join(" ")).toMatch(/Esercizi/);
        expect(tiles.join(" ")).toMatch(/Laboratorio/);
        expect(tiles.join(" ")).toMatch(/Verifiche/);
    });

    test("teacher può caricare sidebar data (links/check-variation + DB topics)", async ({ page }) => {
        // Phase 18 — /files/list-php rimosso (sidebar DB-only)
        await loginAs(page, approvedUsername, TEACHER.password);
        const csrfRes = await page.request.get("/auth/csrf");
        const { token: csrf } = await csrfRes.json();
        const r = await page.request.post("/api/probe", {
            form: { _csrf: csrf, file_links: "/mappe/MAT/MAT_links.json" },
        });
        expect([401, 403]).not.toContain(r.status());
        const topics = await page.request.get("/api/study/topics.json?type=esercizio&subject=MAT");
        expect([401, 403]).not.toContain(topics.status());
    });

    test("teacher può chiamare PrintClient da console e ricevere blob tex", async ({ page }) => {
        await loginAs(page, approvedUsername, TEACHER.password);
        await page.goto("/teacher/dashboard");
        await page.waitForFunction(() => window.FM?.PrintClient);

        // Monkey-patch URL.createObjectURL per catturare il blob
        const filename = await page.evaluate(async () => {
            const selection = {
                version: "A",
                verTitle: "E2E Test Verifica",
                selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
                anno: "2026", sezione: "NOR",
                problems: [{
                    filePath: "/eser/e2e.php", problemId: "p-1", position: 1,
                    text: "Test",
                    items: [{ html: "q1", points: 1.0, includeSolution: false }],
                }],
            };
            const realCreate = URL.createObjectURL.bind(URL);
            let capturedName = null;
            URL.createObjectURL = (blob) => { capturedName = blob.size > 0 ? "ok" : "empty"; return realCreate(blob); };
            const res = await window.FM.PrintClient.printTexForTeacher(selection, "normal");
            return { status: capturedName, filename: res.filename };
        });
        expect(filename.status).toBe("ok");
        expect(filename.filename).toMatch(/\.tex$/);
    });
});
