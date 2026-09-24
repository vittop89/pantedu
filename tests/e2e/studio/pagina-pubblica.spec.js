// @ts-check
/**
 * Pagine pubbliche e stato della sessione.
 * Riscrittura di smoke.spec.js.
 *
 * Sono i controlli di base che devono valere sempre: la pagina iniziale si
 * apre senza errori, i moduli dell'applicazione sono caricati, il modulo di
 * accesso c'è, la sessione si apre e si chiude, e le rotte tolte non
 * rispondono più.
 *
 * Cosa cambia rispetto a prima: la sessione dell'amministratore arriva dalla
 * fixture invece che da un login nella spec, e gli errori di console si
 * leggono dalla diagnostica condivisa, che li filtra già dal rumore di rete.
 */
const { test, expect } = require("../support/test");

/** Moduli che l'applicazione espone in pagina dopo l'avvio. */
const MODULI_ATTESI = ["Api", "AppState", "Config", "Dialog", "Endpoints"];

test.describe("Pagine pubbliche e sessione", () => {
    test("la pagina iniziale si apre senza errori in console", async ({ page }) => {
        await page.goto("/");
        await expect(page).toHaveTitle(/PANTEDU/i);
        await expect(page.locator(".sidebar")).toBeVisible();
    });

    test("l'applicazione espone i propri moduli in pagina", async ({ page }) => {
        await page.goto("/");
        await expect
            .poll(async () => page.evaluate(() => Object.keys(window.FM ?? {})), { message: "moduli caricati", timeout: 30_000 })
            .toEqual(expect.arrayContaining(MODULI_ATTESI));
    });

    test("il modulo di accesso mostra i suoi campi", async ({ page }) => {
        await page.goto("/login");
        await expect(page.locator('input[name="username"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeVisible();
    });

    test("la sessione dell'amministratore è aperta e riconosciuta", async ({ adminApi }) => {
        /** @type {{ authenticated?: boolean, role?: string }} */
        const identita = await adminApi.http.getJson("/auth/user-info");
        expect(identita.authenticated).toBe(true);
        expect(identita.role).toBe("administrator");
    });

    test("uscire chiude la sessione", async ({ adminPage }) => {
        await adminPage.goto("/logout");
        const identita = await adminPage.request.get("/auth/user-info").then((r) => r.json());
        expect(identita.authenticated, "dopo l'uscita non si è più autenticati").toBe(false);
    });

    test("una mutazione senza il gettone di sicurezza non viene eseguita", async ({ teacherPage }) => {
        // La richiesta parte da una sessione valida ma senza il gettone CSRF:
        // il server la respinge con 403 e la pagina di errore, non con un JSON
        // di successo.
        const risposta = await teacherPage.request.post("/check/password", {
            data: { password: "x" },
            headers: { "Content-Type": "application/json" },
            failOnStatusCode: false,
        });
        const corpo = await risposta.text();
        expect([400, 401, 403, 419], `stato ${risposta.status()}`).toContain(risposta.status());
        expect(corpo, "nessuna conferma di successo").not.toMatch(/"ok"\s*:\s*true/);
    });

    test("l'ingresso di accesso tolto il 5 settembre non risponde più", async ({ page }) => {
        const risposta = await page.request.get("/log/auth/login.php", { failOnStatusCode: false });
        expect(risposta.status()).toBe(404);
    });

    test("la ricerca degli esercizi filtra per materia e difficoltà", async ({ adminPage }) => {
        const risposta = await adminPage.goto("/exercises");
        expect(risposta?.status()).toBe(200);
        await expect(adminPage.locator("h1")).toContainText(/ricerca esercizi/i);
        await expect(adminPage.locator("#fm-ex-form")).toBeVisible();

        /** @type {{ ok: boolean, rows: ReadonlyArray<{ materia?: string, difficulty?: number }> }} */
        const risultati = await adminPage.request
            .get("/exercises/search.json?materia=MAT&difficulty=2")
            .then((r) => r.json());
        expect(risultati.ok).toBe(true);
        expect(Array.isArray(risultati.rows)).toBe(true);
        for (const riga of risultati.rows) {
            expect(riga.materia).toBe("MAT");
            expect(riga.difficulty).toBe(2);
        }
    });
});
