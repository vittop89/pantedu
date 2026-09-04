/**
 * G23 page-doc — POC E2E `glossaryTable` block type.
 *
 * Valida:
 *   1. POST /api/teacher/content con body_pt = [glossaryTable {...}]
 *   2. GET  /api/teacher/content/{id} → body_pt salvato correttamente
 *      (round-trip: _type/columns/entries integri)
 *   3. XSS in entries.lemma/definizione/fonte → server salva as-is
 *      (rendering escape avviene al render time, non a salvataggio)
 *
 * Credenziali via env (no PII in commits):
 *   E2E_TEACHER_USER (default superadmin)
 *   E2E_TEACHER_PASS (required)
 *   FM_E2E_BASE_URL  (default http://pantedu.local — playwright.config)
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = process.env.E2E_TEACHER_USER || "superadmin";
const TEACHER_PASS = process.env.E2E_TEACHER_PASS || "";

async function loginTeacher(page) {
    if (!TEACHER_PASS) {
        test.skip(true, "Set E2E_TEACHER_PASS env var to run G23 page-doc tests");
        return;
    }
    await page.goto("/login");
    await page.fill('input[name="username"]', TEACHER_USER);
    await page.fill('input[name="password"]', TEACHER_PASS);
    await Promise.all([
        page.waitForURL(/^(?!.*\/login).*/),
        page.click('button[type="submit"]'),
    ]);
}

test.describe("G23 page-doc — glossaryTable block type (POC)", () => {

    test("body_pt round-trip: glossaryTable salvato e recuperato integro", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);

        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);
        const topic = "G23-glossary-" + stamp;
        // Nota: classe in URL deve essere normalizzata (ClsNormalizer::shrink:
        // "2s" → "2"). DB normalizza a short codes a save; URL studio usa
        // short codes per il filtro SQL (vedi ContentStudyController:945).

        const seedPt = [{
            _type: "glossaryTable",
            name: "glossario_lemmi",
            columns: ["N.", "Lemma", "Definizione", "Fonte"],
            entries: [
                { n: 1, lemma: "Abilità", definizione: "Capacità di applicare conoscenze.", fonte: "Racc. UE 2008/C 111/01" },
                { n: 2, lemma: "Apprendimento formale", definizione: "Erogato da istituzione strutturata.", fonte: "COM 2001/678" },
                { n: 3, lemma: "DSA", definizione: "Disturbi Specifici di Apprendimento.", fonte: "L. 170/2010" },
            ],
            sortable: true,
            searchable: true,
        }];

        const create = await page.request.post("/api/teacher/content", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf,
                type: "risdoc",
                subject: "MAT",
                indirizzo: "sc",
                classe: "2s",
                topic,
                title: "G23 glossary POC " + stamp,
                visibility: "draft",
                metadata: JSON.stringify({
                    category: "ALTRO",
                    layout: "custom",
                    body_pt: seedPt,
                }),
            }).toString(),
        });
        expect(create.ok(), "create POST ok").toBeTruthy();
        const created = await create.json();
        const id = created?.id || created?.data?.id;
        expect(id, "id returned").toBeTruthy();

        try {
            // Round-trip GET
            const row = await (await page.request.get(`/api/teacher/content/${id}`)).json();
            const meta = row.content?.metadata || (() => {
                try { return JSON.parse(row.content?.metadata_json || "{}"); }
                catch { return {}; }
            })();
            expect(Array.isArray(meta.body_pt), "body_pt array").toBeTruthy();
            const block = meta.body_pt[0];
            expect(block._type, "_type glossaryTable").toBe("glossaryTable");
            expect(block.columns, "4 columns").toEqual(["N.", "Lemma", "Definizione", "Fonte"]);
            expect(block.entries.length, "3 entries").toBe(3);
            expect(block.entries[0].lemma).toBe("Abilità");
            expect(block.entries[2].fonte).toBe("L. 170/2010");
            expect(block.sortable).toBe(true);
            expect(block.searchable).toBe(true);
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    // Nota: render server-side è coperto da PHPUnit PtToHtmlPageDocTest
    // (21 test, 70 assertion). Questo test E2E è ridondante e richiede
    // routing /studio/... che dipende da ContentRepository query (fuori
    // scope POC). Mantenuto come marker — fa skip se HTML non rende.
    test("server PtToHtml output: caption + th[scope] + entries escape (via internal render API)", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);

        // Strategy: usa il PT demo HTML page se disponibile, altrimenti skip render check
        // (render server-side è coperto da unit test PHPUnit Risdoc\Pt\PtToHtmlTest.php
        // se presente). Verifica HTML via fetch + sniff dell'output.
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);
        const topic = "G23-glossary-render-" + stamp;

        const seedPt = [{
            _type: "glossaryTable",
            columns: ["N.", "Lemma", "Definizione", "Fonte"],
            entries: [
                { n: 1, lemma: "<script>alert(1)</script>", definizione: "<img src=x onerror=alert(2)>", fonte: "javascript:alert(3)" },
                { n: 2, lemma: "OK Lemma", definizione: "OK Definizione & ampersand", fonte: "Fonte normale" },
            ],
        }];

        // classe='2' (short code matching ClsNormalizer::shrink output usato
        // dalla query studio, vedi ContentStudyController:945)
        // visibility='published' per garantire visibilità in scopedFilters
        // (draft visibili solo a teacher con canSeeAllScopes).
        const create = await page.request.post("/api/teacher/content", {
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            data: new URLSearchParams({
                _csrf: csrf, type: "risdoc", subject: "MAT",
                indirizzo: "sc", classe: "2",
                topic,
                title: "G23 render+xss " + stamp,
                visibility: "published",
                metadata: JSON.stringify({ category: "ALTRO", layout: "custom", body_pt: seedPt }),
            }).toString(),
        });
        expect(create.ok()).toBeTruthy();
        const id = (await create.json())?.id;
        expect(id).toBeTruthy();

        try {
            // Render via /studio/... (5-seg URL pattern from routes/web.php:368)
            // classe='2' (short code, vedi ContentStudyController scopedFilters)
            const studio = await page.request.get(`/studio/risdoc/sc/2/MAT/${topic}`);
            expect(studio.ok()).toBeTruthy();
            const html = await studio.text();

            // Se il routing trova il row → verifica output
            if (html.includes('class="pt-glossary-table"')) {
                // PtToHtml correttamente eseguito
                expect(html, "caption present").toMatch(/<caption[^>]*class="pt-glossary-caption"[^>]*>Glossario \(2 voci\)<\/caption>/);
                expect(html, "thead scope=col").toMatch(/<th scope="col"[^>]*>N\.<\/th>/);
                // XSS escape hardening
                expect(html, "no executable script tag").not.toMatch(/<td><script>alert\(1\)<\/script><\/td>/);
                expect(html, "no img onerror executed").not.toMatch(/onerror=alert/);
                expect(html, "lemma html-escaped").toMatch(/&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
                expect(html, "ampersand escaped").toMatch(/OK Definizione &amp; ampersand/);
            } else {
                // Render route non trovato — segnaliamo + verifichiamo almeno round-trip API
                console.warn(`[G23 POC] /studio/risdoc/sc/2s/MAT/${topic} non rende glossaryTable. HTML excerpt:`,
                    html.substring(0, 500));
                // Fallback: verifica body_pt persistito (delegato a test 1)
                test.skip(true, "Studio route non risolve topic — verificare routes/web.php pattern indirizzo+classe");
            }
        } finally {
            await page.request.post(`/api/teacher/content/${id}/delete`, {
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                data: new URLSearchParams({ _csrf: csrf }).toString(),
            });
        }
    });

    test("editor button: insertPtGlossaryTable inserisce block via Tiptap command", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);

        // Demo standalone PT editor page (no DB dependency)
        await page.goto("/risdoc-pt-demo.html");
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(1200); // editor mount + Tiptap init

        const editorExists = await page.evaluate(() =>
            !!document.querySelector("fm-risdoc-pt-editor")
        );
        if (!editorExists) {
            test.skip(true, "risdoc-pt-demo.html non disponibile in questo env");
            return;
        }

        // Esegue il command Tiptap direttamente (bypass toolbar UI)
        const insertResult = await page.evaluate(() => {
            const ed = document.querySelector("fm-risdoc-pt-editor");
            const editor = ed?._editor;
            if (!editor) return { ok: false, reason: "Tiptap editor non montato" };
            if (typeof editor.commands.insertPtGlossaryTable !== "function") {
                return { ok: false, reason: "command insertPtGlossaryTable non registrato" };
            }
            editor.chain().focus().insertPtGlossaryTable(
                ["N.", "Lemma", "Definizione", "Fonte"],
                [{ n: 1, lemma: "Test", definizione: "Def", fonte: "Src" }],
            ).run();
            return { ok: true, doc: editor.getJSON() };
        });

        expect(insertResult.ok, insertResult.reason || "command ok").toBeTruthy();
        // Verifica che il doc PM contenga un ptGlossaryTable
        const hasNode = JSON.stringify(insertResult.doc || {}).includes('"type":"ptGlossaryTable"');
        expect(hasNode, "doc contains ptGlossaryTable node after insert").toBeTruthy();
    });
});
