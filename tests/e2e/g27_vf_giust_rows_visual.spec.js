/**
 * G27.vf.giust-rows visual — verifica che le righe vuote tratteggiate per
 * scrittura giustifica appaiano nella variante NOR quando il marker
 * GIUSTIFICAZIONE è presente nel field `solution` (frontend extraction).
 *
 * Genera PDF compilato + screenshot per ispezione visuale.
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

test("VF NOR — righe vuote scrittura giust visibili nel PDF", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // Payload mimic frontend extraction: html=question, solution=marker+giustsol.
    // Item 1+2: marker presente → righe vuote attese
    // Item 3: niente solution → no righe vuote (compatto)
    const payload = {
        version: "A",
        verTitle: `VF_GIUST_ROWS_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "vf-giust-rows",
            position: 1, type: "type_VF",
            text: "Rispondi correttamente Vero o Falso.",
            items: [
                {
                    html: "Il quadrato ha 4 lati uguali.",
                    solution: "<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> ",
                    points: 1.0, includeSolution: false,
                },
                {
                    html: "Il triangolo equilatero ha tutti gli angoli di 90 gradi.",
                    solution: "<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> ",
                    points: 1.0, includeSolution: false,
                },
                {
                    html: "Un cerchio ha infiniti assi di simmetria.",
                    solution: "",  // no marker → tabella compatta
                    points: 1.0, includeSolution: false,
                },
            ],
        }],
        materia: "MAT", title: "VF GIUST ROWS",
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

    // Asserzioni TEX: 2 \cdashline (item 1+2 con marker; item 3 no)
    const norFiles = await (await page.request.get(`/api/verifica/${aNor.id}/tex-files`)).json();
    const norEserc = (norFiles.files || []).find(f => f.path === "versioni/esercizi_NOR.tex");
    expect(norEserc).toBeTruthy();
    const cdashCount = (norEserc.content.match(/\\cdashline\{1-4\}/g) || []).length;
    console.log(`[NOR TEX] cdashline count: ${cdashCount}`);
    expect(cdashCount).toBe(3);

    // Compile + screenshot
    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });

    let compiled = false;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (attempt > 0) await page.waitForTimeout(20_000);
        const compR = await page.request.post(`/api/verifica/${aNor.id}/compile`, {
            headers: { "X-CSRF-Token": csrf },
            timeout: 90_000,
        });
        if (compR.status() === 200 && (await compR.json()).ok) {
            compiled = true;
            break;
        }
        console.log(`compile attempt ${attempt + 1}: status=${compR.status()}`);
    }
    expect(compiled, "compile success").toBe(true);

    const pdfR = await page.request.get(`/api/verifica/${aNor.id}/pdf`);
    expect(pdfR.status()).toBe(200);
    const pdfBuf = await pdfR.body();
    const pdfPath = path.join(screenshotsDir, `VF_GIUST_NOR.pdf`);
    fs.writeFileSync(pdfPath, pdfBuf);
    console.log(`PDF: ${pdfBuf.length} bytes → ${pdfPath}`);

    // Render PDF → PNG via pdftoppm (MiKTeX)
    const shotBase = path.join(screenshotsDir, `VF_GIUST_NOR_page`);
    execSync(`pdftoppm -png -r 150 -f 1 -l 1 "${pdfPath}" "${shotBase}"`,
        { stdio: ["ignore", "pipe", "pipe"] });
    const generated = `${shotBase}-1.png`;
    expect(fs.existsSync(generated)).toBe(true);
    console.log(`Screenshot → ${generated}`);
});
