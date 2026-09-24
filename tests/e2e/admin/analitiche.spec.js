// @ts-check
/**
 * Amministrazione — analitiche d'uso e ricerca fra i contenuti dei docenti.
 * Riscrittura di analytics_and_classes.spec.js.
 *
 * La pagina delle analitiche riassume chi usa la piattaforma e con che cosa:
 * utenti per ruolo, contenuti per tipo e per visibilità, accessi nelle ultime
 * ventiquattro ore e negli ultimi trenta giorni. Ha anche una ricerca che
 * attraversa i contenuti di tutti i docenti — una funzione che vede dati di
 * altre persone, e che infatti segnala i contenuti a rischio con delle
 * bandierine.
 *
 * Cosa cambia rispetto a prima: niente login nella spec, niente `fetch` scritto
 * dentro a `page.evaluate`, niente schermate salvate su disco. I due casi sulle
 * iscrizioni chiuse sono passati a `pubblico/iscrizioni-chiuse`, dove stanno
 * insieme agli altri.
 */
const { test, expect } = require("../support/test");

test.describe("Amministrazione — analitiche", () => {
    test("il riepilogo arriva con tutte le sue voci", async ({ adminApi }) => {
        const riepilogo = await adminApi.http.getJson("/api/admin/analytics");
        expect(riepilogo["ok"], "la rotta risponde").toBe(true);

        for (const voce of [
            "users_by_role",
            "content_by_type",
            "content_by_vis",
            "top_authors",
            "top_institutes",
            "access_30d_role",
            "access_24h_total",
            "access_7d_total",
        ]) {
            expect(riepilogo, `il riepilogo contiene «${voce}»`).toHaveProperty(voce);
        }
        expect(typeof riepilogo["access_24h_total"], "gli accessi del giorno sono un numero").toBe("number");
    });

    test("la pagina si apre con le sue tre schede, e il riepilogo si popola", async ({ adminPage }) => {
        await adminPage.goto("/admin/analytics");
        await expect(
            adminPage.getByRole("heading", { level: 1 }).filter({ hasText: "Admin Analytics" }),
            "la pagina delle analitiche",
        ).toBeVisible({ timeout: 30_000 });

        for (const scheda of ["overview", "teachers", "search"]) {
            await expect(adminPage.locator(`.fm-tab[data-tab="${scheda}"]`), `scheda «${scheda}»`).toBeVisible();
        }

        // Il riepilogo si carica dopo: si aspetta che smetta di dire «Caricamento».
        await expect(adminPage.locator("#fm-an-overview"), "il riepilogo si popola")
            .not.toHaveText("Caricamento…", { timeout: 30_000 });
    });

    test("la ricerca fra i contenuti dei docenti dice di chi sono e che rischi presentano", async ({ adminApi }) => {
        const risultato = await adminApi.http.getJson("/api/admin/analytics/cross-search", { limit: "5" });
        expect(risultato["ok"], "la ricerca risponde").toBe(true);

        const righe = /** @type {{ teacher_id: unknown, teacher_username: unknown, body_snippet: unknown, risk_flags: unknown }[]} */ (risultato["rows"]);
        expect(Array.isArray(righe), "con un elenco di risultati").toBe(true);
        for (const riga of righe) {
            expect(riga, "ogni risultato dice di quale docente è").toHaveProperty("teacher_id");
            expect(riga, "e con che utenza").toHaveProperty("teacher_username");
            expect(riga, "e ne mostra un pezzo").toHaveProperty("body_snippet");
            expect(Array.isArray(riga.risk_flags), "con le eventuali segnalazioni di rischio").toBe(true);
        }
    });

    test("il comando del tema scuro sta accanto al banner della sessione e funziona", async ({ adminPage }) => {
        await adminPage.goto("/?home=1");
        const comando = adminPage.locator(".fm-sb-dark.fm-darkmode-mini");
        await expect(comando, "il comando del tema scuro").toBeVisible({ timeout: 30_000 });

        await comando.click();
        await expect(adminPage.locator("body"), "la pagina passa al tema scuro").toHaveClass(/fm-dark/);
        await comando.click();
        await expect(adminPage.locator("body"), "e torna a quello chiaro").not.toHaveClass(/fm-dark/);
    });
});
