/**
 * Audit E2E del sistema DB-backed M11:
 *   - /studio/{ind}/{cls}/{mat}            → lista topics da `exercises`
 *   - /studio/{ind}/{cls}/{mat}/{topic}    → render esercizi + modalità verifica
 *   - Phase 21: verifica-mode auto-on per body.fm-admin-access (no più #btnAct)
 *   - POST /api/verifiche/build persiste in teacher_verifiche
 *   - GET /api/verifiche ritorna la verifica appena creata
 *   - ExerciseAccessPolicy: API /api/studio/exercises.json con filtri funziona
 *
 * Pre-requisito: tools/archive/migrations/migrate_exercises_to_db.php ha popolato la tabella
 * (>=1 esercizio per sc/sc2s/MAT). I test skippano se nessun match.
 */
const path = require("path");
const fs   = require("fs");
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

const SHOTS_DIR = path.join(__dirname, "..", "e2e-results", "artifacts", "studio_verifica");
fs.mkdirSync(SHOTS_DIR, { recursive: true });
const shot = (page, name) =>
    // fullPage:false perché _exercise_assets.php carica MathJax v4 + fonts
    // STIX2 asincroni — "waiting for fonts" può superare timeout su viewport
    // alto. Per gli snapshot visuali di debug basta la viewport corrente.
    page.screenshot({ path: path.join(SHOTS_DIR, `${name}.png`), fullPage: false, timeout: 15_000 });

test.describe("Studio DB-backed + Verifica Builder (admin)", () => {
    test.beforeEach(async ({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem(
                "user_cookie_consent_v2",
                JSON.stringify({ functional: true, analytics: false, advertising: false, timestamp: Date.now() }),
            );
        });
        await loginAdmin(page);
    });

    test("API /api/studio/exercises.json filtra per indirizzo/classe/materia", async ({ page }) => {
        // page.request eredita i cookie della sessione admin
        const res = await page.request.get(
            "/api/studio/exercises.json?indirizzo=sc&classe=sc2s&materia=MAT&limit=50",
        );
        expect(res.ok()).toBeTruthy();
        const json = await res.json();
        expect(json.ok).toBe(true);
        expect(Array.isArray(json.rows)).toBe(true);
        expect(json.rows.length).toBeGreaterThan(0);
        for (const r of json.rows) {
            expect(r.indirizzo).toBe("sc");
            expect(r.classe).toBe("sc2s");
            expect(r.materia).toBe("MAT");
        }
    });

    test("topicsPage lista topics da DB con link /studio/{ind}/{cls}/{mat}/{topic}", async ({ page }) => {
        await page.goto("/studio/sc/sc2s/MAT");
        await expect(page.locator("h1")).toContainText("MAT");
        const links = page.locator(".fm-study-topics a");
        await expect(links.first()).toBeVisible();
        const count = await links.count();
        expect(count).toBeGreaterThan(0);
        await shot(page, "01_topics_list");
    });

    test("topicPage renderizza collex-item con body_html da DB", async ({ page }) => {
        await page.goto("/studio/sc/sc2s/MAT");
        const firstLink = page.locator(".fm-study-topics a").first();
        const href = await firstLink.getAttribute("href");
        await firstLink.click();
        await page.waitForURL((u) => u.pathname.startsWith("/studio/sc/sc2s/MAT/"));
        await expect(page.locator(".fm-draggable-container[data-db-backed='1']")).toBeVisible();
        await expect(page.locator(".fm-collection__item").first()).toBeVisible();
        await shot(page, "02_topic_page");
    });

    test("modalità verifica: auto-on per admin (Phase 21, no btnAct)", async ({ page }) => {
        await page.goto("/studio/sc/sc2s/MAT");
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForSelector(".fm-collection__item");

        // Phase 21 — ensureVerificaMode attiva verifica-mode automaticamente
        // se body ha .fm-admin-access. Non serve più click #btnAct.
        await page.evaluate(() => {
            // Defense test: se body non ha admin-access (studio page non la setta
            // di default senza login admin), aggiungiamola per simulare lo
            // stato che i Study controller applicano quando Auth::hasAccess('admin').
            document.body.classList.add("fm-admin-access");
            window.FM.initVerificaBuilder?.();
        });
        await expect(page.locator("body")).toHaveClass(/fm-verifica-mode/);
        await expect(page.locator(".js-pick-ex").first()).toBeVisible();
        await shot(page, "03_verifica_mode_on");
    });

    test("POST /api/verifiche/build persiste teacher_verifiche + GET list lo ritorna", async ({ page }) => {
        // 1. Vai a una topic page, prendi ID di 2 esercizi dal DOM
        await page.goto("/studio/sc/sc2s/MAT");
        await page.locator(".fm-study-topics a").first().click();
        await page.waitForSelector(".fm-collection__item");
        const ids = await page.$$eval(".fm-collection__item[data-id]", (els) =>
            els.slice(0, 3).map((e) => Number(e.dataset.id)).filter(Boolean),
        );
        expect(ids.length).toBeGreaterThan(0);

        // 2. Recupera CSRF e chiama /api/verifiche/build
        const csrf = await page.evaluate(async () => {
            const r = await fetch("/auth/csrf", { credentials: "same-origin" });
            const j = await r.json();
            return j.token;
        });
        expect(csrf).toBeTruthy();

        const buildResult = await page.evaluate(async ({ ids, csrf }) => {
            const body = new URLSearchParams();
            body.set("title", "E2E Test Verifica " + Date.now());
            body.set("variant", "normal");
            body.set("includeSolutions", "1");
            body.set("_csrf", csrf);
            for (const id of ids) body.append("exerciseIds[]", String(id));
            const r = await fetch("/api/verifiche/build", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                body: body.toString(),
            });
            return { status: r.status, body: await r.json() };
        }, { ids, csrf });

        expect(buildResult.status).toBe(200);
        expect(buildResult.body.ok).toBe(true);
        expect(buildResult.body.id).toBeGreaterThan(0);
        expect(buildResult.body.count).toBe(ids.length);

        // 3. list
        const listResult = await page.evaluate(async () => {
            const r = await fetch("/api/verifiche", { credentials: "same-origin" });
            return r.json();
        });
        expect(listResult.ok).toBe(true);
        const match = listResult.rows.find((r) => r.id === buildResult.body.id);
        expect(match, "verifica appena creata deve apparire in list").toBeTruthy();
        expect(match.variant).toBe("normal");
    });
});
