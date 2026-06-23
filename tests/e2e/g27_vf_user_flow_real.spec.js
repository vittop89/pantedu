/**
 * G27.vf user flow REALE — payload SENZA marker GIUSTIFICAZIONE (replica
 * exatto cosa frontend `dom-block-extractor.extractItemHtml` produce: html
 * = question, solution = vuoto/nodi raw senza <strong fm-sol-label>).
 *
 * Verifica che renderVF assume hasGiust=true sempre per VF problem.
 * Compile + screenshot di prova visiva.
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

test("VF NOR — items SENZA marker (flow frontend reale) → righe vuote presenti", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // Payload realistico: html=question solo, solution=vuoto.
    // Frontend dom-block-extractor con RAW_SELECTOR restrictive scarta
    // <strong class="fm-sol-label"> → marker mai arriva al backend.
    const payload = {
        version: "A",
        verTitle: `VF_REAL_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "vf-real-flow",
            position: 1, type: "type_VF",
            text: "Rispondi correttamente Vero o Falso.",
            items: [
                { html: "Affermazione 1.", solution: "", points: 1.0, includeSolution: false },
                { html: "Affermazione 2.", solution: "", points: 1.0, includeSolution: false },
                { html: "Affermazione 3.", solution: "", points: 1.0, includeSolution: false },
            ],
        }],
        materia: "MAT", title: "VF REAL",
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

    // Asserzione: 3 cdashline (1 per ogni item, anche senza marker)
    const filesR = await page.request.get(`/api/verifica/${aNor.id}/tex-files`);
    const norEserc = (await filesR.json()).files.find(f => f.path === "versioni/esercizi_NOR.tex");
    const cdashCount = (norEserc.content.match(/\\cdashline\{1-4\}/g) || []).length;
    console.log(`[NOR] cdashline count: ${cdashCount} (expected 3)`);
    expect(cdashCount).toBe(3);

    // Compile + screenshot
    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });

    let compiled = false;
    for (let attempt = 0; attempt < 4; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aNor.id}/compile`, {
            headers: { "X-CSRF-Token": csrf },
            timeout: 90_000,
        });
        console.log(`[compile] attempt ${attempt + 1}: status=${compR.status()}`);
        if (compR.status() === 200 && (await compR.json()).ok) {
            compiled = true; break;
        }
    }
    expect(compiled).toBe(true);

    const pdfR = await page.request.get(`/api/verifica/${aNor.id}/pdf`);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, "VF_REAL_NOR.pdf");
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length}b → ${pdfPath}`);
    execSync(`pdftoppm -png -r 150 -f 1 -l 1 "${pdfPath}" "${path.join(screenshotsDir, "VF_REAL_NOR_page")}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
});
