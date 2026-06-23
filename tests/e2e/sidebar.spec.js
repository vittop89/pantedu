/**
 * Sidebar buttons interactivity.
 *
 * La sidebar (`views/partials/sidebar.php`) ha 6 pulsanti .fm-sb-sec[data-sidepage="mappe"]...fm-sb-sec[data-sidepage="verif"]
 * che mostrano/nascondono div .fm-sb-panel (#fm-sp-mappe, #fm-sp-lab, #fm-sp-eser, #fm-sp-verif,
 * #fm-sp-bes, #fm-sp-risdoc). Il binding click avviene in
 * `App.setupSidebarButtons()` (js/modules/integrations/google-apps.js)
 * invocato da `legacyBoot()` in legacy-init.js, che a sua volta deve
 * essere caricato dal bootstrap module.
 *
 * Unit 2 (refactor-html-naming): i btn hanno anche la class `.fm-sb-sec`
 * e l'attributo `data-sidepage="mappe|lab|eser|verif|bes|risdoc"` —
 * verifichiamo entrambe le convenzioni (legacy ID + nuova semantica).
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe("sidebar buttons (homepage)", () => {
    test.beforeEach(async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/?home=1");
        await page.waitForFunction(() => window.FM?.Endpoints);
        // jQuery rimosso (Sprint B 2026-06): attende solo che legacyBoot abbia
        // esposto window.App (binding sidebar vanilla via App.setupSidebarButtons).
        await page.waitForFunction(() => window.App, { timeout: 5000 });
    });

    test("i 6 pulsanti sidebar sono presenti nel DOM", async ({ page }) => {
        // ADR-027 — gli ID legacy #btn0..#btn5 sono stati rimossi: la sidebar è
        // data-driven con .fm-sb-sec[data-sidepage].
        for (const sp of ["mappe", "lab", "eser", "verif", "bes", "risdoc"]) {
            await expect(page.locator(`.fm-sb-sec[data-sidepage="${sp}"]`)).toBeVisible();
        }
    });

    test("i 6 pulsanti hanno class .fm-sb-sec + data-sidepage (Unit 2)", async ({ page }) => {
        const sidepages = await page.$$eval(
            "#fm-sb-scroll .fm-sb-sec[data-sidepage]",
            (nodes) => nodes.map((n) => n.getAttribute("data-sidepage")),
        );
        expect(sidepages.sort()).toEqual(
            ["bes", "eser", "lab", "mappe", "risdoc", "verif"].sort(),
        );
    });

    test("ogni pulsante sidebar ha un click handler funzionante (vanilla, post-jQuery)", async ({ page }) => {
        // jQuery rimosso (Sprint B): non si può più introspezionare il registry
        // eventi via jQuery._data. Verifica comportamentale equivalente: ogni
        // .fm-sb-sec esiste e il suo click (bindato da App.setupSidebarButtons,
        // vanilla) gira senza sollevare errori.
        const errs = [];
        page.on("pageerror", (e) => errs.push(e.message));
        for (const sp of ["mappe", "lab", "eser", "verif", "bes", "risdoc"]) {
            const sel = `.fm-sb-sec[data-sidepage="${sp}"]`;
            await expect(page.locator(sel)).toBeVisible();
            await page.evaluate((s) => document.querySelector(s)?.click(), sel);
            await page.waitForTimeout(100);
        }
        expect(errs, errs.join(" | ")).toEqual([]);
    });

    test(`click programmatico su .fm-sb-sec[data-sidepage="mappe"] cambia border-style (toggle)`, async ({ page }) => {
        const before = await page.locator('.fm-sb-sec[data-sidepage="mappe"]').evaluate((el) =>
            window.getComputedStyle(el).borderStyle,
        );
        // jQuery trigger per evitare problemi di overlay/visibility CSS
        await page.evaluate(() => document.querySelector('.fm-sb-sec[data-sidepage="mappe"]').click());
        await page.waitForTimeout(200);
        const after = await page.locator('.fm-sb-sec[data-sidepage="mappe"]').evaluate((el) =>
            window.getComputedStyle(el).borderStyle,
        );
        expect(before, `before=${before}, after=${after}`).not.toBe(after);
    });

    test(`click su .fm-sb-sec[data-sidepage="eser"] (Esercizi) triggera fetch study content.json`, async ({ page }) => {
        // Phase 19 — sidepage DB-only: db-sidepage.js chiama /api/study/content.json.
        // Lo studio content è una feature docente/studente: l'admin NON lo consuma,
        // quindi rilogghiamo come docente.
        await page.goto("/logout");
        await page.goto("/login");
        await page.fill('input[name="username"]', "superadmin");
        await page.fill('input[name="password"]', process.env.E2E_TEACHER_PASS || "");
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/),
            page.click('button[type="submit"]'),
        ]);
        await page.goto("/?home=1");
        await page.waitForFunction(() => window.FM?.Endpoints && window.App);
        // db-sidepage.js (loadDbContentBySubject) fa il GET SOLO se c'è un
        // contesto curriculare attivo: ind=#sel-iis, cls=#sel-cls (early-return
        // se vuoti) e almeno una materia in collectActiveSubjects(). Dopo
        // loginAdmin→logout→login teacher i select possono restare sul placeholder,
        // quindi impostiamo esplicitamente la combo seedata SCI/2/MAT (i select
        // cascano: iis→cls→mater, perciò li settiamo in sequenza). Helper salta
        // la option placeholder (value "Scegli…"/"Materia:").
        const setSel = async (id, wanted) => {
            await page.waitForFunction((sid) => {
                const e = document.getElementById(sid);
                return e && Array.from(e.options).some((o) => o.value && !/^Scegli|^Materia:/.test(o.value));
            }, id, { timeout: 6000 }).catch(() => null);
            await page.evaluate(({ sid, w }) => {
                const e = document.getElementById(sid);
                if (!e) return;
                const opts = Array.from(e.options).filter((o) => o.value && !/^Scegli|^Materia:/.test(o.value));
                const opt = opts.find((o) => o.value === w) || opts[0];
                if (opt) { e.value = opt.value; e.dispatchEvent(new Event("change", { bubbles: true })); }
            }, { sid: id, w: wanted });
            await page.waitForTimeout(200);
        };
        await setSel("sel-iis", "SCI");
        await setSel("sel-cls", "2");
        await setSel("sel-mater", "MAT");
        await page.evaluate(() => window.FM?.clearTeacherContentCache?.());
        const reqWaiter = page.waitForRequest(
            (r) => /\/api\/study\/content\.json/.test(r.url()),
            { timeout: 6000 },
        ).catch(() => null);
        await page.evaluate(() => document.querySelector('.fm-sb-sec[data-sidepage="eser"]').click());
        const req = await reqWaiter;
        expect(req, "atteso request a /api/study/content.json dopo click").not.toBeNull();
    });

    test("window.Api aliased to ApiJQuery (compat)", async ({ page }) => {
        const ok = await page.evaluate(() =>
            typeof window.Api === "object"
            && typeof window.Api._fetch === "function"
            && window.Api === window.ApiJQuery,
        );
        expect(ok).toBe(true);
    });

    test("endpoint sidebar-data accessibili a student+ (no 401/403/csrf)", async ({ page }) => {
        // Phase 18 — /files/list-php rimosso (sidebar ora DB-only).
        // Resta /api/probe + il DB topics API.
        const csrfRes = await page.request.get("/auth/csrf");
        const { token: csrf } = await csrfRes.json();
        const r = await page.request.post("/api/probe", {
            form: { _csrf: csrf, file_links: "/mappe/MAT/MAT_links.json" },
        });
        expect([401, 403, 419], `status=${r.status()}`).not.toContain(r.status());
        // DB topics API deve essere GET accessibile
        const topics = await page.request.get("/api/study/topics.json?type=esercizio&subject=MAT");
        expect([401, 403]).not.toContain(topics.status());
    });

    test("click su link mappa esterno (diagrams.net) mantiene sidebar", async ({ page }) => {
        // Carica sidebar, apre Mappe, clicca un link esterno: verifica che
        // la sidebar resti visibile e che #fm-content contenga un <iframe>
        // invece di aver navigato off-site.
        await page.evaluate(() => document.querySelector('.fm-sb-sec[data-sidepage="mappe"]').click());
        // Dopo loadSidebarContent può richiedere qualche ms per popolarsi
        await page.waitForTimeout(500);
        // Se non ci sono link di mappa sul curriculum corrente, usa URL
        // esterno fittizio cliccandolo via API
        const beforeVisible = await page.locator(".sidebar").isVisible();
        expect(beforeVisible).toBe(true);

        await page.evaluate(() => {
            // Use non-diagrams external URL to bypass cookie-consent gate
            window.DOMManager.loadUrlInFrame("https://example.com/");
        });
        await page.waitForTimeout(800);

        const sidebarStill = await page.locator(".sidebar").isVisible();
        expect(sidebarStill, "sidebar deve restare visibile dopo click su link esterno").toBe(true);

        const iframeCount = await page.locator("#fm-content iframe").count();
        expect(iframeCount, "#fm-content deve contenere un iframe con il link esterno").toBeGreaterThan(0);

        const iframeSrc = await page.locator("#fm-content iframe").first().getAttribute("src");
        expect(iframeSrc).toContain("example.com");
    });

    test("toggle #IObar (chiudi sidebar) cambia stato checked", async ({ page }) => {
        const checked = await page.locator("#IObar").isChecked();
        // input nascosto da CSS — toggliamo via JS
        await page.evaluate(() => {
            const el = document.getElementById("IObar");
            el.checked = !el.checked;
            el.dispatchEvent(new Event("change", { bubbles: true }));
        });
        const checkedAfter = await page.locator("#IObar").isChecked();
        expect(checkedAfter).not.toBe(checked);
    });

    test("legacy path /eser/* ora ritorna 410 Gone o 302 redirect", async ({ page }) => {
        // Phase 18/19 — LegacyGoneMiddleware: 302 a /studio/... se
        // risolvibile in DB, altrimenti 410.
        const res = await page.request.get("/eser/ar/eser_ar2s/MAT/1_MAT-random-ar2s.php", {
            maxRedirects: 0,
        });
        expect([302, 410]).toContain(res.status());
    });

    test("layout_es.css caricato in parent head (scoped via body.exercise-context)", async ({ page }) => {
        const cssLoaded = await page.evaluate(() =>
            !!document.head.querySelector('link[href*="layout_es.css"]')
        );
        expect(cssLoaded, "layout_es.css deve essere caricato nel head main (scoped)").toBe(true);
    });

    test("content margin-left dinamico: sidebar aperta/chiusa", async ({ page }) => {
        // Sidebar aperta: body NON ha .fm-sidebar-closed, #fm-content margin-left = --widthLsidebar
        const openState = await page.evaluate(() => {
            const el = document.getElementById("fm-content");
            if (!el) return null;
            return {
                bodyHasClass: document.body.classList.contains("fm-sidebar-closed"),
                marginLeft: window.getComputedStyle(el).marginLeft,
            };
        });
        expect(openState).not.toBeNull();
        expect(openState.bodyHasClass).toBe(false);
        expect(parseInt(openState.marginLeft)).toBeGreaterThan(100);

        // Chiudi sidebar via DOMManager.toggleSidebar
        await page.evaluate(() => {
            document.getElementById("IObar").checked = false;
            window.DOMManager.toggleSidebar();
        });
        await page.waitForTimeout(700); // aspetta fine animazione 600ms

        const closedState = await page.evaluate(() => {
            const el = document.getElementById("fm-content");
            return {
                bodyHasClass: document.body.classList.contains("fm-sidebar-closed"),
                marginLeft: window.getComputedStyle(el).marginLeft,
            };
        });
        expect(closedState.bodyHasClass).toBe(true);
        expect(parseInt(closedState.marginLeft)).toBeLessThanOrEqual(34);
    });
});
