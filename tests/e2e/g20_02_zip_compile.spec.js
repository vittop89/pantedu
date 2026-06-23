/**
 * G20.0 — E2E live: genera batch verifica, scarica ZIP nuovo layout,
 * estrae, compila pdflatex su tutti i 4 main → 4 PDF.
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("ZIP multi-file: download + extract + compile pdflatex 4 main", async ({ page }) => {
    test.setTimeout(180000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    const PUB = String.raw`\(\enclose{circle}[mathcolor=red]{x}\)`;
    const r = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: {
            verTitle: "G20ZipTest", selectedIIS: "sc", selectedCLS: "3", selectedMATER: "MAT",
            anno: "2026", sezione: "NOR",
            problems: [{ filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect", text: "Risolvi.",
                items: [{ html: `Determina ${PUB}.`, solution: String.raw`\(x = 4\sqrt{19}/10\)`, points: 1.0, includeSolution: false }],
            }],
            options: { includeSolutions: false }, versions: ["A"], title: "G20ZipTest", materia: "MAT",
            indirizzo: "sc", classe: "3", version_label: "g20", nPrint: 1, nPrintDSA: 1, nPrintDIS: 1, dsa: true, force: true,
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    if (!r.ok()) {
        const errBody = await r.text();
        console.log(`save-tex-batch failed: status=${r.status()} body=${errBody.slice(0, 500)}`);
    }
    expect(r.ok()).toBeTruthy();
    const body = await r.json();
    expect(body.ok).toBe(true);
    expect(body.docs.length).toBeGreaterThanOrEqual(4);
    const batchId = body.batch_id;
    console.log(`Batch: ${batchId} (${body.docs.length} varianti)`);

    // Download ZIP
    const zipResp = await page.request.get(`/api/verifica/batch/${batchId}/zip`);
    expect(zipResp.ok()).toBeTruthy();
    const zipBytes = await zipResp.body();
    console.log(`ZIP size: ${zipBytes.length} bytes`);

    // Estrai con tar (Windows nativo)
    const extractDir = path.join("C:", "tmp", "g20zip-test", batchId);
    fs.mkdirSync(extractDir, { recursive: true });
    const zipPath = path.join(extractDir, "bundle.zip");
    fs.writeFileSync(zipPath, zipBytes);
    try {
        // unzip da MSYS/Git Bash (sempre disponibile su Windows con git)
        execSync(`unzip -o "${zipPath.replace(/\\/g, "/")}" -d "${extractDir.replace(/\\/g, "/")}"`,
                 { stdio: "pipe", shell: "C:\\Program Files\\Git\\bin\\bash.exe" });
    } catch (e) {
        // fallback: PowerShell Expand-Archive
        try {
            execSync(`powershell -Command "Expand-Archive -Force -Path '${zipPath}' -DestinationPath '${extractDir}'"`,
                     { stdio: "pipe" });
        } catch (e2) {
            console.log("extract failed:", e2.message);
        }
    }

    // Verify struttura
    const expected = [
        "texCommon/verifica.sty",
        "texCommon/intestazione.tex",
        "texCommon/ulteriori_misure.tex",
        "texCommon/BES_DSA/misure_dispensative.tex",
        "texCommon/BES_DSA/compensazione_orale.tex",
        "griglie/sc_MAT.tex",
        "versioni/main_NOR.tex",
        "versioni/main_SOL.tex",
        "versioni/main_DSA.tex",
        "versioni/main_DIS.tex",
        "versioni/esercizi_NOR.tex",
        "versioni/esercizi_SOL.tex",
        "versioni/esercizi_DSA.tex",
        "versioni/esercizi_DIS.tex",
        "README.txt",
    ];
    for (const rel of expected) {
        const full = path.join(extractDir, rel);
        expect(fs.existsSync(full), `manca file: ${rel}`).toBe(true);
    }
    console.log("✓ Struttura ZIP corretta (15 file)");

    // Compila ognuno dei 4 main_*.tex
    const versioniDir = path.join(extractDir, "versioni");
    const variants = ["NOR", "SOL", "DSA", "DIS"];
    const results = {};
    for (const v of variants) {
        try {
            execSync(`cd /d "${versioniDir}" && pdflatex -interaction=nonstopmode -halt-on-error main_${v}.tex`, {
                stdio: "pipe", shell: "cmd",
            });
            const pdf = path.join(versioniDir, `main_${v}.pdf`);
            results[v] = fs.existsSync(pdf) ? fs.statSync(pdf).size : 0;
        } catch (e) {
            results[v] = `ERROR: ${e.message.split("\n")[0].slice(0, 100)}`;
        }
    }
    console.log("Compile results:", results);
    for (const v of variants) {
        expect(typeof results[v], `${v} should compile to PDF`).toBe("number");
        expect(results[v], `${v} PDF should be > 0 bytes`).toBeGreaterThan(0);
    }
});
