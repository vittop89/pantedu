// @ts-check
/**
 * Le pagine si aprono, si somigliano, e portano dove dicono.
 * Riscrittura della seconda metà di interactions.spec.js.
 *
 * Controlli sull'apparato attorno al contenuto: la finestra di accesso è
 * centrata come quella di iscrizione, il cruscotto dell'amministrazione ha il
 * proprio percorso di navigazione e la propria barra, l'area docente ha le sue
 * schede, e le pagine principali si aprono senza errori in console. Una rotta
 * che non esiste risponde che non esiste, e una riservata chiede di entrare —
 * mai un errore del server.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, e l'attesa di
 * quattrocento millisecondi prima di misurare se la finestra è centrata è
 * sostituita dall'attesa che l'animazione sia finita.
 */
const { test, expect, attendiAnimazioniFerme } = require("../support/test");

test.describe("Qualità — pagine pubbliche", () => {
    const PAGINE = [
        { percorso: "/", nome: "pagina iniziale" },
        { percorso: "/login", nome: "pagina di accesso" },
        { percorso: "/register", nome: "pagina delle iscrizioni" },
    ];
    for (const { percorso, nome } of PAGINE) {
        test(`la ${nome} si apre senza errori in console`, async ({ page }) => {
            await page.goto(percorso);
        });
    }

    test("la finestra di accesso è centrata, e quella di iscrizione le somiglia", async ({ page }) => {
        await page.goto("/login");
        await expect(page.locator("body"), "la pagina è in modalità finestra").toHaveClass(/fm-shell--modal/);
        await expect(page.locator(".fm-card").first(), "e la scheda è quella della finestra").toHaveClass(/fm-card--modal/);

        await attendiAnimazioniFerme(page);
        const riquadro = await page.locator(".fm-card").first().boundingBox();
        const finestra = page.viewportSize();
        expect(riquadro, "la scheda ha un ingombro").toBeTruthy();
        expect(finestra, "la finestra ha una dimensione").toBeTruthy();
        if (!riquadro || !finestra) return;

        // Tolleranza di venti pixel: barra di scorrimento e arrotondamenti.
        expect(Math.abs(riquadro.x + riquadro.width / 2 - finestra.width / 2), "centrata in orizzontale").toBeLessThan(20);
        expect(Math.abs(riquadro.y + riquadro.height / 2 - finestra.height / 2), "e in verticale").toBeLessThan(20);

        await page.goto("/register");
        await expect(page.locator("body"), "l'iscrizione usa la stessa modalità").toHaveClass(/fm-shell--modal/);
    });

    test("i fogli di stile principali sono serviti", async ({ page }) => {
        for (const percorso of ["/css/shell.css", "/css/tokens.css", "/css/layout.css"]) {
            const risposta = await page.request.get(percorso);
            expect(risposta.ok(), `${percorso} risponde ${risposta.status()}`).toBe(true);
            expect(risposta.headers()["content-type"], `${percorso} è servito come CSS`).toMatch(/css/);
        }
    });
});

test.describe("Qualità — cruscotti e navigazione", () => {
    test("il cruscotto dell'amministrazione mostra i propri numeri", async ({ adminPage }) => {
        await adminPage.goto("/admin/dashboard");
        await expect(adminPage.getByRole("heading", { level: 1 }), "col suo titolo").toContainText(/admin/i);
        await expect(adminPage.locator(".fm-tile .fm-big").first(), "e almeno un riquadro con un numero").toBeVisible();
    });

    test("il cruscotto dell'amministrazione ha il proprio percorso e la propria barra", async ({ adminPage }) => {
        await adminPage.goto("/admin/dashboard");
        await expect(adminPage.locator(".fm-breadcrumb"), "il percorso di navigazione").toBeVisible();
        await expect(adminPage.locator(".fm-admin-toolnav"), "e la barra degli strumenti").toBeVisible();

        // Il percorso parte dall'amministrazione: l'account amministrativo non
        // passa dalla home pubblica.
        const radice = adminPage.locator('.fm-breadcrumb a[href="/admin/dashboard"]');
        await expect(radice, "il percorso comincia dall'amministrazione").toBeVisible();
        await expect(radice).toContainText(/Admin/);
        await expect(adminPage.locator('.fm-breadcrumb a[href="/?home=1"]'), "e non porta alla home pubblica").toHaveCount(0);
    });

    test("la home pubblica resta raggiungibile per indirizzo, con la sua barra laterale", async ({ adminPage }) => {
        await adminPage.goto("/admin/dashboard");
        await adminPage.goto("/?home=1");
        await expect(adminPage, "si arriva davvero alla home").toHaveURL(/\?home=1/);
        await expect(
            adminPage.locator("#fm-sidebar, .fm-sidebar, .sel-session-banner").first(),
            "con la barra laterale",
        ).toBeAttached({ timeout: 30_000 });
    });

    test("il cruscotto del docente ha i suoi quattro riquadri e le sue schede", async ({ adminPage }) => {
        await adminPage.goto("/teacher/dashboard");
        await expect(adminPage.locator(".fm-overview-tile"), "mappe, esercizi, laboratorio, verifiche").toHaveCount(4);
        await expect(adminPage.locator(".fm-area-docente-nav"), "e la navigazione a schede dell'area").toBeVisible();
    });

    test("la ricerca degli esercizi ha il proprio modulo, e un invio a vuoto non rompe niente", async ({ adminPage, diagnostics }) => {
        await adminPage.goto("/exercises");
        await expect(adminPage.locator('select[name="materia"]'), "il filtro della materia").toBeVisible();
        const invia = adminPage.locator('button[type="submit"]');
        await expect(invia, "e il comando di ricerca").toBeVisible();

        await invia.click();
        await expect(adminPage.locator("#fm-ex-results"), "arriva una risposta").toBeVisible({ timeout: 30_000 });
        const guasti = diagnostics.failedResponses.filter((r) => / → 5\d\d$/.test(r));
        expect(guasti, `risposte 5xx: ${guasti.join("\n")}`).toEqual([]);
    });

    // 2026-09-14 — contro l'immagine del rilascio non c'è più il registro del
    // server di `php -S` a dire quali chiamate alle API sono andate male: lo
    // annota il client nella diagnostica della prova. Nei due versi.
    test("la diagnostica annota le risposte non riuscite delle API, e solo quelle", async ({ teacherApi, diagnostics }) => {
        const prima = diagnostics.failedResponses.length;
        const buona = await teacherApi.http.send("GET", "/auth/user-info");
        expect(buona.status, "una chiamata che riesce").toBe(200);
        expect(diagnostics.failedResponses.length, "non lascia righe").toBe(prima);

        const mancante = await teacherApi.http.send("GET", "/api/questa-rotta-non-esiste-e2e");
        expect(mancante.status, "una chiamata a una rotta che non c'è").toBe(404);
        expect(diagnostics.failedResponses.slice(prima), "lascia la sua riga").toEqual([
            "GET /api/questa-rotta-non-esiste-e2e → 404 [api, docente]",
        ]);
    });
});

test.describe("Qualità — rotte che non ci sono e rotte riservate", () => {
    test("una rotta inesistente risponde che non esiste", async ({ page }) => {
        const risposta = await page.request.get("/questa-rotta-non-esiste-xyz", { maxRedirects: 0 });
        expect([404, 301, 302], `ha risposto ${risposta.status()}`).toContain(risposta.status());
    });

    test("una rotta dell'amministrazione senza sessione chiede di entrare", async ({ page }) => {
        const risposta = await page.request.get("/admin/dashboard", { maxRedirects: 0 });
        expect([301, 302, 401, 403], `ha risposto ${risposta.status()}`).toContain(risposta.status());
    });

    test("dopo l'uscita, una rotta dell'amministrazione non risponde più", async ({ adminPage }) => {
        await adminPage.goto("/logout");
        const risposta = await adminPage.request.get("/admin/whoami", { maxRedirects: 0 });
        expect([301, 302, 401, 403], `ha risposto ${risposta.status()}`).toContain(risposta.status());
    });
});
