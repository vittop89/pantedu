/**
 * G27.griglia.baseline — Verifica normalizzazione fontsize baseline:
 * `\fontsize{N}{2}` legacy → `\fontsize{N}{N+3}` per centratura verticale
 * nelle cell m{...} di tabularx (no overlap testo↔bordo top).
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const TEACHER_USER = "superadmin";
const TEACHER_PASS = (process.env.E2E_TEACHER_PASS || "");

async function login(page) {
    await page.addInitScript(() => {
        localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
            functional: true, analytics: false, advertising: false, timestamp: Date.now(),
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/, { timeout: 10000 }),
        page.click('button[type="submit"]'),
    ]);
}
async function fetchCsrf(page) {
    return (await (await page.request.get("/auth/csrf")).json()).token;
}

test("Griglia NOR: baseline normalizzato + screenshot ultima pagina", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `GRIGLIA_BL_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "g-bl",
            position: 1, type: "Collect",
            text: "Esercizio:",
            items: [{ html: "Test.", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT", title: "GRIGLIA BL",
        dsa: false, compensa: false,
        includeGriglia: true, includeMisure: true,
        nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
        tipologia: "scritto",
    };
    const saveR = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(saveR.status()).toBe(200);
    const docs = (await saveR.json()).docs || [];
    const aNor = docs.find(d => d.variant === "A_NOR");
    expect(aNor).toBeTruthy();

    // Verifica TEX griglia: NESSUN \fontsize{N}{2} residuo
    const filesR = await page.request.get(`/api/verifica/${aNor.id}/tex-files`);
    const files = (await filesR.json()).files;
    const griglia = files.find(f => f.path.startsWith("griglie/") && f.path.endsWith(".tex") && !f.path.includes("compact"));
    expect(griglia).toBeTruthy();
    const oldBaseline = (griglia.content.match(/\\fontsize\{[0-9.]+\}\{2\}/g) || []).length;
    expect(oldBaseline, "no residual \\fontsize{N}{2}").toBe(0);
    // Nuovo baseline: \fontsize{N}{N+1} (es. 9/10, 8.5/10, 10/11)
    const newBaseline = (griglia.content.match(/\\fontsize\{[0-9.]+\}\{(9|10|11|12)\}/g) || []).length;
    expect(newBaseline).toBeGreaterThan(10);
    console.log(`[BASELINE] new \\fontsize occurrences: ${newBaseline}`);

    // Compile + screenshot last page (griglia)
    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aNor.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 90_000,
        });
        console.log(`[compile] attempt ${attempt + 1}: status=${compR.status()}`);
        if (compR.status() === 200 && (await compR.json()).ok) { compiled = true; break; }
    }
    expect(compiled).toBe(true);

    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });
    const pdfR = await page.request.get(`/api/verifica/${aNor.id}/pdf`);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, "GRIGLIA_BL.pdf");
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length}b`);
    execSync(`pdftoppm -png -r 150 "${pdfPath}" "${path.join(screenshotsDir, "GRIGLIA_BL_page")}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
});
