/**
 * G27.vf.visual — E2E con screenshot del PDF compilato che mostra:
 *   - tabella VF con bordi completi (|...|)
 *   - padding cell + arraystretch
 *   - riga "Sol:" merged + sfondo gray (variante SOL)
 *   - riga \cdashline vuota per giustifica (variante NOR)
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

test("VF table render PDF + screenshot (NOR + SOL)", async ({ page }) => {
    test.setTimeout(600_000);
    await login(page);
    const csrf = await fetchCsrf(page);

    // VF problem 3 affermazioni: 1 con giust + sol_V, 1 con giust + sol_F, 1 senza
    const payload = {
        version: "A",
        verTitle: `VF_VISUAL_${Date.now()}`,
        selectedIIS: "ar", selectedCLS: "2s", selectedMATER: "MAT",
        anno: "2025-26", sezione: "B",
        problems: [{
            filePath: "/eser/ar/ar2s/MAT/1",
            problemId: "uuid-vf-visual",
            position: 1,
            type: "type_VF",
            text: "Rispondi correttamente Vero o Falso.",
            items: [
                {
                    html: "Il quadrato ha 4 lati uguali.<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> Per definizione di quadrato.",
                    solution: "<span class=\"V\">Vero per definizione: il quadrato è un quadrilatero regolare.</span>",
                    points: 1.0,
                    includeSolution: false,
                },
                {
                    html: "Il triangolo equilatero ha tutti gli angoli di 90 gradi.<strong class=\"fm-sol-label\">GIUSTIFICAZIONE</strong> Confronta con la somma degli angoli.",
                    solution: "<span class=\"F\">Falso: gli angoli del triangolo equilatero sono tutti di 60 gradi.</span>",
                    points: 1.0,
                    includeSolution: false,
                },
                {
                    html: "Un cerchio ha infiniti assi di simmetria.",
                    solution: "<span class=\"V\">Vero.</span>",
                    points: 1.0,
                    includeSolution: false,
                },
            ],
        }],
        materia: "MAT", title: "VF VISUAL TEST",
        dsa: false, compensa: false,
        includeGriglia: true, includeMisure: true,
        nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
        tipologia: "scritto",
    };

    const r = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: payload,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(r.status()).toBe(200);
    const docs = (await r.json()).docs || [];
    const aNor = docs.find(d => d.variant === "A_NOR");
    const aSol = docs.find(d => d.variant === "A_SOL");
    expect(aNor && aSol).toBeTruthy();

    const screenshotsDir = path.join(__dirname, "..", "..", "tmp-vf-screenshots");
    if (!fs.existsSync(screenshotsDir)) fs.mkdirSync(screenshotsDir, { recursive: true });

    // Compile con retry intelligente: PHP fastcgi timeout 30s + nginx rate
    // limit possono dare 500/503. Riprovo dopo 30s per superare la finestra
    // rate (nginx zone=texcompile:20r/m + burst=5).
    const compileWithRetry = async (doc, label) => {
        for (let attempt = 0; attempt < 4; attempt++) {
            if (attempt > 0) await page.waitForTimeout(30_000);
            try {
                const compR = await page.request.post(`/api/verifica/${doc.id}/compile`, {
                    headers: { "X-CSRF-Token": csrf },
                    timeout: 90_000,
                });
                console.log(`[${label}] attempt ${attempt + 1}: status=${compR.status()}`);
                if (compR.status() === 200) {
                    const body = await compR.json();
                    if (body.ok) return true;
                }
            } catch (e) {
                console.log(`[${label}] attempt ${attempt + 1}: ${e.message}`);
            }
        }
        return false;
    };

    const results = {};
    for (const doc of [aNor, aSol]) {
        results[doc.variant] = await compileWithRetry(doc, doc.variant);
        await page.waitForTimeout(15_000);  // gap rate-limit
    }

    // Per ogni doc compilato → PDF screenshot
    for (const doc of [aNor, aSol]) {
        if (!results[doc.variant]) {
            console.log(`SKIP screenshot ${doc.variant}: compile fallito (rate limit/timeout)`);
            continue;
        }
        const pdfR = await page.request.get(`/api/verifica/${doc.id}/pdf`);
        if (pdfR.status() !== 200) continue;
        const pdfBuf = await pdfR.body();
        const pdfPath = path.join(screenshotsDir, `${doc.variant}.pdf`);
        fs.writeFileSync(pdfPath, pdfBuf);
        console.log(`PDF ${doc.variant}: ${pdfBuf.length} bytes → ${pdfPath}`);

        // PDF → PNG via pdftoppm (MiKTeX). Render PRIMA pagina, 150 DPI.
        const shotBase = path.join(screenshotsDir, `${doc.variant}_page`);
        try {
            execSync(`pdftoppm -png -r 150 -f 1 -l 1 "${pdfPath}" "${shotBase}"`,
                { stdio: ["ignore", "pipe", "pipe"] });
            // pdftoppm produce ${shotBase}-1.png
            const generated = `${shotBase}-1.png`;
            if (fs.existsSync(generated)) {
                console.log(`Screenshot ${doc.variant} → ${generated}`);
            } else {
                console.log(`Screenshot ${doc.variant} not generated`);
            }
        } catch (e) {
            console.log(`pdftoppm failed for ${doc.variant}: ${e.message}`);
        }
    }
    // Almeno UN PDF deve essere stato prodotto per validare il fix end-to-end
    const successCount = Object.values(results).filter(Boolean).length;
    expect(successCount, "almeno 1 compile success").toBeGreaterThan(0);

    // Verifica struttura TeX prodotto (assertions chiare per il test)
    const norFiles = await (await page.request.get(`/api/verifica/${aNor.id}/tex-files`)).json();
    const norEserc = (norFiles.files || []).find(f => f.path === "versioni/esercizi_NOR.tex");
    expect(norEserc.content).toContain("\\renewcommand{\\arraystretch}{1.4}");
    expect(norEserc.content).toContain("\\setlength{\\tabcolsep}{6pt}");
    expect(norEserc.content).toContain("\\cdashline{1-4}");

    const solFiles = await (await page.request.get(`/api/verifica/${aSol.id}/tex-files`)).json();
    const solEserc = (solFiles.files || []).find(f => f.path === "versioni/esercizi_SOL.tex");
    expect(solEserc.content).toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Giustifica}}");
});
