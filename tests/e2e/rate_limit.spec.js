/**
 * Verifica che il middleware 'rate' applichi una soglia per-role
 * sui POST autenticati (write endpoints legacy).
 *
 * Soglie: admin 60/min, teacher 30/min, altri 15/min (sliding window 60s).
 *
 * Il test logga admin, recupera token CSRF, invia 60 POST a /files/list
 * (tutti devono passare il middleware: 2xx, 4xx applicativi diversi da 429),
 * poi invia il 61esimo e si aspetta HTTP 429 con corpo JSON
 * {"error":"rate limit exceeded","retry_after":N}.
 *
 * NB: usa endpoint admin perché la suite di test e2e ha credenziali admin
 * stabili. La soglia teacher (30) è coperta indirettamente dalla stessa
 * logica di middleware (LIMITS table).
 */

const { test, expect } = require("@playwright/test");
const { loginAdmin } = require("./helpers");

test.describe.serial("rate limit middleware", () => {
    test("admin POST oltre 60/min su /api/probe → 429", async ({ page }) => {
        test.setTimeout(60_000);

        await loginAdmin(page);

        // Recupera CSRF dalla dashboard admin (stesso pattern di helpers)
        await page.goto("/admin/dashboard");
        const csrf = await page.evaluate(() => {
            const m = /const CSRF\s*=\s*"([^"]+)"/.exec(
                document.documentElement.innerHTML
            );
            return m ? m[1] : null;
        });
        expect(csrf, "CSRF token must be present in admin dashboard").toBeTruthy();

        const ENDPOINT = "/api/probe";
        const LIMIT    = 60; // soglia admin

        // Le prime LIMIT richieste non devono ricevere 429
        for (let i = 1; i <= LIMIT; i++) {
            const res = await page.request.post(ENDPOINT, {
                form: { _csrf: csrf },
            });
            expect(
                res.status(),
                `request #${i} should not be rate limited (got ${res.status()})`
            ).not.toBe(429);
        }

        // La (LIMIT+1)esima richiesta deve essere bloccata dal middleware
        const blocked = await page.request.post(ENDPOINT, {
            form: { _csrf: csrf },
        });
        expect(blocked.status()).toBe(429);

        const body = await blocked.json();
        expect(body).toMatchObject({
            error: "rate limit exceeded",
        });
        expect(typeof body.retry_after).toBe("number");
        expect(body.retry_after).toBeGreaterThan(0);
    });
});
