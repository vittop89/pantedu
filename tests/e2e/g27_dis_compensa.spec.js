/**
 * G27.dis.compensa — DIS variant con compensa=true: TexBuilder include
 * compensazione_orale.tex SOTTO griglia + usa griglia compact baseline 7/8.
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

test("DIS+compensa: compensazione_orale SOTTO griglia + compact 7/8", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `DIS_COMP_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "dis-comp",
            position: 1, type: "Collect",
            text: "Esercizio:",
            items: [{ html: "Test esercizio.", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT", title: "DIS COMP",
        dsa: true, compensa: true,
        includeGriglia: true, includeMisure: true,
        nPrint: 0, nPrintDSA: 0, nPrintDIS: 1,
        tipologia: "scritto",
        variants: ['A_DIS'],
    };
    const saveR = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(saveR.status()).toBe(200);
    const docs = (await saveR.json()).docs || [];
    const aDis = docs.find(d => d.variant === "A_DIS");
    expect(aDis).toBeTruthy();

    // Verifica TEX bundle: compensa input presente, ordine SOTTO griglia
    const filesR = await page.request.get(`/api/verifica/${aDis.id}/tex-files`);
    const files = (await filesR.json()).files;
    const main = files.find(f => f.path === "versioni/main_DIS.tex");
    expect(main).toBeTruthy();
    const grigliaPos = main.content.indexOf("griglie/");
    const compensaPos = main.content.indexOf("compensazione_orale");
    console.log(`[ORDER] griglia@${grigliaPos} compensa@${compensaPos}`);
    expect(grigliaPos).toBeGreaterThan(0);
    expect(compensaPos).toBeGreaterThan(grigliaPos);
    // No più commento `% \input{compensa}` — placeholder COMPENSA_OPEN risolto a vuoto
    expect(main.content).not.toContain("% \\input{");

    // Compact griglia: cerca \fontsize{7}{8} nel griglia compact file
    const griglia = files.find(f => f.path.endsWith("_compact.tex"));
    expect(griglia, "compact griglia presente").toBeTruthy();
    expect(griglia.content).toContain("\\fontsize{7}{8}\\selectfont");

    // Compile + screenshot
    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aDis.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 120_000,
        });
        console.log(`[compile] attempt ${attempt + 1}: status=${compR.status()}`);
        if (compR.status() === 200 && (await compR.json()).ok) { compiled = true; break; }
    }
    expect(compiled).toBe(true);

    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });
    const pdfR = await page.request.get(`/api/verifica/${aDis.id}/pdf`);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, "DIS_COMP.pdf");
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length}b → ${pdfPath}`);
    execSync(`pdftoppm -png -r 150 "${pdfPath}" "${path.join(screenshotsDir, "DIS_COMP_page")}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
});
