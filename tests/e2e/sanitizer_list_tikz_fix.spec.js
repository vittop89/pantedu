/**
 * E2E test del fix Sanitizer (list <ul>/<ol>/<li> + TikZ <svg data-tikz-body>)
 * + integrazione latexindent in TexBuilder.buildFlat.
 *
 * Pipeline coperta:
 *   POST /api/verifica/save-tex
 *     → Selection.fromArray
 *     → TexBuilder.buildFlat (Sanitizer + format VPS)
 *     → readTex via /api/verifica/{id}/tex
 *
 * Asserzioni TEX prodotto:
 *   - 4 \item da una lista <ol> ordered con 4 <li>
 *   - \begin{tikzpicture}...\end{tikzpicture} preservato (no <svg> residue)
 *   - DSA UI residues strippati (fm-dsa-li-buttons)
 *   - HTML entities decodate
 *   - Niente preamble \usepackage/\begin{document} dentro tikzpicture
 *   - Indentazione (latexindent applicato): \item indented dentro environment
 *
 * Side: salva il TEX e PDF compilato in tests/e2e-results/ per review visuale.
 */
const { test, expect } = require("@playwright/test");
const fs = require("fs");
const path = require("path");

const USERNAME = process.env.FM_E2E_USER || "superadmin";
const PASSWORD = process.env.FM_E2E_PASS || (process.env.E2E_TEACHER_PASS || "");

// HTML simil-quello che ContractRenderer produce per il problema lavatrice:
// 3 text/latex blocks + un blocco list ordered con 4 <li>, + un blocco TikZ
// post-render (svg data-tikz-body URL-encoded).
const TIKZ_BODY = "\\begin{tikzpicture}\n  \\foreach \\i in {1,...,5} {\n    \\ifnum\\i<3 \\draw (\\i,0) circle (1pt); \\fi\n  }\n  \\draw[->] (-1,0) -- (5,0);\n\\end{tikzpicture}";
const TIKZ_BODY_URL = encodeURIComponent(TIKZ_BODY);

const PROBLEM_HTML = `<span class="fm-text">Il prezzo </span><span class="fm-latex">$P$</span><span class="fm-text"> per la riparazione di una lavatrice prevede che al costo del pezzo da sostituire vengano aggiunti 35 € fissi per la chiamata. Se si deve sostituire un pezzo che costa 62 €,</span><ol class="fm-dsa-li-list"><li data-fm-dsa-state=""><span class="fm-dsa-li-buttons" aria-label="Marca DSA"><button type="button" class="fm-dsa-li-btn fm-dsa-li-F" data-mark="F">F</button><button type="button" class="fm-dsa-li-btn fm-dsa-li-GF" data-mark="GF">GF</button></span><span class="fm-dsa-li-num">1.</span><span class="fm-dsa-li-content">scrivi la legge che esprime $P$ in funzione delle ore $t$ di manodopera</span></li><li><span class="fm-dsa-li-buttons">F GF</span><span class="fm-dsa-li-num">2.</span><span class="fm-dsa-li-content">rappresentala nel piano cartesiano.</span></li><li><span class="fm-dsa-li-buttons">F GF</span><span class="fm-dsa-li-num">3.</span><span class="fm-dsa-li-content">A quanto ammonta $P$ se il tecnico ha lavorato per un'ora e mezza?</span></li><li><span class="fm-dsa-li-buttons">F GF</span><span class="fm-dsa-li-num">4.</span><span class="fm-dsa-li-content">Quante ore ha impiegato il tecnico se il prezzo della riparazione &gt; 179,50 €?</span></li></ol><svg data-tikz-hash="abc" data-tikz-body="${TIKZ_BODY_URL}" xmlns="http://www.w3.org/2000/svg"><path d="M0 0"/></svg>`;

test.describe("Sanitizer + latexindent E2E (real backend)", () => {
    test("verifica lavatrice: list 4-items + tikzpicture preservati nel TEX e nel PDF", async ({ page }) => {
        // 1. Login
        await page.goto("/login");
        await page.locator("input[name=username]").fill(USERNAME);
        await page.locator("input[name=password]").fill(PASSWORD);
        await page.locator("button[type=submit]").first().click();
        await page.waitForLoadState("networkidle");

        const csrf = await page.request.get("/auth/csrf").then(r => r.json()).then(j => j.token);

        // 2. POST /api/verifica/save-tex (variante NOR — niente badge SOL)
        const payload = {
            verTitle: "TestE2E_Sanitizer_Lavatrice",
            selectedIIS: "sc",
            selectedCLS: "1",
            selectedMATER: "MAT",
            anno: "2026",
            sezione: "NOR",
            variant: "normal",
            problems: [{
                filePath: "/test/e2e/lavatrice",
                problemId: "type_Collect_lavatrice",
                position: 1,
                type: "Collect",
                text: "Risolvi il seguente problema.",
                items: [{
                    html: PROBLEM_HTML,
                    solution: "",
                    points: 1.0,
                    includeSolution: false,
                }],
            }],
            options: { includeTitlePage: true, includeSolutions: false },
            title: "TestE2E_Sanitizer_Lavatrice",
            materia: "MAT",
            version_label: "e2e_sanitizer",
            indirizzo: "sc",
            classe: "1",
        };

        const resp = await page.request.post("/api/verifica/save-tex", {
            data: payload,
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": csrf,
                "Accept": "application/json",
            },
            timeout: 60000,
        });
        expect(resp.status(), "save-tex must return 200").toBe(200);
        const body = await resp.json();
        expect(body.ok, JSON.stringify(body)).toBe(true);
        const docId = body.doc?.id;
        expect(docId, "doc id must be returned").toBeTruthy();
        console.log(`save-tex OK doc=${docId}`);

        // 3. GET /api/verifica/{id}/tex → verifica contenuto
        const texResp = await page.request.get(`/api/verifica/${docId}/tex`);
        expect(texResp.status()).toBe(200);
        const tex = await texResp.text();
        const outDir = "tests/e2e-results";
        fs.mkdirSync(outDir, { recursive: true });
        fs.writeFileSync(path.join(outDir, `lavatrice_doc_${docId}.tex`), tex);

        console.log(`TEX length: ${tex.length} bytes`);

        // 4. Asserzioni LISTA (4 \item da <ol> 4-items)
        const itemCount = (tex.match(/\\item\b/g) || []).length;
        expect(itemCount, "expected at least 5 \\item (1 outer + 4 inner)").toBeGreaterThanOrEqual(5);
        expect(tex, "must contain \\begin{enumerate}").toMatch(/\\begin\{enumerate\}/);
        expect(tex, "must contain content of each list item").toContain("scrivi la legge");
        expect(tex, "rappresentala").toContain("rappresentala");
        expect(tex, "ammonta P").toContain("ammonta");
        expect(tex, "Quante ore").toContain("Quante ore");

        // 5. Asserzioni TikZ (preservato + body inline)
        expect(tex, "must contain \\begin{tikzpicture}").toMatch(/\\begin\{tikzpicture\}/);
        expect(tex, "tikz body \\foreach").toContain("\\foreach");
        expect(tex, "tikz body \\ifnum<").toMatch(/\\ifnum\\i\s*<\s*3/);
        expect(tex, "no svg residual").not.toMatch(/<svg/);

        // 6. Asserzioni cleanliness (HTML strippato + entities decodate + DSA stripped)
        expect(tex, "no raw <ol>").not.toMatch(/<ol[\s>]/);
        expect(tex, "no raw <li>").not.toMatch(/<li[\s>]/);
        expect(tex, "no <span> con fm-dsa-li-buttons leak").not.toContain("fm-dsa-li-buttons");
        expect(tex, "&gt; entity decodato").not.toMatch(/&(gt|lt|amp|nbsp);/);
        expect(tex, "no &gt; literal in TEX (ma > letterale ok)").toContain("> 179,50");

        // 7. Asserzioni preamble TikZ NON leakato in body
        // (deve esserci nel preambolo del main_NOR ma NON dentro gli environment)
        const inTikzMatch = tex.match(/\\begin\{tikzpicture\}[\s\S]*?\\end\{tikzpicture\}/);
        expect(inTikzMatch, "tikzpicture trovato").toBeTruthy();
        const tikzInner = inTikzMatch[0];
        expect(tikzInner, "no \\usepackage dentro tikzpicture").not.toMatch(/\\usepackage\b/);
        expect(tikzInner, "no \\begin{document} dentro tikzpicture").not.toMatch(/\\begin\{document\}/);

        // 8. Asserzione latexindent (indentazione dentro environment)
        // Cerca almeno un \item indentato (tab/spazi prima)
        expect(tex, "almeno un \\item indentato").toMatch(/\n[\t ]+\\item/);

        // 9. Compile PDF via VPS e salva (per review visuale)
        // Se compilePdf endpoint esposto sul backend richiede CSRF/auth.
        const compileResp = await page.request.post(`/api/verifica/${docId}/compile-pdf`, {
            headers: { "X-CSRF-Token": csrf, "Accept": "application/json" },
            timeout: 60000,
        }).catch(() => null);
        if (compileResp && compileResp.status() === 200) {
            const cr = await compileResp.json();
            if (cr?.ok && cr?.pdf_b64) {
                fs.writeFileSync(path.join(outDir, `lavatrice_doc_${docId}.pdf`), Buffer.from(cr.pdf_b64, "base64"));
                console.log(`PDF saved: ${outDir}/lavatrice_doc_${docId}.pdf`);
            }
        }

        console.log("✓ Sanitizer + latexindent E2E passed");
    });
});
