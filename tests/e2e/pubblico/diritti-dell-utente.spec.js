// @ts-check
/**
 * Diritti che l'utente esercita da solo: consensi, rettifica, portabilità,
 * cancellazione dell'account.
 * Riscrittura di gdpr_self_service.spec.js.
 *
 * Sono le rotte `/me/*`: quel che una persona può fare sui propri dati senza
 * chiedere niente a nessuno. Il consenso si dà e si ritira (art. 7), i propri
 * dati si correggono (art. 16), si scaricano tutti in un archivio (art. 20) e
 * l'account si può chiedere di cancellare (art. 17).
 *
 * La cancellazione ha tre tempi, e il test li percorre tutti: si chiede, si
 * conferma dalla pagina del collegamento ricevuto per posta, e poi comincia un
 * periodo di ripensamento di trenta giorni durante il quale si può ancora
 * annullare. È il
 * percorso che protegge da una cancellazione chiesta per sbaglio, e va
 * verificato fino in fondo — compreso l'annullamento, che è quel che riporta
 * l'account di prova dov'era.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec (erano otto, uno per
 * test), niente gettone di sicurezza chiesto a mano, e i ripristini — il
 * consenso ritirato, il nome rimesso, la cancellazione annullata — sono
 * registrati nella pulizia invece di stare in fondo al test.
 */
const { test, expect } = require("../support/test");

test.describe.configure({ mode: "serial" });

test.describe("Pubblico — diritti che l'utente esercita da solo", () => {
    test("l'elenco dei consensi dice quali si possono dare e in quale versione", async ({ teacherApi }) => {
        const consensi = await teacherApi.http.getJson("/me/consents");
        expect(consensi["ok"], "la rotta risponde").toBe(true);
        expect(Array.isArray(consensi["active"]), "con i consensi in corso").toBe(true);
        expect(consensi["current_version"], "e la versione dell'informativa a cui si riferiscono").toBeTruthy();

        const disponibili = /** @type {string[]} */ (consensi["available_types"]);
        expect(disponibili, "fra i consensi c'è quello per i dati particolari").toContain("art9_bes_dsa");
        expect(disponibili, "e quello per le statistiche d'uso").toContain("analytics");
    });

    test("un consenso si dà e si ritira", async ({ teacherApi, cleanup }) => {
        cleanup.add("teacher", "ritira il consenso alle statistiche d'uso", async () => {
            await teacherApi.http.send("POST", "/me/consents/revoke", { form: { type: "analytics" } });
        });

        const dato = await teacherApi.http.postForm("/me/consents/grant", { type: "analytics" });
        expect(dato["ok"], "il consenso si registra").toBe(true);
        expect(dato["consent_id"], "con un identificativo suo").toBeTruthy();

        const conIlConsenso = /** @type {{ consent_type: string }[]} */ ((await teacherApi.http.getJson("/me/consents"))["active"]);
        expect(conIlConsenso.some((c) => c.consent_type === "analytics"), "e compare fra quelli in corso").toBe(true);

        const ritirato = await teacherApi.http.postForm("/me/consents/revoke", { type: "analytics" });
        expect(ritirato["revoked"], "il ritiro riesce").toBe(true);

        const senza = /** @type {{ consent_type: string }[]} */ ((await teacherApi.http.getJson("/me/consents"))["active"]);
        expect(senza.some((c) => c.consent_type === "analytics"), "e sparisce da quelli in corso").toBe(false);
    });

    test("un consenso che non esiste viene rifiutato, dicendo quali ci sono", async ({ teacherApi }) => {
        const tentativo = await teacherApi.http.send("POST", "/me/consents/grant", { form: { type: "consenso_inventato" } });
        expect(tentativo.status, "l'applicazione rifiuta").toBe(400);
        expect(tentativo.body?.["error"], "e dice perché").toBe("invalid_type");
        expect(/** @type {string[]} */ (tentativo.body?.["allowed"]), "elencando quelli previsti").toContain("art9_bes_dsa");
    });

    test("la cancellazione dell'account si chiede, si conferma e si può ancora annullare", async ({ teacherApi, cleanup }) => {
        cleanup.add("teacher", "annulla ogni richiesta di cancellazione rimasta aperta", async () => {
            await teacherApi.http.send("POST", "/me/cancel-deletion", { form: {} });
        });

        const chiesta = await teacherApi.http.postForm("/me/request-deletion", {
            reason: "Prova della suite end-to-end",
        });
        expect(chiesta["ok"], "la richiesta viene presa").toBe(true);
        expect(chiesta["cooling_off_days"], "con trenta giorni di ripensamento").toBe(30);
        // Fuori dalla produzione il collegamento di conferma è restituito qui:
        // in produzione arriva per posta e il test non potrebbe leggerlo.
        const gettone = String(chiesta["debug_token"] ?? "");
        expect(gettone, "e il collegamento di conferma, che qui arriva nella risposta").toBeTruthy();

        const daConfermare = await teacherApi.http.getJson("/me/deletion-status");
        expect(daConfermare["pending"], "la richiesta risulta in sospeso").toBe(true);
        expect(/** @type {any} */ (daConfermare["request"]).status, "in attesa di conferma").toBe("pending_confirm");

        // Aprire il collegamento non conferma niente (24/9/2026): alcuni
        // programmi di posta i collegamenti li aprono da soli. Conferma il
        // pulsante della pagina, cioè un POST con il gettone.
        const aperto = await teacherApi.http.getJson("/me/confirm-deletion", { token: gettone });
        expect(aperto["status"], "aprire il collegamento non conferma").toBe("pending_confirm");

        const confermata = await teacherApi.http.postForm("/me/confirm-deletion", { token: gettone });
        expect(confermata["ok"], "la conferma riesce").toBe(true);
        expect(confermata["status"], "e comincia il ripensamento").toBe("cooling_off");

        const inRipensamento = /** @type {any} */ ((await teacherApi.http.getJson("/me/deletion-status"))["request"]);
        expect(inRipensamento.status, "lo stato lo conferma").toBe("cooling_off");
        expect(inRipensamento.execute_after, "con la data in cui verrebbe eseguita").toBeTruthy();

        const annullata = await teacherApi.http.postForm("/me/cancel-deletion", {});
        expect(annullata["ok"], "l'annullamento riesce").toBe(true);

        const dopo = await teacherApi.http.getJson("/me/deletion-status");
        expect(dopo["pending"], "e non resta niente in sospeso").toBe(false);
    });

    /**
     * Lo stesso percorso dai pulsanti, come lo fa una persona (24/9/2026).
     * Fino a quel giorno «I tuoi dati» aveva un collegamento a una rotta che
     * accetta solo POST, e le risposte erano JSON grezzo. L'email la suite
     * non la può leggere: fuori dalla produzione la pagina della richiesta
     * porta il collegamento di conferma, ed è quello che si apre qui.
     */
    test("dalla pagina «I tuoi dati» la cancellazione si chiede, si conferma e si annulla con i pulsanti", async ({ teacherPage, teacherApi, cleanup }) => {
        cleanup.add("teacher", "annulla ogni richiesta di cancellazione rimasta aperta", async () => {
            await teacherApi.http.send("POST", "/me/cancel-deletion", { form: {} });
        });
        const titolo = teacherPage.getByRole("heading", { level: 1 });

        await teacherPage.goto("/privacy/your-data");
        await teacherPage.getByRole("button", { name: "Chiedi la cancellazione dell'account" }).click();
        await expect(titolo, "la risposta è una pagina, non JSON").toHaveText(/Controlla la posta|Email non inviata/);

        await teacherPage.getByRole("link", { name: "il collegamento di conferma" }).click();
        await expect(titolo, "il collegamento apre la pagina con il pulsante").toHaveText("Conferma la cancellazione");
        const aperta = /** @type {any} */ ((await teacherApi.http.getJson("/me/deletion-status"))["request"]);
        expect(aperta.status, "aprirlo non ha confermato").toBe("pending_confirm");

        await teacherPage.getByRole("button", { name: "Conferma la cancellazione dell'account" }).click();
        await expect(titolo, "il pulsante conferma").toHaveText("Cancellazione confermata");

        await teacherPage.getByRole("link", { name: "Torna a «I tuoi dati»" }).click();
        await expect(teacherPage.getByText("La cancellazione del tuo account è confermata"), "«I tuoi dati» mostra lo stato").toBeVisible();
        await teacherPage.getByRole("button", { name: "Annulla la richiesta di cancellazione" }).click();
        await expect(titolo, "e da lì si annulla").toHaveText("Cancellazione annullata");

        const dopo = await teacherApi.http.getJson("/me/deletion-status");
        expect(dopo["pending"], "e non resta niente in sospeso").toBe(false);
    });

    test("un collegamento di conferma inventato non vale", async ({ teacherApi }) => {
        const tentativo = await teacherApi.http.send("GET", "/me/confirm-deletion", {
            params: { token: "gettone_inventato_xxxxxxx" },
        });
        expect(tentativo.status, "l'applicazione rifiuta").toBe(400);
        expect(tentativo.body?.["error"], "e dice che è scaduto o non valido").toBe("token_invalid_or_expired");
    });

    test("i propri dati si correggono", async ({ teacherApi, cleanup }) => {
        const profilo = await teacherApi.http.getJson("/auth/user-info");
        const nomeOriginale = String(profilo["first_name"] ?? "");
        cleanup.add("teacher", "rimette il nome del docente com'era", async () => {
            await teacherApi.http.send("POST", "/me/profile", { form: { first_name: nomeOriginale } });
        });

        const corretto = await teacherApi.http.postForm("/me/profile", { first_name: "NomeCorretto" });
        expect(corretto["ok"], "la correzione riesce").toBe(true);
        expect(Number(corretto["updated_fields"]), "e tocca almeno un campo").toBeGreaterThanOrEqual(1);
    });

    test("un indirizzo di posta sbagliato non viene accettato nel profilo", async ({ teacherApi }) => {
        const tentativo = await teacherApi.http.send("POST", "/me/profile", { form: { email: "non-e-un-indirizzo" } });
        expect(tentativo.status, "l'applicazione rifiuta").toBe(400);
        expect(tentativo.body?.["error"], "e dice perché").toBe("invalid_email");
    });

    test("un indirizzo valido non cambia l'email da qui: serve la conferma da «Il mio account»", async ({ teacherApi }) => {
        const tentativo = await teacherApi.http.send("POST", "/me/profile", { form: { email: "nuovo.indirizzo@example.test" } });
        expect(tentativo.status, "l'applicazione non la cambia").toBe(409);
        expect(tentativo.body?.["error"], "e dice dove si cambia").toBe("email_da_confermare");
    });

    test("i propri dati si scaricano tutti, in un archivio", async ({ teacherApi }) => {
        const risposta = await teacherApi.http.request.get("/me/export-data");
        expect(risposta.ok(), "l'esportazione riesce").toBe(true);
        expect(risposta.headers()["content-disposition"], "arriva come file da salvare").toMatch(/attachment.*export/);
        expect(risposta.headers()["content-type"] ?? "", "ed è un archivio").toMatch(/zip|octet-stream/);

        const contenuto = await risposta.body();
        expect(contenuto.subarray(0, 2).toString("ascii"), "che comincia come un archivio ZIP").toBe("PK");
        expect(contenuto.length, "e non è vuoto").toBeGreaterThan(100);
    });
});
