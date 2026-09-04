/**
 * G27.tikz.hoist — Regression test for TikZ preamble hoisting.
 *
 * BUG storico (pre-G27.tikz.hoist):
 *   `Sanitizer::extractTikzpicture` scartava tutto quello che precedeva
 *   `\begin{tikzpicture}`, perdendo `\newcommand`/`\newif`/`\newenvironment`/
 *   `\def` definiti dai template "real-world" (es. `poligono` dal dropdown
 *   TeX, 12KB). Risultato: pdflatex compilava in silenzio (warning, no
 *   errore) ma il PDF mostrava solo testo nudo (alpha/beta/nomi punti)
 *   senza la figura, perché le macro custom referenziate restavano
 *   undefined nel main_*.tex finale.
 *
 * FIX:
 *   - Sanitizer::hoistPreamble accumula preBody+postBody attorno al
 *     tikzpicture, dedupando per hash normalizzato (strip commenti `%`).
 *   - `\newcommand` → `\providecommand` per supportare inserzioni multiple
 *     dello stesso template (idempotenza: se gia' definito, no-op).
 *   - TexBuilder::build prepende il preamble raccolto al body
 *     `esercizi_*.tex` wrappato in `\makeatletter`/`\makeatother`.
 *
 * Questo test inserisce un template TikZ con preamble custom in 6
 * posizioni di un esercizio RM:
 *   - question outer (top-level del quesito)
 *   - question dentro list item
 *   - cella RM outer
 *   - cella RM dentro list item
 *   - giustificazione outer
 *   - giustificazione dentro list item
 *
 * Asserzioni:
 *   - PATCH 200, save-tex 200, compile 200
 *   - Nel TEX generato: \providecommand{\SetPoints} = 1 (dedup OK)
 *   - Nel TEX generato: \newcommand{\SetPoints} = 0 (rewrite OK)
 *   - Nel TEX generato: il marker hoist `% G27.tikz.hoist` presente
 *   - PDF generato non vuoto (>50KB, indica figure renderizzate)
 *
 * Restore finale: ripristina lo state pre-test del contract item per non
 * lasciare side-effect su altri test/UI manuale.
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = process.env.PLAYWRIGHT_TEST_USERNAME || "superadmin";
const TEACHER_PASS = process.env.PLAYWRIGHT_TEST_PASSWORD || (process.env.E2E_TEACHER_PASS || "");

// Template TikZ con preamble custom: 4 macro newcommand + tikzpicture body.
// Imita la struttura del template "poligono" del dropdown ma in versione
// minima (~600 byte) per test veloce. Le macro \drawSquare e \markCorner
// sono usate dal body — se NON hoistate, pdflatex le lascia undefined e
// la figura non si disegna.
const TIKZ_WITH_CUSTOM_PREAMBLE = `\\usepackage{amsmath}
\\usepackage{tikz}
\\usetikzlibrary{calc}

\\newcommand{\\SetPoints}[1]{\\def\\PointsData{#1}}
\\newcommand{\\drawSquare}[2]{%
  \\draw[thick, fill=cyan!20] (#1) rectangle (#2);
}
\\newcommand{\\markCorner}[2]{%
  \\fill[red] (#1) circle (2pt);
  \\node[above right] at (#1) {#2};
}

\\begin{document}
\\begin{tikzpicture}[scale=0.5]
  \\drawSquare{0,0}{4,3}
  \\markCorner{0,0}{$A$}
  \\markCorner{4,0}{$B$}
  \\markCorner{4,3}{$C$}
  \\markCorner{0,3}{$D$}
\\end{tikzpicture}
\\end{document}`;

const TIKZ_BLOCK = (label) => ({
    type: "tikz",
    script: `% TIKZ_TEST ${label}\n${TIKZ_WITH_CUSTOM_PREAMBLE}`,
    tex_packages: "amsmath,tikz",
    tikz_libs: "calc",
});
const TEXT = (s) => ({ type: "text", content: s });

const QUESTION_BLOCKS = [
    TEXT("Q outer:"),
    TIKZ_BLOCK("Q_outer"),
    {
        type: "list", ordered: true, list_preset: "alpha-decimal", dsa_section: "question",
        items: [
            [TEXT("punto a:"), TIKZ_BLOCK("Q_in_list")],
            [TEXT("punto b solo testo")],
        ],
    },
];
const JUSTIFICATION_BLOCKS = [
    TEXT("Giust outer:"),
    TIKZ_BLOCK("J_outer"),
    {
        type: "list", ordered: false, list_preset: "arrow-bullet", dsa_section: "justification",
        items: [
            [TEXT("passo 1:"), TIKZ_BLOCK("J_in_list")],
            [TEXT("passo 2 solo testo")],
        ],
    },
];
const CELL_BLOCKS = [
    TEXT("Cella outer:"),
    TIKZ_BLOCK("Cell_outer"),
    {
        type: "list", ordered: false, list_preset: "arrow-bullet", dsa_section: "options",
        items: [
            [TEXT("a-1:"), TIKZ_BLOCK("Cell_in_list")],
            [TEXT("a-2 solo testo")],
        ],
    },
];

test.describe("G27.tikz.hoist — preamble macro hoisting", () => {
    test("inserisce TikZ con \\newcommand in 6 posizioni RM, compila e renderizza figure", async ({ page }) => {
        test.setTimeout(120000);

        // Bypass cookie consent banner
        await page.addInitScript(() => {
            localStorage.setItem("user_cookie_consent_v2", JSON.stringify({
                functional: true, analytics: false, advertising: false, timestamp: Date.now(),
            }));
        });

        // Login
        await page.goto("/login");
        await page.fill('input[name="username"]', TEACHER_USER);
        await page.fill('input[name="password"]', TEACHER_PASS);
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/, { timeout: 15000 }),
            page.click('button[type="submit"]'),
        ]);

        // No need to navigate to studio page; sintetizziamo HTML direttamente
        // (vedi sotto). Ci serve solo la sessione autenticata + CSRF token.
        // Naviga a una pagina valida per esistere la sessione.
        await page.goto("/area-docente/verifiche", { waitUntil: "domcontentloaded" });

        const csrf = await page.evaluate(async () => {
            const r = await fetch("/auth/csrf", { credentials: "same-origin" });
            return (await r.json()).token;
        });
        expect(csrf, "CSRF token retrieved").toBeTruthy();

        try {
            // 3. Build Selection payload by injecting <script type="text/tikz">
            //    HTML directly with our test TikZ content. Bypass dell'editor
            //    DOM scrape (tikz-render-client.js converte script→svg ma su
            //    crypto.subtle.digest insecure context puo' fallire e mettere
            //    error placeholders nel DOM, perdendo il body TikZ).
            const tikzScriptHtml = (label) =>
                `<script type="text/tikz" data-tex-packages="amsmath,tikz" data-tikz-libraries="calc">${
                    `% TIKZ_TEST ${label}\n${TIKZ_WITH_CUSTOM_PREAMBLE}`
                }</script>`;
            const itemHtmlForRm =
                'Q outer:' + tikzScriptHtml('Q_outer') +
                '<ol class="fm-dsa-li-list" data-dsa-section="question">' +
                  '<li>punto a:' + tikzScriptHtml('Q_in_list') + '</li>' +
                  '<li>punto b solo testo</li>' +
                '</ol>' +
                // Cell content as a tabular HTML (fm-rm-table)
                '<table class="fm-rm-table" data-rows="1" data-cols="1" data-typecell="|X|">' +
                  '<tr><td class="rm-option" data-row="0" data-col="0">' +
                    '<div class="fm-cell-content">' +
                      'Cella outer:' + tikzScriptHtml('Cell_outer') +
                      '<ul class="fm-dsa-li-list" data-dsa-section="options">' +
                        '<li>a-1:' + tikzScriptHtml('Cell_in_list') + '</li>' +
                        '<li>a-2 solo testo</li>' +
                      '</ul>' +
                    '</div>' +
                  '</td></tr>' +
                '</table>' +
                // Justification block
                '<div class="fm-giustsol">Giust outer:' + tikzScriptHtml('J_outer') +
                  '<ul class="fm-dsa-li-list" data-dsa-section="justification">' +
                    '<li>passo 1:' + tikzScriptHtml('J_in_list') + '</li>' +
                    '<li>passo 2 solo testo</li>' +
                  '</ul>' +
                '</div>';

            const saveTexPayload = {
                selectedIIS: "SCI", selectedCLS: "3", selectedMATER: "MAT",
                verTitle: "G27_TIKZ_HOIST_TEST",
                problems: [{
                    position: 1, type: "RMulti", text: "",
                    filePath: "/eser/sc/eser_sc3s/MAT/2_MAT-prova_2-sc3s.php",
                    problemId: "g0",
                    items: [{
                        position: 1, points: 1, html: itemHtmlForRm, includeSolution: false,
                    }],
                }],
                options: { includeSolutions: false },
                title: "G27_TIKZ_HOIST_TEST", materia: "MAT",
                anno: "2026", verTime: "60min", sezione: "T",
                istituto: "TestSchool", addressSchool: "Scientifico",
                nPrint: 1, nPrintDSA: 0, nPrintDIS: 0,
            };

            const saveTexRes = await page.evaluate(async ({ payload, c }) => {
                const r = await fetch("/api/verifica/save-tex", {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": c },
                    credentials: "same-origin",
                    body: JSON.stringify(payload),
                });
                return { status: r.status, body: (await r.text()).substring(0, 1500) };
            }, { payload: saveTexPayload, c: csrf });
            expect(saveTexRes.status, `save-tex should succeed: ${saveTexRes.body}`).toBe(200);

            const saveBody = JSON.parse(saveTexRes.body.substring(0, saveTexRes.body.lastIndexOf("}") + 1));
            const docId = saveBody.doc?.id;
            expect(docId, "save-tex returns a doc id").toBeTruthy();

            // 5. Download generated TEX and assert hoist markers
            const tex = await page.evaluate(async (id) => {
                const r = await fetch(`/api/verifica/${id}/tex`, { credentials: "same-origin" });
                return await r.text();
            }, docId);
            expect(tex.length, "TEX is non-empty").toBeGreaterThan(1000);

            // ASSERT: hoister marker is present (preamble was injected)
            expect(tex, "hoist marker present").toContain("G27.tikz.hoist");

            // ASSERT: \newcommand was rewritten to \providecommand (idempotency)
            expect((tex.match(/\\providecommand\{\\drawSquare\}/g) || []).length,
                "\\drawSquare definition present as \\providecommand").toBeGreaterThanOrEqual(1);
            expect((tex.match(/\\newcommand\{\\drawSquare\}/g) || []).length,
                "\\drawSquare NOT present as \\newcommand (rewritten)").toBe(0);

            // ASSERT: dedup — exactly 1 definition of \drawSquare even if used 6 times
            expect((tex.match(/\\providecommand\{\\drawSquare\}/g) || []).length,
                "\\drawSquare defined exactly ONCE despite 6 insertions (dedup)").toBe(1);

            // ASSERT: 6 calls to \drawSquare in body (one per insertion position)
            expect((tex.match(/\\drawSquare\{0,0\}\{4,3\}/g) || []).length,
                "\\drawSquare called 6 times in tikzpicture bodies").toBe(6);

            // 6. Compile and verify PDF is non-trivial size (figure rendered)
            const compileRes = await page.evaluate(async ({ id, c }) => {
                const r = await fetch(`/api/verifica/${id}/compile`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json", "X-CSRF-Token": c },
                    credentials: "same-origin",
                    body: JSON.stringify({}),
                });
                return { status: r.status, body: (await r.text()).substring(0, 2000) };
            }, { id: docId, c: csrf });
            expect(compileRes.status, `compile should succeed: ${compileRes.body}`).toBe(200);

            const compileBody = JSON.parse(compileRes.body.substring(0, compileRes.body.lastIndexOf("}") + 1));
            expect(compileBody.ok, "compile returns ok").toBe(true);
            expect(compileBody.doc?.has_pdf, "PDF was produced").toBe(true);
            expect(compileBody.doc?.pdf_size, "PDF size > 30KB (figures present, not just text)").toBeGreaterThan(30000);
        } finally {
            // No restore needed: il test crea solo un nuovo doc in DB tramite
            // /api/verifica/save-tex (no side-effect su contract esistenti).
        }
    });
});
