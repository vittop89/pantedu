// @ts-check
/**
 * Il docente senza scuola: lo stato si può raggiungere, si capisce, e si torna
 * indietro.
 *
 * ── Perché (22/9/2026) ────────────────────────────────────────────────────
 *
 * Dal 22 settembre la scuola è facoltativa all'iscrizione, fuori dallo
 * scenario 3. Ma la cosa da provare non è l'iscrizione: è che **lo stato sia
 * vivibile**.
 *
 * Perché quello stato esisteva già — raggiungibile in due clic dal profilo,
 * perché lo scollegamento non ha mai controllato che restasse almeno una
 * scuola — e non era mai stato guardato. Tredici rotte rispondevano
 * `institute_not_found` con un 404, e nessun pezzo di interfaccia traduceva
 * quel codice: al docente arrivava un errore di rete grezzo su una pagina
 * vuota. Il «catalogo globale» che sembrava la rete di sicurezza restituiva
 * zero voci.
 *
 * ── E la controprova di quello che l'utente aveva chiesto ─────────────────
 *
 * «Rimane comunque la possibilità di aggiungere un istituto dopo, giusto?».
 * Sì, e questa spec è la dimostrazione: scollega l'ultima, guarda che
 * l'applicazione lo dica, e la ricollega.
 *
 * ── Non si lascia il docente senza scuola ─────────────────────────────────
 *
 * La scuola si ricollega nel registro di pulizia, che gira anche quando la
 * prova fallisce a metà: un docente di prova scollegato resterebbe rotto per
 * tutte le spec successive.
 */
const { test, expect } = require("../support/test");

test.describe("Area docente — il docente senza scuola", () => {
    test("scollegare l'ultima scuola lo dice, e si può rimettere", async ({ teacherPage, teacherApi, cleanup }) => {
        const prima = await teacherApi.http.getJson("/api/teacher/institutes");
        const collegate = prima.institutes ?? [];
        expect(collegate.length, "controllo positivo: il docente parte con almeno una scuola").toBeGreaterThan(0);

        // Si rimettono TUTTE, in ogni caso: è l'unica rete che regge anche se
        // la prova muore a metà.
        cleanup.add("teacher", "ricollega le scuole del docente", async () => {
            for (const i of collegate) {
                await teacherApi.http.postForm("/api/teacher/institutes/link", {
                    institute_id: String(i.id),
                });
            }
        });

        // Si scollegano tutte tranne l'ultima, senza guardare: quello che conta
        // è l'ultima.
        for (const i of collegate.slice(0, -1)) {
            await teacherApi.http.send("POST", `/api/teacher/institutes/${i.id}/unlink`, { form: {} });
        }

        const ultima = collegate[collegate.length - 1];
        const esito = await teacherApi.http.send("POST", `/api/teacher/institutes/${ultima.id}/unlink`, { form: {} });

        expect(esito.ok, `scollegare l'ultima deve riuscire → ${esito.status}`).toBe(true);
        expect(
            esito.body?.senza_scuola,
            "e il server deve DIRE che era l'ultima: prima rispondeva {ok:true} e basta",
        ).toBe(true);
        expect(String(esito.body?.messaggio ?? ""), "con una spiegazione, non un codice").toMatch(/scuola/i);

        // ── Lo stato si capisce, non è un errore di rete ──────────────────
        const fonti = await teacherApi.http.send("GET", "/api/teacher/origins.json");
        expect(
            fonti.body?.senza_scuola,
            "le rotte che senza scuola non hanno niente da mostrare lo devono dichiarare",
        ).toBe(true);
        expect(
            String(fonti.body?.dove ?? ""),
            "e devono dire dove si rimedia",
        ).toContain("/area-docente/profilo");

        // ── Il catalogo è vuoto, e non esplode ────────────────────────────
        const curriculum = await teacherApi.http.send("GET", "/api/curriculum");
        expect(curriculum.status, "il catalogo risponde, non va in errore").toBeLessThan(500);

        // ── E si torna indietro dall'interfaccia, che è la cosa chiesta ───
        await teacherPage.goto("/area-docente/profilo");
        await expect(
            teacherPage.locator("#fm-profile-current"),
            "l'elenco si popola",
        ).not.toContainText("Caricamento", { timeout: 30_000 });

        await expect(
            teacherPage.locator("#fm-profile-add-btn"),
            "il bottone per collegare una scuola c'è: è la via per tornare indietro",
        ).toBeVisible();

        const ricollegata = await teacherApi.http.postForm("/api/teacher/institutes/link", {
            institute_id: String(ultima.id),
        });
        expect(ricollegata.ok, "e ricollegarla riesce").toBe(true);

        const dopo = await teacherApi.http.getJson("/api/teacher/institutes");
        expect(
            (dopo.institutes ?? []).some((/** @type {{id: unknown}} */ i) => String(i.id) === String(ultima.id)),
            "la scuola è tornata",
        ).toBe(true);
    });
});
