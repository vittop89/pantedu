/**
 * G27.dis.xelatex — DIS variant compila con xelatex (auto-override backend
 * per fontspec+OpenDyslexic). Verifica engine usato e screenshot PDF.
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

test("DIS compila con xelatex + OpenDyslexic font visibile", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `DIS_FONT_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "dis-test",
            position: 1, type: "Collect",
            text: "Esercizio:",
            items: [
                { html: "Testo che dovrebbe apparire in OpenDyslexic font.", points: 1.0, includeSolution: false },
            ],
        }],
        materia: "MAT", title: "DIS FONT",
        dsa: true, compensa: false,  // dsa=true necessario per generare DIS
        includeGriglia: true, includeMisure: true,
        nPrint: 0, nPrintDSA: 0, nPrintDIS: 1,
        tipologia: "scritto",
        variants: ['A_DIS'],  // forza esplicita
    };

    const saveR = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(saveR.status()).toBe(200);
    const docs = (await saveR.json()).docs || [];
    const aDis = docs.find(d => d.variant === "A_DIS");
    expect(aDis, "A_DIS doc presente").toBeTruthy();

    // Compile DIS — backend auto-override engine=xelatex
    let compiled = false; let engineUsed = '';
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        // Simulate frontend topbar che invia engine=pdflatex (default dropdown).
        // Backend deve override a xelatex per DIS variant.
        const compR = await page.request.post(`/api/verifica/${aDis.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 120_000,
        });
        console.log(`[compile] attempt ${attempt + 1}: status=${compR.status()}`);
        if (compR.status() === 200) {
            const body = await compR.json();
            if (body.ok) {
                compiled = true;
                engineUsed = body.compile?.engine || '?';
                console.log(`[compile] engine=${engineUsed}`);
                break;
            }
        }
    }
    expect(compiled).toBe(true);
    expect(engineUsed).toBe('xelatex');

    // Screenshot PDF
    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });
    const pdfR = await page.request.get(`/api/verifica/${aDis.id}/pdf`);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, "DIS_FONT.pdf");
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length}b → ${pdfPath}`);
    execSync(`pdftoppm -png -r 150 -f 1 -l 1 "${pdfPath}" "${path.join(screenshotsDir, "DIS_FONT_page")}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
});
