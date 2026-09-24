// @ts-check
/**
 * Pannello dell'amministratore: contatori delle notifiche e pagina di riepilogo.
 * Riscrittura di admin_dashboard_notifications.spec.js, fetta 2 del
 * refactoring E2E.
 *
 * Verifica, con la stessa copertura di prima: l'API delle notifiche risponde
 * con i contatori attesi; la pagina mostra il riepilogo moderno e non i
 * collegamenti rimossi; il segnalino con il totale compare accanto alla
 * sessione quando c'è qualcosa da segnalare; in modalità a istanza singola il
 * modulo di iscrizione non esiste.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente `fetch` dentro
 * la pagina per leggere le API (client dell'amministratore con il token CSRF),
 * niente attesa a tempo prima di verificare l'assenza del segnalino, niente
 * screenshot di lavoro.
 */
const { test, expect } = require("../support/test");

const CONTATORI = /** @type {const} */ ([
    "total",
    "pending_registrations",
    "blocked_credentials",
    "blocked_ips",
    "failed_logins_24h",
    "new_teacher_content_24h",
]);

test.describe("Amministrazione — notifiche e pannello", () => {
    test("l'API delle notifiche risponde con tutti i contatori", async ({ adminApi }) => {
        const notifiche = await adminApi.notifications();
        expect(notifiche.ok).toBe(true);
        for (const chiave of CONTATORI) {
            expect(typeof notifiche[chiave], `contatore ${chiave}`).toBe("number");
            expect(notifiche[chiave]).toBeGreaterThanOrEqual(0);
        }
    });

    test("il pannello mostra il riepilogo moderno senza i collegamenti rimossi", async ({ adminPage }) => {
        await adminPage.goto("/admin/dashboard");
        await expect(adminPage.getByRole("heading", { name: "Admin Dashboard" })).toBeVisible();
        // Dal 13 settembre 2026 i riquadri «Da gestire» seguono lo scenario di
        // esercizio: «Registrazioni in attesa» compare dove le iscrizioni sono
        // aperte, o se una richiesta è arrivata lo stesso. Quale riquadro c'è in
        // quale scenario lo verifica admin/aree-per-scenario; qui basta la sezione.
        await expect(adminPage.getByRole("heading", { name: "Da gestire" })).toBeVisible();
        await expect(adminPage.locator(".fm-admin-kpi .fm-tile").first(), "con almeno un riquadro").toBeVisible();
        // Le statistiche restano raggiungibili dal corpo della pagina. La stessa
        // voce esiste anche nella barra strumenti, che però è chiusa al
        // caricamento: da chiusa non compare nell'albero di accessibilità, e
        // asserirla come faceva la spec storica verificherebbe solo il markup.
        await expect(adminPage.getByRole("link", { name: "Analytics" })).toBeVisible();
        // Pagine tolte dal 2026-09-05: la barra strumenti ha sostituito /admin/tools.
        // Qui il bersaglio è proprio l'indirizzo, quindi il locator è sull'href.
        await expect(adminPage.locator('a[href="/admin/tools"]')).toHaveCount(0);
        await expect(adminPage.locator('a[href="/log/admin/user_manager.php"]')).toHaveCount(0);
        await expect(adminPage.locator('a[href="/log/security/monitoring/dashboard.php"]')).toHaveCount(0);
    });

    test("il segnalino accanto alla sessione riflette il totale delle notifiche", async ({ adminPage, adminApi }) => {
        const totale = (await adminApi.notifications()).total;
        await adminPage.goto("/?home=1");
        const segnalino = adminPage.locator(".sel-session-banner .fm-admin-badge");
        if (totale > 0) {
            await expect(segnalino).toBeVisible();
            await expect(segnalino).toContainText(String(totale));
        } else {
            // Niente da segnalare: il pannello resta pulito anche dopo che il
            // modulo che lo popola è stato caricato.
            await expect(adminPage.locator(".sel-session-banner")).toBeVisible();
            await expect(segnalino).toHaveCount(0);
        }
    });

    test("in modalità a istanza singola il modulo di iscrizione non esiste", async ({ adminPage }) => {
        const risposta = await adminPage.goto("/register");
        expect([200, 403, 404]).toContain(risposta?.status());
        await expect(adminPage.locator('input[name="email"]')).toHaveCount(0);
        await expect(adminPage.locator("#fm-register-form")).toHaveCount(0);
    });
});
