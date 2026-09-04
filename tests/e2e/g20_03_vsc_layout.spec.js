/**
 * G20.0 — E2E live: bundle VSC distribuito.
 * Genera batch, fetcha /batch/{id}/files, materializza i path distribuiti
 * nel filesystem locale, compila i 4 main con pdflatex.
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

test("VSC distributed: bundle paths + pdflatex compile", async ({ page }) => {
    test.setTimeout(180000);
    await page.goto("/login");
    await page.locator("input[name=username]").fill(USERNAME);
    await page.locator("input[name=password]").fill(PASSWORD);
    await page.locator("button[type=submit]").first().click();
    await page.waitForLoadState("networkidle");
    const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

    const r = await page.request.post("/api/verifica/save-tex-batch?force=1", {
        data: {
            verTitle: "G20VscTest", selectedIIS: "sc", selectedCLS: "3", selectedMATER: "MAT",
            anno: "2026", sezione: "NOR",
            problems: [{ filePath: "/x", problemId: "type_Collect_x", position: 1, type: "Collect", text: "Risolvi.",
                items: [{ html: String.raw`Determina \(\enclose{circle}[mathcolor=red]{x}\).`, solution: String.raw`\(x = 4\sqrt{19}/10\)`, points: 1.0, includeSolution: false }],
            }],
            options: { includeSolutions: false }, versions: ["A"], title: "G20VscTest", materia: "MAT",
            indirizzo: "sc", classe: "3", version_label: "vsc", nPrint: 1, nPrintDSA: 1, nPrintDIS: 1, dsa: true, force: true,
        },
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    });
    if (!r.ok()) {
        console.log("save fail:", r.status(), (await r.text()).slice(0, 400));
    }
    expect(r.ok()).toBeTruthy();
    const body = await r.json();
    expect(body.docs.length).toBeGreaterThanOrEqual(4);
    const batchId = body.batch_id;
    console.log(`Batch: ${batchId}`);

    // Fetch bundle VSC
    const fr = await page.request.get(`/api/verifica/batch/${batchId}/files`);
    expect(fr.ok()).toBeTruthy();
    const fb = await fr.json();
    expect(fb.ok).toBe(true);
    expect(fb.institute_code).toBeTruthy();
    console.log(`Institute: ${fb.institute_code} | Files: ${fb.files.length}`);

    // Espone i path
    const paths = fb.files.map(f => f.path);
    console.log("Files paths:");
    paths.forEach(p => console.log("  " + p));

    // Asserzioni layout distribuito
    const expectedTexCommon = [
        "texCommon/verifica.sty",
        "texCommon/intestazione.tex",
        "texCommon/ulteriori_misure.tex",
        "texCommon/BES_DSA/misure_dispensative.tex",
        "texCommon/BES_DSA/compensazione_orale.tex",
    ];
    for (const p of expectedTexCommon) {
        expect(paths).toContain(p);
    }
    expect(paths).toContain("sc/griglie/sc_MAT.tex");

    // Cerca version folder
    const verRegex = /^sc\/3\/MAT\/verifiche\/g20vsctest\/vsc-[\d_]+-[A-Z_]+\/main_NOR\.tex$/;
    const norMain = paths.find(p => verRegex.test(p));
    expect(norMain, "main_NOR.tex con path distribuito").toBeTruthy();

    // Materializza in fs locale + compile
    const root = path.join("C:", "tmp", "g20vsc-test", batchId, fb.institute_code);
    fs.mkdirSync(root, { recursive: true });
    for (const f of fb.files) {
        const full = path.join(root, f.path);
        fs.mkdirSync(path.dirname(full), { recursive: true });
        fs.writeFileSync(full, f.content);
    }
    console.log(`Materialized at: ${root}`);

    // Compila ognuno dei 4 main (cwd = version folder)
    const verDir = path.dirname(path.join(root, norMain));
    console.log(`Version dir: ${verDir}`);
    const variants = ["NOR", "SOL", "DSA", "DIS"];
    const results = {};
    for (const v of variants) {
        try {
            execSync(`cd /d "${verDir}" && pdflatex -interaction=nonstopmode -halt-on-error main_${v}.tex`, {
                stdio: "pipe", shell: "cmd",
            });
            const pdf = path.join(verDir, `main_${v}.pdf`);
            results[v] = fs.existsSync(pdf) ? fs.statSync(pdf).size : 0;
        } catch (e) {
            const stdoutTail = (e.stdout?.toString() || "").split("\n").slice(-15).join("\n");
            results[v] = `ERROR (last lines):\n${stdoutTail}`;
        }
    }
    console.log("Compile results:", results);
    for (const v of variants) {
        expect(typeof results[v], `${v} should compile to PDF`).toBe("number");
        expect(results[v], `${v} PDF should be > 0 bytes`).toBeGreaterThan(0);
    }
});
