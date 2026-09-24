// @ts-check
/**
 * Modulo per scrivere al responsabile della protezione dei dati.
 * Riscrittura di gdpr_dpo_contact.spec.js.
 *
 * L'articolo 12 del regolamento chiede che esercitare i propri diritti sia
 * facile: dev'esserci un modo per scrivere, senza account, dicendo quale
 * diritto si vuole esercitare. La pagina lo offre, elenca i diritti per
 * articolo, rimanda all'informativa e al Garante, e conferma la richiesta con
 * un numero e con il termine entro cui arriverà la risposta.
 *
 * I controlli sui campi non sono formalità: un indirizzo di posta sbagliato
 * vuol dire una risposta che non arriva, e un messaggio di tre parole non dice
 * quale diritto si stia esercitando.
 *
 * Il campo esca — invisibile a chi legge, compilato solo da un programma
 * automatico — riceve la stessa conferma di tutti, ma la richiesta non viene
 * registrata: chi manda posta indesiderata non deve capire di essere stato
 * riconosciuto.
 *
 * Cosa cambia rispetto a prima: niente gettone di sicurezza chiesto a mano, e
 * soprattutto le richieste create dai test vengono chiuse. L'intestazione della
 * spec storica prometteva di farlo («Cleanup: chiude tutte le DPO requests
 * create dai test»), ma non c'era una riga che lo facesse: ogni giro ne
 * lasciava tre aperte nel registro delle richieste.
 */
const { test, expect } = require("../support/test");

/** Manda il modulo e restituisce stato e pagina di risposta. */
async function inviaRichiesta(/** @type {import("@playwright/test").Page} */ page, /** @type {Record<string, string>} */ campi) {
    const token = (await (await page.request.get("/auth/csrf")).json()).token;
    const risposta = await page.request.post("/dpo-contact", {
        headers: { "X-CSRF-Token": token },
        form: { _csrf: token, ...campi },
    });
    return { stato: risposta.status(), html: await risposta.text() };
}

/**
 * Registra la chiusura della richiesta appena creata.
 *
 * Le richieste al responsabile non si cancellano — è un registro, e deve
 * restare — ma si chiudono con una nota: così il pannello
 * dell'amministrazione non si riempie di richieste di prova aperte.
 */
function chiudiRichiesta(/** @type {any} */ cleanup, /** @type {any} */ adminApi, /** @type {string} */ html) {
    const numero = /N°\s*(\d+)/.exec(html)?.[1];
    if (!numero) return;
    cleanup.add("admin", `chiude la richiesta al DPO n° ${numero}`, async () => {
        await adminApi.http.send("POST", `/admin/data-requests/${numero}/action`, {
            form: { action: "mark_closed", notes: "Richiesta creata dalla suite end-to-end automatica." },
        });
    });
}

test.describe.configure({ mode: "serial" });

test.describe("Pubblico — contatto con il responsabile della protezione dei dati", () => {
    test("la pagina elenca i diritti e rimanda all'informativa e al Garante", async ({ page }) => {
        const risposta = await page.request.get("/dpo-contact");
        expect(risposta.ok(), "la pagina si apre senza account").toBe(true);
        const html = await risposta.text();

        expect(html, "il titolo dice a chi si scrive").toContain("Richieste privacy ed esercizio dei diritti");
        expect(html, "e che un DPO designato non c'è").toContain("non ha un responsabile della protezione dei dati (DPO) designato");
        expect(html, "diritto di accesso").toContain("Art. 15");
        expect(html, "diritto all'oblio").toContain("Art. 17");
        expect(html, "diritto alla portabilità").toContain("Art. 20");
        expect(html, "il rimando all'autorità di controllo").toContain("Garante Privacy");
        expect(html, "e quello all'informativa").toContain("/privacy/informativa");
        expect(html, "il campo esca contro i programmi automatici c'è").toContain('name="url_field"');
    });

    test("una richiesta ben scritta viene presa in carico, con numero e termine", async ({ page, adminApi, cleanup }) => {
        const { stato, html } = await inviaRichiesta(page, {
            name: "Persona Di Prova",
            email: "prova@example.local",
            subject: "access",
            message: "Vorrei ottenere copia dei miei dati personali (Art. 15).",
        });
        chiudiRichiesta(cleanup, adminApi, html);

        expect(stato, "la richiesta viene accettata").toBe(200);
        expect(html, "e la pagina lo conferma").toContain("Richiesta ricevuta");
        expect(html, "con il numero della richiesta").toMatch(/N°\s*\d+/);
        expect(html, "e il termine entro cui arriverà la risposta").toContain("30 giorni");
    });

    /**
     * Una segnalazione di violazione non è una richiesta sui propri dati, e
     * non ha lo stesso orologio: l'articolo 33 dà settantadue ore da quando il
     * titolare ne viene a conoscenza, e la conoscenza comincia con questo
     * modulo. Fino al 22 settembre 2026 la ricevuta era la stessa per tutti e
     * nove gli oggetti, e prometteva un mese anche a chi segnalava una fuga.
     */
    test("a chi segnala una violazione si dicono le 72 ore, non i 30 giorni", async ({ page, adminApi, cleanup }) => {
        const { stato, html } = await inviaRichiesta(page, {
            name: "Persona Di Prova",
            email: "segnalante@example.local",
            subject: "breach_report",
            message: "Ho visto il nome di uno studente dentro un documento pubblicato sulla piattaforma.",
        });
        chiudiRichiesta(cleanup, adminApi, html);

        expect(stato, "la segnalazione viene accettata").toBe(200);
        expect(html, "e la pagina lo conferma").toContain("Richiesta ricevuta");
        expect(html, "con il termine vero").toContain("72 ore");
        expect(html, "e l'articolo che lo fissa").toContain("Art. 33");
        expect(html, "senza promettere un mese").not.toContain("30 giorni");
        expect(html, "e dicendo che i dati di un altro titolare si girano a lui").toContain("scuola");
    });

    test("una richiesta che riguarda un minore viene accettata come le altre", async ({ page, adminApi, cleanup }) => {
        const { stato, html } = await inviaRichiesta(page, {
            name: "Genitore Di Prova",
            email: "genitore@example.local",
            subject: "erasure",
            message: "Sono il genitore di uno studente minorenne e chiedo la cancellazione dell'account.",
            is_minor_related: "1",
        });
        chiudiRichiesta(cleanup, adminApi, html);

        expect(stato, "la richiesta viene accettata").toBe(200);
        expect(html, "e la pagina lo conferma").toContain("Richiesta ricevuta");
    });

    test("un indirizzo di posta sbagliato viene rifiutato", async ({ page }) => {
        const { stato, html } = await inviaRichiesta(page, {
            name: "Prova",
            email: "non-e-un-indirizzo",
            subject: "access",
            message: "Un messaggio abbastanza lungo da passare l'altro controllo.",
        });
        expect(stato, "l'applicazione rifiuta").toBe(400);
        expect(html, "e dice che cosa non va").toContain("Email non valida");
    });

    test("un messaggio troppo corto viene rifiutato", async ({ page }) => {
        const { stato, html } = await inviaRichiesta(page, {
            name: "Prova",
            email: "prova@example.local",
            subject: "access",
            message: "troppo breve",
        });
        expect(stato, "l'applicazione rifiuta").toBe(400);
        expect(html, "e dice quanto dev'essere lungo").toContain("tra 20 e 8192");
    });

    test("un diritto che non esiste viene rifiutato", async ({ page }) => {
        const { stato } = await inviaRichiesta(page, {
            name: "Prova",
            email: "prova@example.local",
            subject: "diritto_inventato",
            message: "Un messaggio abbastanza lungo da passare l'altro controllo.",
        });
        expect(stato, "l'applicazione rifiuta un oggetto che non è fra quelli previsti").toBe(400);
    });

    test("chi compila il campo esca riceve la stessa conferma, ma non lascia richieste", async ({ page, adminApi }) => {
        const prima = await adminApi.http.request.get("/admin/data-requests");
        const quanteprima = ((await prima.text()).match(/N°\s*\d+|data-requests\/\d+/g) ?? []).length;

        const { stato, html } = await inviaRichiesta(page, {
            name: "Programma Automatico",
            email: "spam@example.local",
            subject: "other",
            message: "Messaggio inviato da un programma automatico per riempire il modulo.",
            url_field: "http://esempio-di-spam.local",
        });
        expect(stato, "la risposta è quella di sempre").toBe(200);
        expect(html, "con la stessa conferma").toContain("Richiesta ricevuta");
        expect(html, "ma senza un numero di richiesta, perché non è stata registrata").not.toMatch(/N°\s*\d+/);

        const dopo = await adminApi.http.request.get("/admin/data-requests");
        const quantedopo = ((await dopo.text()).match(/N°\s*\d+|data-requests\/\d+/g) ?? []).length;
        expect(quantedopo, "e il registro non è cresciuto").toBe(quanteprima);
    });
});
