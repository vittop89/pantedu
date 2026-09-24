// @ts-check
/**
 * A chi è visibile un modello dell'Istituto, e perché lo si è cambiato.
 * Riscrittura di b3_visibility_scope.spec.js e b4_audit_reason.spec.js.
 *
 * Un modello dell'Istituto può essere aperto a tutti, riservato a un
 * indirizzo o a una classe, oppure chiuso. Lo decide un amministratore, e
 * ogni cambiamento passa da un controllo che pretende una motivazione scritta:
 * senza, la mutazione non viene eseguita. È il registro degli accessi
 * privilegiati — chi ha cambiato che cosa e perché — e vale anche in lettura
 * sui dati personali.
 *
 * Le due spec storiche battevano sulla stessa rotta: una per il permesso, una
 * per la motivazione. Qui sono un file solo, perché è un comportamento solo.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec e nessun contesto di
 * browser aperto a mano, niente gettone di sicurezza chiesto a mano, e il
 * ripristino del permesso è registrato nella pulizia invece di stare in un
 * `finally` annidato in un altro `finally`.
 */
const { test, expect } = require("../support/test");

const MOTIVAZIONE = "Modifica del permesso di visibilità per la suite end-to-end";

/** Il primo modello dell'Istituto: quelli senza proprietario. */
async function modelloIstituzionale(/** @type {any} */ api) {
    const modelli = await api.risdoc.templates({ origin: "risdoc" });
    const istituzionale = modelli.find((/** @type {any} */ m) => !m.owner_id) ?? modelli[0];
    expect(istituzionale, "l'Istituto ha almeno un modello").toBeTruthy();
    return istituzionale;
}

/** Registra il ritorno del modello alla visibilità aperta. */
function ripristinaVisibilita(/** @type {any} */ cleanup, /** @type {any} */ adminApi, /** @type {number} */ id) {
    cleanup.add("admin", `rimette il modello ${id} visibile a tutti`, async () => {
        await adminApi.risdoc.setVisibilityScope(id, { scope: "public" });
    });
}

test.describe("Amministrazione — visibilità dei modelli e motivazione", () => {
    test("il permesso si salva, con i suoi dettagli", async ({ adminApi, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);

        const aperto = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "public" });
        expect(aperto.ok, `apertura a tutti → ${aperto.status}`).toBe(true);
        expect(aperto.body?.["visibility_scope"], "il permesso salvato").toBe("public");
        expect(aperto.body?.["scope_indirizzo"], "senza indirizzo, perché vale per tutti").toBeFalsy();

        const perIndirizzo = await adminApi.risdoc.setVisibilityScope(modello.id, {
            scope: "indirizzo",
            scope_indirizzo: "sc",
        });
        expect(perIndirizzo.ok, `restrizione a un indirizzo → ${perIndirizzo.status}`).toBe(true);
        expect(perIndirizzo.body?.["visibility_scope"], "il permesso salvato").toBe("indirizzo");
        expect(perIndirizzo.body?.["scope_indirizzo"], "con l'indirizzo a cui è riservato").toBe("sc");
    });

    test("un permesso inventato e un indirizzo vuoto vengono rifiutati", async ({ adminApi, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);

        const inventato = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "private" });
        expect(inventato.status, "un permesso che non esiste è rifiutato").toBe(400);

        const senzaIndirizzo = await adminApi.risdoc.setVisibilityScope(modello.id, {
            scope: "indirizzo",
            scope_indirizzo: "",
        });
        expect(senzaIndirizzo.status, "riservarlo a nessun indirizzo è rifiutato").toBe(400);
    });

    test("un docente non può cambiare a chi è visibile un modello dell'Istituto", async ({ adminApi, teacher2Api }) => {
        const modello = await modelloIstituzionale(adminApi);
        const tentativo = await teacher2Api.risdoc.setVisibilityScope(modello.id, { scope: "public" });
        expect(tentativo.status, "l'applicazione lo vieta").toBe(403);
    });

    test("con il modello aperto a tutti, ogni docente lo vede", async ({ adminApi, teacher2Api, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);
        await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "public" });

        const visti = await teacher2Api.risdoc.templates({ origin: "risdoc" });
        expect(visti.some((m) => m.id === modello.id), "il modello aperto è nell'elenco dell'altro docente").toBe(true);
    });

    test("con il modello chiuso, l'altro docente non ne legge il contenuto", async ({ adminApi, teacher2Api, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);
        const scheda = await adminApi.risdoc.detail(modello.id);

        const chiuso = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "denied" });
        expect(chiuso.ok, `chiusura → ${chiuso.status}`).toBe(true);

        const lettura = await teacher2Api.risdoc.file(modello.id, { kind: "html", path: scheda.html_file });
        // Quel che conta è che non arrivi il contenuto: la rotta può negare
        // esplicitamente oppure rispondere che il file non c'è.
        const corpo = String(lettura.body?.body ?? "");
        expect(corpo, "il corpo del modello chiuso non arriva").toBe("");
    });

    test("senza motivazione la mutazione non viene eseguita", async ({ adminApi, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);

        // La motivazione la mette il client della suite su ogni scrittura
        // (tests/e2e/support/api/http.ts): qui va tolta apposta, perché è
        // proprio il caso senza.
        const senza = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "public" }, "");
        expect(senza.status, "l'applicazione rifiuta").toBe(400);
        expect(senza.body?.["error"], "e dice che manca la motivazione").toBe("audit_reason_required");

        const troppoCorta = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "public" }, "ok");
        expect(troppoCorta.status, "una motivazione di due lettere vale come mancante").toBe(400);
    });

    test("la motivazione vale sia nell'intestazione sia nel corpo della richiesta", async ({ adminApi, cleanup }) => {
        const modello = await modelloIstituzionale(adminApi);
        ripristinaVisibilita(cleanup, adminApi, modello.id);

        const conIntestazione = await adminApi.risdoc.setVisibilityScope(modello.id, { scope: "public" }, MOTIVAZIONE);
        expect(conIntestazione.ok, `motivazione nell'intestazione → ${conIntestazione.status}`).toBe(true);

        const nelCorpo = await adminApi.risdoc.setVisibilityScope(
            modello.id,
            { scope: "public", _audit_reason: MOTIVAZIONE },
            "",
        );
        expect(nelCorpo.ok, `motivazione nel corpo → ${nelCorpo.status}`).toBe(true);
    });
});
