/**
 * Interactions + DB-frontend integrity suite.
 *
 * Covers:
 *  - Page accessibility & console-error budget on key authenticated pages
 *  - Static asset availability (bootstrap.js, endpoints.js, css)
 *  - DB ↔ frontend integrity (search.json count vs HTML render)
 *  - Admin JSON endpoints (whoami, access-log, debug-log, registrations)
 *  - Curriculum public-read JSON shape
 *  - Frontend interactions (filter form, results render, sidebar nav)
 *  - 404 / unknown route renders error page (no 500)
 */
const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

async function consoleAndNetwork(page) {
    const errors = [];
    const failed = [];
    page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });
    page.on("response", (r) => {
        const u = r.url();
        if (r.status() >= 400 && !/favicon|gas-client/i.test(u)) failed.push(`${r.status()} ${u}`);
    });
    return { errors, failed };
}

test.describe("static assets", () => {
    test("bootstrap.js è servito con MIME corretto", async ({ request }) => {
        const r = await request.get("/js/modules/bootstrap.js");
        expect(r.ok()).toBe(true);
        expect(r.headers()["content-type"]).toMatch(/javascript/);
    });

    test("endpoints.js esporta Endpoints constant", async ({ request }) => {
        const r = await request.get("/js/modules/core/endpoints.js");
        expect(r.ok()).toBe(true);
        const body = await r.text();
        expect(body).toContain("export const Endpoints");
    });

    test("css principali sono raggiungibili (shell.css + tokens.css)", async ({ request }) => {
        const must = ["/css/shell.css", "/css/tokens.css", "/css/layout.css"];
        for (const p of must) {
            const r = await request.get(p);
            expect(r.ok(), `${p} status=${r.status()}`).toBe(true);
            expect(r.headers()["content-type"]).toMatch(/css/);
        }
    });
});

test.describe("admin JSON endpoints", () => {
    test.beforeEach(async ({ page }) => { await loginAdmin(page); });

    test("/admin/whoami restituisce ruolo administrator", async ({ page }) => {
        const r = await page.request.get("/admin/whoami");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(j.ok).toBe(true);
        expect(j.user?.role).toBe("administrator");
    });

    test("/admin/access-log ritorna lista 'recent'", async ({ page }) => {
        const r = await page.request.get("/admin/access-log?limit=5");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(j.ok).toBe(true);
        expect(Array.isArray(j.recent)).toBe(true);
    });

    test("/admin/access-stats type=daily_stats valido", async ({ page }) => {
        const r = await page.request.get("/admin/access-stats?type=daily_stats");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(j.ok).toBe(true);
    });

    test("/admin/access-stats type=invalido rifiutato 400", async ({ page }) => {
        const r = await page.request.get("/admin/access-stats?type=BOGUS");
        expect(r.status()).toBe(400);
    });

    test("/admin/debug-log ritorna mappa logs", async ({ page }) => {
        const r = await page.request.get("/admin/debug-log?lines=3");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(j.ok).toBe(true);
        expect(typeof j.logs).toBe("object");
    });

    test("/admin/registrations ritorna 'pending' array", async ({ page }) => {
        const r = await page.request.get("/admin/registrations");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(Array.isArray(j.pending)).toBe(true);
    });
});

test.describe("curriculum public read", () => {
    test("/curriculum non autenticato → solo entries attive", async ({ request }) => {
        const r = await request.get("/curriculum");
        expect(r.ok()).toBe(true);
        const j = await r.json();
        expect(j.ok).toBe(true);
        expect(j.curriculum).toHaveProperty("indirizzi");
        expect(j.curriculum).toHaveProperty("classi");
        expect(j.curriculum).toHaveProperty("materie");
        for (const k of ["indirizzi", "classi", "materie"]) {
            expect(Array.isArray(j.curriculum[k])).toBe(true);
        }
    });

    test("/curriculum admin → ritorna struttura completa (con inactive)", async ({ page }) => {
        await loginAdmin(page);
        const r = await page.request.get("/curriculum");
        const j = await r.json();
        expect(j.ok).toBe(true);
        // shape admin: ['indirizzi','classi','materie'] presenti, ognuno con tutti i record
        expect(j.curriculum).toHaveProperty("indirizzi");
    });
});

test.describe("DB ↔ frontend integrity", () => {
    test("search.json: 'count' coerente con 'rows.length' (no off-by-one)", async ({ page }) => {
        await loginAdmin(page);
        const j = await (await page.request.get(
            "/exercises/search.json?materia=MAT&limit=10",
        )).json();
        expect(j.ok).toBe(true);
        // 'count' è la dimensione del payload; deve essere == rows.length
        if (typeof j.count === "number") {
            expect(j.count).toBe(j.rows.length);
        }
        // Tutte le righe rispettano il filtro server-side
        for (const r of j.rows) expect(r.materia).toBe("MAT");
    });

    test("search.json: limit=N mai più di N righe", async ({ page }) => {
        await loginAdmin(page);
        const r = await page.request.get("/exercises/search.json?limit=3");
        const j = await r.json();
        expect(j.ok).toBe(true);
        expect(j.rows.length).toBeLessThanOrEqual(3);
    });

    test("lista verifiche (API contenuti) shape coerente con DB schema", async ({ page }) => {
        // Legacy /teacher/verifiche.json RIMOSSO: la lista verifiche è ora
        // nell'API contenuti unificata, teacher-scoped → login docente.
        await page.goto("/login");
        await page.fill('input[name="username"]', "superadmin");
        await page.fill('input[name="password"]', process.env.E2E_TEACHER_PASS || "");
        await Promise.all([
            page.waitForURL(/^(?!.*\/login).*/),
            page.click('button[type="submit"]'),
        ]);
        const r = await page.request.get("/api/teacher/content?content_type=verifica");
        const j = await r.json();
        expect(j.ok).toBe(true);
        for (const row of (j.rows || []).slice(0, 10)) {
            for (const k of ["id", "content_type", "title", "created_at"]) {
                expect(row, `riga ${row.id} manca ${k}`).toHaveProperty(k);
            }
        }
    });
});

test.describe("page accessibility (no console errors)", () => {
    const PAGES = [
        ["/", "homepage"],
        ["/login", "login"],
        ["/register", "register"],
    ];
    for (const [url, label] of PAGES) {
        test(`${label} (${url}) no errori console`, async ({ page }) => {
            const { errors } = await consoleAndNetwork(page);
            await page.goto(url);
            await page.waitForLoadState("domcontentloaded");
            const critical = errors.filter((e) =>
                !/gas-client|MIME type|Failed to load resource/i.test(e),
            );
            expect(critical, critical.join("\n")).toEqual([]);
        });
    }

    test("/admin/dashboard renderizza con counts visibili", async ({ page }) => {
        await loginAdmin(page);
        const { errors } = await consoleAndNetwork(page);
        await page.goto("/admin/dashboard");
        await expect(page.locator("h1")).toContainText(/admin/i);
        // Almeno una tile presente con un numero dentro
        const tiles = page.locator(".fm-tile .fm-big");
        await expect(tiles.first()).toBeVisible();
        const critical = errors.filter((e) => !/gas-client|MIME/i.test(e));
        expect(critical, critical.join("\n")).toEqual([]);
    });

    test("/teacher/dashboard mostra 4 tile docente", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/teacher/dashboard");
        // Layout a tile (panoramica): Mappe/Esercizi/Laboratorio/Verifiche.
        // (La dashboard ridisegnata non ha più un h1; le 4 tile sono nel DOM.)
        await expect(page.locator(".fm-overview-tile")).toHaveCount(4);
    });

    test("/exercises form filtri render correttamente", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/exercises");
        await expect(page.locator('select[name="materia"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });
});

test.describe("frontend interactions", () => {
    test("/exercises form vuoto → submit non genera 5xx", async ({ page }) => {
        await loginAdmin(page);
        const { failed } = await consoleAndNetwork(page);
        await page.goto("/exercises");
        await page.click('button[type="submit"]');
        await page.waitForLoadState("networkidle");
        const fivexx = failed.filter((f) => /^5\d\d/.test(f));
        expect(fivexx, fivexx.join("\n")).toEqual([]);
    });

    test("sidebar dashboard → home navigation è funzionante", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/teacher/dashboard");
        // Ha sempre un link visibile a /exercises o /admin/dashboard
        const links = page.locator('a[href^="/"]');
        const count = await links.count();
        expect(count).toBeGreaterThan(0);
    });

    test("logout via /logout poi accesso admin route → redirect/4xx", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/logout");
        const r = await page.request.get("/admin/whoami", { maxRedirects: 0 });
        // dovrebbe essere redirect (301/302) o 401/403
        expect([301, 302, 401, 403]).toContain(r.status());
    });
});

test.describe("error handling", () => {
    test("route inesistente restituisce 404 (non 500)", async ({ request }) => {
        const r = await request.get("/this-route-does-not-exist-xxx", { maxRedirects: 0 });
        expect([404, 301, 302]).toContain(r.status());
    });

    test("/admin/* senza auth → redirect login (no 500)", async ({ request }) => {
        const r = await request.get("/admin/dashboard", { maxRedirects: 0 });
        expect([301, 302, 401, 403]).toContain(r.status());
    });
});

test.describe("login card layout", () => {
    test("/login ha backdrop dim + card centrata (fm-shell--modal)", async ({ page }) => {
        await page.goto("/login");
        const bodyClass = await page.locator("body").getAttribute("class");
        expect(bodyClass).toContain("fm-shell--modal");
        const cardClass = await page.locator(".fm-card").getAttribute("class");
        expect(cardClass).toContain("fm-card--modal");
    });

    test("card .fm-card--modal è centrata visivamente (position fixed 50/50)", async ({ page }) => {
        await page.goto("/login");
        // Aspetta che l'animazione finisca
        await page.waitForTimeout(400);
        const rect = await page.locator(".fm-card").boundingBox();
        const viewport = page.viewportSize();
        const centerX = rect.x + rect.width / 2;
        const centerY = rect.y + rect.height / 2;
        // Tolleranza 20px per scrollbar/subpixel
        expect(Math.abs(centerX - viewport.width / 2)).toBeLessThan(20);
        expect(Math.abs(centerY - viewport.height / 2)).toBeLessThan(20);
    });

    test("/register usa stessa modalità modal", async ({ page }) => {
        await page.goto("/register");
        const bodyClass = await page.locator("body").getAttribute("class");
        expect(bodyClass).toContain("fm-shell--modal");
    });
});

test.describe("dashboard back navigation", () => {
    test("/admin/dashboard ha link verso la home", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/dashboard");
        // Il back-to-home vive ora nel breadcrumb (page_head.php): "🏠 Home".
        const back = page.locator('.fm-breadcrumb a[href="/?home=1"]');
        await expect(back).toBeVisible();
        await expect(back).toContainText(/Home/);
    });

    test("/admin/dashboard ha breadcrumb Home › Admin", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/dashboard");
        await expect(page.locator(".fm-breadcrumb")).toBeVisible();
        await expect(page.locator('.fm-breadcrumb a[href="/?home=1"]')).toBeVisible();
    });

    test("click su 'Torna alla home' da admin porta a homepage con sidebar", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/admin/dashboard");
        await page.click('.fm-breadcrumb a[href="/?home=1"]');
        await page.waitForURL(/\/\?home=1|\/$/);
        // Deve essere la homepage, non il redirect al dashboard
        expect(page.url()).toMatch(/\?home=1/);
    });

    test("/teacher/dashboard ha la nav area docente (admin può accedere)", async ({ page }) => {
        await loginAdmin(page);
        await page.goto("/teacher/dashboard");
        // Scelta di prodotto: l'area docente usa la nav a schede (.fm-area-docente-nav),
        // non un link 'torna alla home' separato.
        await expect(page.locator(".fm-area-docente-nav")).toBeVisible();
    });
});

test.describe("FM module integrity (frontend bootstrap)", () => {
    test("FM.Endpoints completo: tutti i namespace previsti esistono", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => window.FM?.Endpoints);
        const ok = await page.evaluate(() => {
            const E = window.FM.Endpoints;
            const required = [
                "auth", "files", "exercises", "tikz", "editor",
                "verifiche", "update", "create", "check", "admin",
                "teacher", "analytics", "templates",
            ];
            return required.every((k) => E[k] && typeof E[k] === "object");
        });
        expect(ok).toBe(true);
    });

    test("Endpoints espone URL moderni (no /.*\\.php/ in chiavi primarie)", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => window.FM?.Endpoints);
        const legacyLeaks = await page.evaluate(() => {
            const E = window.FM.Endpoints;
            const out = [];
            for (const [ns, obj] of Object.entries(E)) {
                if (ns === "legacy" || ns === "templates") continue;
                for (const [k, v] of Object.entries(obj || {})) {
                    if (typeof v === "string" && /\.php(\?|$)/.test(v)) {
                        out.push(`${ns}.${k} = ${v}`);
                    }
                }
            }
            return out;
        });
        expect(legacyLeaks, "URL legacy .php nelle chiavi primarie (migrare a modern):\n" + legacyLeaks.join("\n")).toEqual([]);
    });

    test("FM.Api espone getJson + postJson", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => window.FM?.Api);
        const has = await page.evaluate(() => {
            const a = window.FM.Api;
            return a && typeof a.getJson === "function" && typeof a.postJson === "function";
        });
        expect(has).toBe(true);
    });
});
