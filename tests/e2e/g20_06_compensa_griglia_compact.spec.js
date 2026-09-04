/**
 * G20.6 — E2E: Compensa attivo → griglia compact emessa nel ZIP +
 * referenziata da main_DSA/main_DIS, e compila con pdflatex.
 */
const { test, expect } = require("@playwright/test");
const fs   = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("Compensa ON: griglia _compact emessa + main DSA/DIS la referenzia + compila", async ({ page }) => {
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
            verTitle: "G20Compensa", selectedIIS: "sc", selectedCLS: "3", selectedMATER: "MAT",
            anno: "2026", sezione: "NOR",
            problems: [{ filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect", text: "Risolvi.",
                items: [{ html: `Determina ${PUB}.`, solution: String.raw`\(x = 4\sqrt{19}/10\)`, points: 1.0, includeSolution: false }],
            }],
            options: { includeSolutions: false }, versions: ["A"], title: "G20Compensa", materia: "MAT",
            indirizzo: "sc", classe: "3", version_label: "g20c", nPrint: 1, nPrintDSA: 1, nPrintDIS: 1,
            dsa: true, compensa: true, force: true,
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    expect(r.ok()).toBeTruthy();
    const body = await r.json();
    const batchId = body.batch_id;

    // Download + extract ZIP
    const zipResp = await page.request.get(`/api/verifica/batch/${batchId}/zip`);
    expect(zipResp.ok()).toBeTruthy();
    const extractDir = path.join("C:", "tmp", "g20compensa-test", batchId);
    fs.mkdirSync(extractDir, { recursive: true });
    const zipPath = path.join(extractDir, "bundle.zip");
    fs.writeFileSync(zipPath, await zipResp.body());
    try {
        execSync(`unzip -o "${zipPath.replace(/\\/g, "/")}" -d "${extractDir.replace(/\\/g, "/")}"`,
                 { stdio: "pipe", shell: "C:\\Program Files\\Git\\bin\\bash.exe" });
    } catch {
        execSync(`powershell -Command "Expand-Archive -Force -Path '${zipPath}' -DestinationPath '${extractDir}'"`,
                 { stdio: "pipe" });
    }

    // Verifica: entrambe le griglie emesse
    const standardGriglia = path.join(extractDir, "griglie", "sc_MAT.tex");
    const compactGriglia  = path.join(extractDir, "griglie", "sc_MAT_compact.tex");
    expect(fs.existsSync(standardGriglia), "griglia standard").toBe(true);
    expect(fs.existsSync(compactGriglia),  "griglia compact con Compensa").toBe(true);

    // Verifica: la compact contiene fontsize{7}{2}
    const compactText = fs.readFileSync(compactGriglia, "utf-8");
    expect(compactText).toContain("\\fontsize{7}{2}\\selectfont");
    // E NON ha fontsize{8.5} originale
    expect(compactText).not.toMatch(/\\fontsize\{8\.5\}/);

    // main_DSA + main_DIS referenziano la _compact
    const mainDsa = fs.readFileSync(path.join(extractDir, "versioni", "main_DSA.tex"), "utf-8");
    const mainDis = fs.readFileSync(path.join(extractDir, "versioni", "main_DIS.tex"), "utf-8");
    expect(mainDsa).toContain("griglie/sc_MAT_compact");
    expect(mainDis).toContain("griglie/sc_MAT_compact");

    // main_NOR + main_SOL invece NON referenziano la _compact
    const mainNor = fs.readFileSync(path.join(extractDir, "versioni", "main_NOR.tex"), "utf-8");
    const mainSol = fs.readFileSync(path.join(extractDir, "versioni", "main_SOL.tex"), "utf-8");
    expect(mainNor).not.toContain("_compact");
    expect(mainSol).not.toContain("_compact");

    // Compila tutti i 4 main → 4 PDF
    const versioniDir = path.join(extractDir, "versioni");
    const variants = ["NOR", "SOL", "DSA", "DIS"];
    for (const v of variants) {
        execSync(`cd /d "${versioniDir}" && pdflatex -interaction=nonstopmode -halt-on-error main_${v}.tex`, {
            stdio: "pipe", shell: "cmd",
        });
        const pdf = path.join(versioniDir, `main_${v}.pdf`);
        expect(fs.existsSync(pdf), `${v} compila`).toBe(true);
        expect(fs.statSync(pdf).size, `${v} non-empty`).toBeGreaterThan(0);
    }
    console.log("✓ 4 PDF compilati con Compensa griglia compact");
});
