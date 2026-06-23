/**
 * G19.49h — E2E live: genera un batch verifica con payload sintetico
 * (problema con cerchi MathJax, badge editoriale, soluzione embedded),
 * recupera i .tex per variant e asserisce le trasformazioni:
 *   - SOL: contiene badge libro + sol + \Circled
 *   - NOR/DSA/DIS: NO badge libro, NO sol, sì \Circled
 *   - Tutti: `\(...\)` → `$...$`, no `\enclose`, no `\bbox`, no `\mathmakebox`
 */

const { test, expect } = require("@playwright/test");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

const PUBLISHER_BADGE = `\\(\\begin{array}{|c|}\\hline\\small{\\text{Matematica multimediale.blu}}\\\\[-5pt]\\tiny{\\text{Vol.2 Ed.3 - ZANICHELLI}}\\\\[-5pt]\\tiny{\\text{Massimo Bergamini - Graziella Barozzi}}\\\\[-5pt]\\hline\\end{array}\\quad\\overset{\\color{red}\\huge \\bullet\\bullet\\circ\\circ}{\\underset{\\text{P-}1171}{\\bbox[border: 1px solid white; background: green,3pt]{{\\mathmakebox[cm][c]{\\textcolor{white}{\\large 181}}}}}}\\quad\\)`;

const PROBLEM_HTML = `${PUBLISHER_BADGE} Sia \\(ABCD\\) un trapezio. Determina \\(\\enclose{circle}[mathcolor=red]{x}\\).`;
const SOLUTION_HTML = `\\(\\enclose{circle}[mathcolor=red]{x} = \\dfrac{32 - 4\\sqrt{19}}{10}\\)`;

test.describe("G19.49h — TEX generation per variant", () => {
    test("SOL contiene badge+sol+Circled, NOR esclude badge+sol", async ({ page }) => {
        // Login
        await page.goto("/login");
        await page.locator("input[name=username]").fill(USERNAME);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");

        const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

        const payload = {
            verTitle: "TestE2E G19.49h",
            selectedIIS: "sc",
            selectedCLS: "3",
            selectedMATER: "MAT",
            anno: "2026",
            sezione: "NOR",
            problems: [{
                filePath: "/test/e2e",
                problemId: "type_Collect_test",
                position: 1,
                type: "Collect",
                text: "Risolvi il seguente problema.",
                items: [{
                    html: PROBLEM_HTML,
                    solution: SOLUTION_HTML,
                    points: 1.0,
                    includeSolution: false,
                }],
            }],
            options: { includeTitlePage: true, includeSolutions: false },
            versions: ["A"],
            title: "TestE2E G19.49h",
            materia: "MAT",
            version_label: "e2e",
            indirizzo: "sc",
            classe: "3",
            // Forza A_SOL + A_NOR + A_DSA + A_DIS
            nPrint: 1,
            nPrintDSA: 1,
            nPrintDIS: 1,
            dsa: true,
            force: true, // sovrascrivi se esiste già
        };

        const resp = await page.request.post("/api/verifica/save-tex-batch?force=1", {
            data: payload,
            headers: {
                "Content-Type":  "application/json",
                "X-CSRF-Token":  csrf,
                "Accept":        "application/json",
            },
            timeout: 60000,
        });
        expect(resp.status()).toBe(200);
        const body = await resp.json();
        expect(body.ok).toBe(true);
        expect(body.docs.length).toBeGreaterThanOrEqual(2);

        const batchId = body.batch_id;
        console.log(`Batch creato: ${batchId} con ${body.docs.length} varianti`);

        // Fetch .tex content per variant via /api/verifica/{id}/tex
        const docs = body.docs;
        const texByVariant = {};
        for (const d of docs) {
            const r = await page.request.get(`/api/verifica/${d.id}/tex`);
            const tex = await r.text();
            texByVariant[d.variant] = tex;
        }

        console.log("Variants generate:", Object.keys(texByVariant));

        // ─────── Asserzioni SOL ───────
        const sol = texByVariant["A_SOL"];
        expect(sol, "A_SOL non generato").toBeTruthy();
        // Badge libro PRESENTE in SOL
        expect(sol, "SOL deve contenere il publisher badge").toContain("Matematica multimediale.blu");
        expect(sol, "SOL deve contenere ZANICHELLI badge").toContain("ZANICHELLI");
        // Soluzione PRESENTE: G19.49j riquadro grigio centrato (no più "Soluzione:")
        expect(sol, "SOL deve contenere il riquadro Soluzione").toContain("\\fcolorbox{gray!50}{gray!10}{\\textbf{Soluzione}}");
        expect(sol, "SOL label A) maiuscola").toContain("label=\\textbf{\\Alph*)}");
        expect(sol, "SOL deve contenere la formula sol").toContain("4\\sqrt{19}");
        // Cerchi convertiti
        expect(sol, "SOL deve avere \\Circled, no \\enclose").not.toContain("\\enclose");
        expect(sol, "SOL deve avere \\Circled[inner color").toContain("\\Circled[inner color=");
        // No bbox/mathmakebox/lower
        expect(sol, "SOL no \\bbox").not.toMatch(/\\bbox\[/);
        expect(sol, "SOL no \\mathmakebox").not.toContain("\\mathmakebox");
        // bbox con background → \colorbox{COLOR}
        expect(sol, "SOL bbox[bg:green] → \\colorbox{green}").toContain("\\colorbox{green}");
        // Math delimiters convertiti
        expect(sol, "SOL no \\(").not.toMatch(/\\\(/);
        expect(sol, "SOL no \\)").not.toMatch(/\\\)/);

        // ─────── Asserzioni NOR ───────
        const nor = texByVariant["A_NOR"];
        expect(nor, "A_NOR non generato").toBeTruthy();
        // Badge libro ASSENTE in NOR
        expect(nor, "NOR NON deve contenere il publisher badge").not.toContain("Matematica multimediale.blu");
        expect(nor, "NOR NON deve contenere ZANICHELLI").not.toContain("ZANICHELLI");
        expect(nor, "NOR NON deve contenere Bergamini").not.toContain("Bergamini");
        // Soluzione ASSENTE (no inline sol; rule placeholder solo se item.includeSolution=true)
        expect(nor, "NOR NON deve contenere la formula soluzione").not.toContain("4\\sqrt{19}");
        // Cerchi convertiti anche qui
        expect(nor, "NOR \\Circled presente").toContain("\\Circled[inner color=");
        expect(nor, "NOR no \\enclose").not.toContain("\\enclose");
        // No bbox/mathmakebox
        expect(nor, "NOR no \\bbox").not.toMatch(/\\bbox\[/);
        expect(nor, "NOR no \\mathmakebox").not.toContain("\\mathmakebox");
        // Math delimiters
        expect(nor, "NOR no \\(").not.toMatch(/\\\(/);
        expect(nor, "NOR no \\)").not.toMatch(/\\\)/);
        // Testo problema preservato
        expect(nor, "NOR deve contenere il testo problema").toContain("trapezio");

        // ─────── Asserzioni DSA / DIS ───────
        for (const v of ["A_DSA", "A_DIS"]) {
            const t = texByVariant[v];
            if (!t) continue;
            expect(t, `${v} no badge`).not.toContain("Matematica multimediale.blu");
            expect(t, `${v} no soluzione`).not.toContain("4\\sqrt{19}");
            expect(t, `${v} no \\enclose`).not.toContain("\\enclose");
            expect(t, `${v} no \\(`).not.toMatch(/\\\(/);
        }

        console.log("✓ Tutte le asserzioni passate");
        console.log("--- Sample SOL (problema):", (sol.match(/trapezio[^$]*\$[^$]*\$/)?.[0] || "n/a").slice(0, 200));
        console.log("--- Sample NOR (problema):", (nor.match(/trapezio[^$]*\$[^$]*\$/)?.[0] || "n/a").slice(0, 200));
        // Dump dell'item con badge + cerchio per ispezione manuale
        const cIdx = sol.indexOf("\\colorbox");
        console.log("=== SOL excerpt around \\colorbox ===");
        console.log(sol.slice(Math.max(0, cIdx - 200), cIdx + 600));
        const ciIdx = sol.indexOf("\\Circled");
        console.log("=== SOL excerpt around \\Circled ===");
        console.log(sol.slice(Math.max(0, ciIdx - 200), ciIdx + 400));
    });
});
