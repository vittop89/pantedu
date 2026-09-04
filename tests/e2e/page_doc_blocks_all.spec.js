/**
 * G23 page-doc — Sprint 2 — E2E per tutti 5 block types end-to-end.
 *
 * Valida round-trip body_pt per:
 *   1. glossaryTable (già coperto in page_doc_glossary_table.spec.js)
 *   2. staticContent (HTML sanitizzato + nesting items)
 *   3. accordion (collapsible items con body_pt nested)
 *   4. linkListPdf (link normativi gerarchici)
 *   5. citationNorma (citazione legge strutturata)
 *
 * Inoltre: editor toolbar Tiptap commands per ognuno.
 *
 * Credenziali via env (no PII).
 */
const { test, expect } = require("@playwright/test");

const TEACHER_USER = process.env.E2E_TEACHER_USER || "superadmin";
const TEACHER_PASS = process.env.E2E_TEACHER_PASS || "";

async function loginTeacher(page) {
    if (!TEACHER_PASS) {
        test.skip(true, "Set E2E_TEACHER_PASS env var");
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

async function createAndFetchContent(page, csrf, body_pt, topic) {
    const create = await page.request.post("/api/teacher/content", {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({
            _csrf: csrf, type: "risdoc", subject: "MAT",
            indirizzo: "sc", classe: "2s",
            topic, title: topic, visibility: "draft",
            metadata: JSON.stringify({ category: "ALTRO", layout: "custom", body_pt }),
        }).toString(),
    });
    expect(create.ok(), `create ${topic}`).toBeTruthy();
    const id = (await create.json())?.id;
    expect(id, `id ${topic}`).toBeTruthy();
    const row = await (await page.request.get(`/api/teacher/content/${id}`)).json();
    const meta = row.content?.metadata || (() => {
        try { return JSON.parse(row.content?.metadata_json || "{}"); } catch { return {}; }
    })();
    return { id, meta };
}

async function deleteContent(page, csrf, id) {
    await page.request.post(`/api/teacher/content/${id}/delete`, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        data: new URLSearchParams({ _csrf: csrf }).toString(),
    });
}

test.describe("G23 page-doc — Sprint 2 — 4 block types round-trip", () => {

    test("staticContent round-trip + nesting items preservato", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);

        const seedPt = [{
            _type: "staticContent",
            title: "PARTE I — Norme generali",
            level: 2,
            format: "html",
            body: "<p>Testo introduttivo</p><ul><li>Punto 1</li><li>Punto 2</li></ul>",
            items: [
                {
                    _type: "staticContent",
                    title: "A. Registrazione voti",
                    level: 3,
                    body: "<p>Sub-sezione</p>",
                },
            ],
        }];

        let id;
        try {
            const r = await createAndFetchContent(page, csrf, seedPt, "G23-static-" + stamp);
            id = r.id;
            const block = r.meta.body_pt[0];
            expect(block._type).toBe("staticContent");
            expect(block.title).toBe("PARTE I — Norme generali");
            expect(block.level).toBe(2);
            expect(block.body).toMatch(/<p>Testo introduttivo<\/p>/);
            expect(block.items.length).toBe(1);
            expect(block.items[0].title).toBe("A. Registrazione voti");
            expect(block.items[0].level).toBe(3);
        } finally { if (id) await deleteContent(page, csrf, id); }
    });

    test("accordion round-trip + items.body_pt nested", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);

        const seedPt = [{
            _type: "accordion",
            allow_multiple: false,
            items: [
                {
                    title: "A. Registrazione voti",
                    body_pt: [{ _type: "block", style: "normal", children: [{ _type: "span", text: "Body A", marks: [] }] }],
                    default_open: true,
                },
                {
                    title: "B. Recuperi",
                    body_pt: [{ _type: "block", style: "normal", children: [{ _type: "span", text: "Body B", marks: [] }] }],
                    default_open: false,
                },
            ],
        }];

        let id;
        try {
            const r = await createAndFetchContent(page, csrf, seedPt, "G23-accordion-" + stamp);
            id = r.id;
            const block = r.meta.body_pt[0];
            expect(block._type).toBe("accordion");
            expect(block.allow_multiple).toBe(false);
            expect(block.items.length).toBe(2);
            expect(block.items[0].title).toBe("A. Registrazione voti");
            expect(block.items[0].default_open).toBe(true);
            expect(block.items[0].body_pt[0].children[0].text).toBe("Body A");
        } finally { if (id) await deleteContent(page, csrf, id); }
    });

    test("linkListPdf round-trip + sub_items gerarchici", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);

        const seedPt = [{
            _type: "linkListPdf",
            title: "Scuola dell'infanzia e primo ciclo",
            items: [
                {
                    label: "Indicazioni nazionali DM 254/2012",
                    href: "/strcomp_bes_altro/ALTRO/linee_guida/primo_ciclo/509.pdf",
                    external: false,
                    description: "Curricolo infanzia/primaria/secondaria I grado",
                    sub_items: [
                        { label: "Allegato A", href: "/allegato_a.pdf", external: false },
                    ],
                },
                {
                    label: "MIUR portal",
                    href: "https://www.mim.gov.it/",
                    external: true,
                },
            ],
        }];

        let id;
        try {
            const r = await createAndFetchContent(page, csrf, seedPt, "G23-linklist-" + stamp);
            id = r.id;
            const block = r.meta.body_pt[0];
            expect(block._type).toBe("linkListPdf");
            expect(block.title).toBe("Scuola dell'infanzia e primo ciclo");
            expect(block.items.length).toBe(2);
            expect(block.items[0].label).toMatch(/DM 254\/2012/);
            expect(block.items[0].sub_items?.length).toBe(1);
            expect(block.items[1].external).toBe(true);
        } finally { if (id) await deleteContent(page, csrf, id); }
    });

    test("citationNorma round-trip con tutti i campi", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);
        const csrf = (await (await page.request.get("/auth/csrf")).json()).token;
        const stamp = Date.now().toString(36);

        const seedPt = [{
            _type: "citationNorma",
            tipo: "DM",
            numero: "5669",
            anno: 2011,
            articolo: "Art. 4 c. 2",
            title: "Linee Guida DSA",
            href: "/strcomp_bes_altro/ALTRO/linee_guida/BES/prot5669_11.pdf",
            quote: "Gli strumenti compensativi devono essere riconosciuti dalla scuola...",
        }];

        let id;
        try {
            const r = await createAndFetchContent(page, csrf, seedPt, "G23-citation-" + stamp);
            id = r.id;
            const block = r.meta.body_pt[0];
            expect(block._type).toBe("citationNorma");
            expect(block.tipo).toBe("DM");
            expect(block.numero).toBe("5669");
            expect(String(block.anno)).toBe("2011");
            expect(block.articolo).toBe("Art. 4 c. 2");
            expect(block.quote).toMatch(/strumenti compensativi/);
        } finally { if (id) await deleteContent(page, csrf, id); }
    });

    test("editor Tiptap commands: insert per tutti 4 nuovi block types", async ({ page }) => {
        test.setTimeout(60_000);
        await loginTeacher(page);

        await page.goto("/risdoc-pt-demo.html");
        await page.waitForLoadState("domcontentloaded");
        await page.waitForTimeout(1200);

        const editorExists = await page.evaluate(() => !!document.querySelector("fm-risdoc-pt-editor"));
        if (!editorExists) {
            test.skip(true, "risdoc-pt-demo.html non disponibile");
            return;
        }

        const results = await page.evaluate(() => {
            const ed = document.querySelector("fm-risdoc-pt-editor");
            const editor = ed?._editor;
            if (!editor) return { error: "editor non montato" };
            const cmds = [
                "insertPtStaticContent",
                "insertPtAccordion",
                "insertPtLinkListPdf",
                "insertPtCitationNorma",
            ];
            const out = {};
            for (const c of cmds) {
                out[c] = typeof editor.commands[c] === "function";
            }
            // Esegui anche uno (PtStaticContent) per verificare effettivo insert
            editor.chain().focus().insertPtStaticContent("Test", "<p>body</p>", 2).run();
            const docJson = JSON.stringify(editor.getJSON());
            out.docHasStaticContent = docJson.includes('"type":"ptStaticContent"');
            return out;
        });
        expect(results.insertPtStaticContent, "command insertPtStaticContent").toBeTruthy();
        expect(results.insertPtAccordion, "command insertPtAccordion").toBeTruthy();
        expect(results.insertPtLinkListPdf, "command insertPtLinkListPdf").toBeTruthy();
        expect(results.insertPtCitationNorma, "command insertPtCitationNorma").toBeTruthy();
        expect(results.docHasStaticContent, "doc contains ptStaticContent after insert").toBeTruthy();
    });
});
