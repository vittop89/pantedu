/**
 * E2E (Phase 13) — multi-materia, multi-tipo content per docente.
 *
 *   - POST /api/teacher/subjects     → crea materia custom (es. CHI)
 *   - GET  /api/teacher/subjects     → lista materie del docente
 *   - POST /api/teacher/content      → crea content (mappa/esercizio/lab/verifica)
 *   - GET  /api/teacher/content      → lista content del docente
 *   - POST /api/teacher/content/{id}/publish → visibility=published
 *   - GET  /api/study/topics.json    → studenti vedono published
 *   - GET  /studio/{type}/{ind}/{cls}/{subj}/{topic} → render HTML
 *
 * Eseguito come admin (passa scope studente policy).
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "teacher_content");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 15_000 });

const SUBJECT_CODE = "E2E_" + Math.random().toString(36).slice(2, 8).toUpperCase();

test.describe("Teacher content multi-materia (Phase 13)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
    });

    test("POST /api/teacher/subjects crea materia custom + lista", async ({ page }) => {
        const csrf = await page.evaluate(async () => {
            const r = await fetch("/auth/csrf", { credentials: "same-origin" });
            return (await r.json()).token;
        });
        const create = await page.evaluate(async ({ code, csrf }) => {
            const body = new URLSearchParams({ code, label: "E2E Materia " + code, group: "Test", _csrf: csrf });
            const r = await fetch("/api/teacher/subjects", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: r.status, body: await r.json() };
        }, { code: SUBJECT_CODE, csrf });
        expect(create.status).toBe(200);
        expect(create.body.ok).toBe(true);
        expect(create.body.subject.code).toBe(SUBJECT_CODE);

        // List
        const list = await page.evaluate(async () => {
            const r = await fetch("/api/teacher/subjects", { credentials: "same-origin" });
            return r.json();
        });
        expect(list.ok).toBe(true);
        const found = list.subjects.find(s => s.code === SUBJECT_CODE);
        expect(found, "materia appena creata in lista").toBeTruthy();
    });

    test("POST /api/teacher/content crea + publish + GET retrieves it", async ({ page }) => {
        const csrf = await page.evaluate(async () => (await (await fetch("/auth/csrf")).json()).token);

        const create = await page.evaluate(async ({ subject, csrf }) => {
            const body = new URLSearchParams({
                type: "esercizio",
                subject,
                indirizzo: "sc",
                classe: "sc2s",
                topic: "Test topic",
                title: "E2E content " + Date.now(),
                body_html: "<p>Risolvi l'equazione: \\(x^2 - 5x + 6 = 0\\)</p>",
                visibility: "draft",
                _csrf: csrf,
            });
            const r = await fetch("/api/teacher/content", {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: r.status, body: await r.json() };
        }, { subject: SUBJECT_CODE, csrf });
        expect(create.status).toBe(200);
        expect(create.body.ok).toBe(true);
        const id = create.body.id;
        expect(id).toBeGreaterThan(0);

        // Publish
        const publish = await page.evaluate(async ({ id, csrf }) => {
            const body = new URLSearchParams({ _csrf: csrf });
            const r = await fetch(`/api/teacher/content/${id}/publish`, {
                method: "POST", credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString(),
            });
            return { status: r.status, body: await r.json() };
        }, { id, csrf });
        expect(publish.status).toBe(200);
        expect(publish.body.ok).toBe(true);
        expect(publish.body.visibility).toBe("published");

        // Retrieve via /api/study/content.json (route pubblica per studio)
        const study = await page.evaluate(async (subject) => {
            const qs = new URLSearchParams({
                type: "esercizio", subject, ind: "sc", cls: "sc2s", topic: "Test topic",
            });
            const r = await fetch(`/api/study/content.json?${qs}`, { credentials: "same-origin" });
            return r.json();
        }, SUBJECT_CODE);
        expect(study.ok).toBe(true);
        const found = (study.rows || []).find(r => r.id === id);
        expect(found, "content published deve apparire in /api/study/content.json").toBeTruthy();
    });

    test("/studio/esercizio/sc/sc2s/{SUBJ}/Test%20topic renderizza body_html", async ({ page }) => {
        const url = `/studio/esercizio/sc/sc2s/${SUBJECT_CODE}/Test%20topic`;
        await page.goto(url);
        await expect(page.locator(".fm-db-study .fm-titolo h1")).toContainText("Test topic");
        await expect(page.locator(".fm-collection__item").first()).toBeVisible();
        const body = await page.locator(".fm-collection__item .fm-collection").first().textContent();
        expect(body).toContain("Risolvi l'equazione");
        await shot(page, "01_studio_render");
    });

    test("#sel-mater contiene materia teacher (via curriculum_entries DB)", async ({ page }) => {
        await page.goto("/?home=1");
        await page.waitForFunction(() => document.getElementById("sel-mater"));
        // CurriculumService legge curriculum_entries da DB; TeacherSubject
        // POST upsert insert kind=materie → la materia compare tra le option.
        const codes = await page.evaluate(() =>
            [...document.querySelectorAll("#sel-mater option")].map(o => o.value),
        );
        expect(codes).toContain(SUBJECT_CODE);
        await shot(page, "02_sel_mater_includes_subj");
    });

    test(`db-sidepage: click .fm-sb-sec[data-sidepage="eser"] con materia selezionata popola #fm-sp-eser con link DB`, async ({ page }) => {
        await page.goto("/?home=1");
        await page.waitForFunction(() => window.FM?.initDbSidepage);
        // Chosen wraps i select originali; settiamo i value via evaluate +
        // dispatch change (db-sidepage ascolta change su #sel-iis/cls/mater).
        // I select usano code brevi: ind="sc", cls="2s" → db-sidepage
        // concatena a "sc2s" per il query DB.
        await page.evaluate((subj) => {
            const setSel = (id, val) => {
                const el = document.getElementById(id);
                el.value = val;
                el.dispatchEvent(new Event("change", { bubbles: true }));
            };
            setSel("sel-iis",   "sc");
            setSel("sel-cls",   "2s");
            setSel("sel-mater", subj);
        }, SUBJECT_CODE);
        // Click btn2 (Esercizi) — db-sidepage popola #fm-sp-eser
        await page.evaluate(() => document.getElementById("btn2")?.click());
        // Attendi block DB con link a /studio/esercizio/sc/sc2s/{SUBJECT_CODE}/...
        await expect.poll(async () =>
            await page.evaluate(() => {
                const links = document.querySelectorAll("#fm-sp-eser .fm-db-block-list a");
                return [...links].map(a => a.getAttribute("href")).join("|");
            }),
            { timeout: 8_000 }).toContain(`/studio/esercizio/sc/sc2s/${SUBJECT_CODE}`);
        await shot(page, "03_btn2_db_block");
    });
});
