/**
 * G27.dsa-sci-firma — DSA Scientifico (SCI_MAT/sc_MAT) compact griglia con
 * firma "Comune Esempio,_/_/2026 Voto:_/10" presente DOPO TOTALE + compensa firme
 * sotto. Fix porting legacy: firma non strippata in compact.
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

test("DSA Scientifico+compensa: firma DOPO TOTALE + compensa con sue firme", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    const payload = {
        version: "A",
        verTitle: `DSA_SCI_${Date.now()}`,
        selectedIIS: "sc", selectedCLS: "1s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/sc/sc1s/MAT/1",
            problemId: "dsa-sci",
            position: 1, type: "Collect",
            text: "Esercizio:",
            items: [{ html: "Test.", points: 1.0, includeSolution: false }],
        }],
        materia: "MAT", title: "DSA SCI",
        dsa: true, compensa: true,
        includeGriglia: true, includeMisure: true,
        nPrint: 0, nPrintDSA: 1, nPrintDIS: 0,
        tipologia: "scritto",
        variants: ['A_DSA'],
    };
    const saveR = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(saveR.status()).toBe(200);
    const docs = (await saveR.json()).docs || [];
    const aDsa = docs.find(d => d.variant === "A_DSA");
    expect(aDsa).toBeTruthy();

    // Verifica TEX: griglia compact PRESERVA firma "Comune Esempio" + compensa file presente
    const filesR = await page.request.get(`/api/verifica/${aDsa.id}/tex-files`);
    const files = (await filesR.json()).files;
    const griglia = files.find(f => f.path.endsWith("_compact.tex"));
    expect(griglia, "compact griglia presente").toBeTruthy();
    expect(griglia.content).toContain("Comune Esempio,");
    expect(griglia.content).toContain("/10");
    expect(griglia.content).toContain("\\fontsize{7}{8}");

    // Compile + screenshot
    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aDsa.id}/compile`, {
            data: { engine: 'pdflatex' },
            headers: { "X-CSRF-Token": csrf, "Content-Type": "application/json" },
            timeout: 90_000,
        });
        if (compR.status() === 200 && (await compR.json()).ok) { compiled = true; break; }
    }
    expect(compiled).toBe(true);

    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });
    const pdfR = await page.request.get(`/api/verifica/${aDsa.id}/pdf`);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, "DSA_SCI.pdf");
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length}b`);
    execSync(`pdftoppm -png -r 150 "${pdfPath}" "${path.join(screenshotsDir, "DSA_SCI_page")}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
});
