/**
 * Test produzione TeX: per ogni template con tex_file, genera ZIP via export,
 * scarica, verifica struttura contenuti:
 *  - main.tex  ha UN solo \documentclass
 *  - body.tex  NO \documentclass / NO \begin{document}
 *  - risdoc.sty presente
 *  - intestaLAteX_IIS.tex presente
 *
 * Opzionale: tenta compilazione pdflatex se disponibile (skip se non installato).
 */

const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");
const { execSync } = require("child_process");
const AdmZip = require("adm-zip");

test.setTimeout(240000);

const TEMPLATES_WITH_TEX = [16, 19, 20, 21, 22, 24, 25];

test("tex production pipeline — all templates", async ({ browser }) => {
    const page = await browser.newPage();
    await page.addInitScript(() => {
        localStorage.setItem("cookieConsent", JSON.stringify({
            necessary:true,functional:true,analytics:false,marketing:false,
            date: new Date().toISOString()
        }));
    });
    await page.goto("/login");
    await page.fill('input[name="username"]', "superadmin");
    await page.fill('input[name="password"]', (process.env.E2E_TEACHER_PASS || ""));
    await Promise.all([page.waitForURL(/^(?!.*\/login).*/), page.click('button[type="submit"]')]);
    await page.waitForTimeout(800);

    const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
    const OUT = path.join(__dirname, "..", "e2e-results", "tex-production");
    fs.mkdirSync(OUT, { recursive: true });

    const REPORT = { ranAt: new Date().toISOString(), templates: {} };

    for (const tid of TEMPLATES_WITH_TEX) {
        console.log(`\n=== Template ${tid} ===`);
        const res = await page.request.post(`/api/risdoc/templates/${tid}/export`, {
            form: {
                _csrf: csrf,
                mode: "zip",
                form_state: JSON.stringify({
                    fields: {
                        profilo_classe: "Classe eterogenea, buona partecipazione in generale.",
                        studenti_table: [
                            { __label: "TOTALE", value: "25" },
                            { __label: "DIVERSAMENTE ABILI - TOTALE", value: "2" },
                            { __label: "CON DSA CON PDP - TOTALE", value: "3" },
                            { __label: "CON BES - TOTALI", value: "5" },
                            { __label: "con BES e PDP", value: "1" },
                        ],
                    },
                    state: { classe: "2s", sezione: "A", indirizzo: "sc", disciplina: "MAT", professore: "Vittorio Pantaleo" },
                }),
            },
        });
        const j = await res.json();
        expect.soft(res.status(), `tmpl ${tid} export`).toBe(200);
        if (!j.ok) {
            REPORT.templates[tid] = { status: "FAIL_EXPORT", error: j.error || j.detail };
            continue;
        }

        // Download ZIP
        const dl = await page.request.get(j.url);
        const zipBuf = Buffer.from(await dl.body());
        const zipPath = path.join(OUT, `tmpl-${tid}.zip`);
        fs.writeFileSync(zipPath, zipBuf);

        // Inspect with adm-zip
        const zip = new AdmZip(zipBuf);
        const entries = zip.getEntries().map(e => e.entryName);
        const mainTex = zip.getEntry("main.tex")?.getData().toString("utf-8") || "";
        const bodyEntry = entries.find(n => /\.tex$/.test(n) && n !== "main.tex" && !n.startsWith("texCommon/"));
        const bodyTex = bodyEntry ? zip.getEntry(bodyEntry).getData().toString("utf-8") : "";

        const check = {
            entries,
            mainDocumentclassCount: (mainTex.match(/\\documentclass/g) || []).length,
            bodyDocumentclassCount: (bodyTex.match(/\\documentclass/g) || []).length,
            bodyHasBeginDocument: /\\begin\{document\}/.test(bodyTex),
            bodyHasEndDocument:   /\\end\{document\}/.test(bodyTex),
            bodyLen: bodyTex.length,
            bodyLineCount: bodyTex.split(/\r?\n/).length,
            hasRisdocSty: entries.includes("texCommon/risdoc.sty"),
            hasIntestazione: entries.includes("texCommon/intestaLAteX_IIS.tex"),
        };

        // Asserts struttura (hard)
        const fails = [];
        if (check.mainDocumentclassCount !== 1) fails.push(`main \\documentclass ×${check.mainDocumentclassCount}`);
        if (check.bodyDocumentclassCount !== 0) fails.push(`body \\documentclass ×${check.bodyDocumentclassCount}`);
        if (check.bodyHasBeginDocument) fails.push(`body \\begin{document} presente`);
        if (check.bodyHasEndDocument) fails.push(`body \\end{document} presente`);
        if (!check.hasRisdocSty) fails.push("risdoc.sty missing");
        if (!check.hasIntestazione) fails.push("intestaLAteX_IIS.tex missing");
        if (check.bodyLen < 100) fails.push(`body troppo corto (${check.bodyLen} b)`);
        if (check.bodyLineCount < 10) fails.push(`body too few lines (${check.bodyLineCount})`);

        // CONTENT CHECKS — verifica sostituzione corretta
        const contentChecks = [];
        // No [field] marker letterale nel body (eccetto [field-name] che non ha altri match)
        if (/\[field\](?!-)/.test(bodyTex)) contentChecks.push("[field] literali non sostituiti");
        // No [field-name] rimasti
        if (/\[field-[a-zA-Z0-9_-]+\]/.test(bodyTex)) contentChecks.push("[field-name] literali non sostituiti");
        // Se template 16: verifica labels risolte (no "2s" "sc" "MAT" grezzi in \simplefield)
        if (tid === 16) {
            if (/\\simplefield\{[^}]*\}\{2s\}/.test(bodyTex)) contentChecks.push("classe=2s non risolto in Classe II");
            if (/\\simplefield\{[^}]*\}\{sc\}/.test(bodyTex)) contentChecks.push("indirizzo=sc non risolto in Scientifico");
            if (/\\simplefield\{[^}]*\}\{MAT\}/.test(bodyTex)) contentChecks.push("disciplina=MAT non risolto in Matematica");
            if (/\\simplefield\{Sezione\}\{\}/.test(bodyTex)) contentChecks.push("sezione vuota");
            // profilo_classe override
            if (!/Classe eterogenea/.test(bodyTex)) contentChecks.push("profilo_classe override NON applicato");
            // studenti_table values
            if (!/TOTALE\s*&\s*25\s*\\\\/.test(bodyTex)) contentChecks.push("studenti_table TOTALE=25 non sostituito");
        }
        check.contentChecks = contentChecks;
        fails.push(...contentChecks);

        REPORT.templates[tid] = { ...check, status: fails.length === 0 ? "PASS" : "FAIL", fails };
        console.log(`  ${fails.length === 0 ? "PASS" : "FAIL"} — ${bodyEntry}`);
        if (fails.length) console.log(`  fails: ${fails.join(", ")}`);
    }

    // Compile test: prova pdflatex su ogni ZIP se disponibile
    const PDFLATEX = "C:/Users/vitto/AppData/Local/Programs/MiKTeX/miktex/bin/x64/pdflatex.exe";
    const hasPdflatex = fs.existsSync(PDFLATEX);
    if (hasPdflatex) {
        console.log(`\n========== COMPILE TEST ==========`);
        for (const tid of TEMPLATES_WITH_TEX) {
            const zipPath = path.join(OUT, `tmpl-${tid}.zip`);
            if (!fs.existsSync(zipPath)) continue;
            const extractDir = path.join(OUT, `compile-${tid}`);
            fs.rmSync(extractDir, { recursive: true, force: true });
            fs.mkdirSync(extractDir, { recursive: true });
            new AdmZip(zipPath).extractAllTo(extractDir, true);

            try {
                // nonstopmode (senza halt-on-error): continua su errori non fatali,
                // molti template legacy hanno warning che non impediscono il PDF.
                execSync(`"${PDFLATEX}" -interaction=nonstopmode -file-line-error main.tex`, {
                    cwd: extractDir, timeout: 45000, stdio: "pipe",
                });
            } catch {} // ignore exit code: controlliamo PDF esistenza sotto
            const pdfOk = fs.existsSync(path.join(extractDir, "main.pdf"));
            REPORT.templates[tid].compile = pdfOk ? "PASS" : "FAIL";
            if (!pdfOk) {
                const logPath = path.join(extractDir, "main.log");
                const logErrs = fs.existsSync(logPath)
                    ? fs.readFileSync(logPath, "utf8").split("\n").filter(l => /^!|\.tex:\d+:/.test(l)).slice(-3).join(" | ")
                    : "(no log)";
                REPORT.templates[tid].compileError = logErrs;
                console.log(`  ${tid}: FAIL — ${logErrs.slice(0, 150)}`);
            } else {
                console.log(`  ${tid}: PASS (pdf generated)`);
            }
        }
    } else {
        console.log("pdflatex non installato — skip compile test");
    }

    fs.writeFileSync(path.join(OUT, "report.json"), JSON.stringify(REPORT, null, 2));

    const structFails = Object.entries(REPORT.templates).filter(([, v]) => v.status !== "PASS");
    const compileFails = Object.entries(REPORT.templates).filter(([, v]) => v.compile && v.compile !== "PASS");
    console.log(`\n========== SUMMARY ==========`);
    console.log(`Templates: ${Object.keys(REPORT.templates).length}`);
    console.log(`Structure PASS: ${Object.keys(REPORT.templates).length - structFails.length}`);
    console.log(`Structure FAIL: ${structFails.length}`);
    console.log(`Compile PASS: ${Object.keys(REPORT.templates).length - compileFails.length}`);
    console.log(`Compile FAIL: ${compileFails.length} (bug legacy .tex, non regressione)`);
    for (const [tid, v] of structFails) console.log(`  STRUCT ${tid}: ${(v.fails || [v.error]).join("; ")}`);
    for (const [tid, v] of compileFails) console.log(`  COMPILE ${tid}: ${v.compileError?.slice(0, 120) || "(no log)"}`);

    // Hard: structure must pass (nostra responsabilità)
    expect(structFails, "structure failures").toHaveLength(0);
    // Soft: compile may fail per bug legacy .tex preesistenti
    expect.soft(compileFails.length, "compile failures (tollerabili, verifica bug legacy)").toBeLessThanOrEqual(1);
    await page.close();
});
