// @ts-check
/**
 * Percorsi che escono dalla cartella, e limite alle richieste.
 * Riscrittura di security.spec.js e rate_limit.spec.js.
 *
 * Due difese che non si vedono mai finché reggono. La prima: gli endpoint che
 * scrivono file ricevono un percorso da chi chiama, e un percorso può contenere
 * `../` per uscire dalla cartella in cui dovrebbe restare. Un tentativo del
 * genere dev'essere rifiutato, e la risposta non deve contenere niente di quel
 * che si voleva leggere — un messaggio d'errore che riporta il contenuto del
 * file è una fuga come un'altra.
 *
 * La seconda: oltre una certa quantità di richieste al minuto l'applicazione
 * risponde di riprovare più tardi. Serve contro chi prova password a raffica e
 * contro chi, anche senza cattive intenzioni, farebbe cadere il servizio.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec, niente gettone di
 * sicurezza chiesto a mano, e le rotte legacy non sono più provate «a due
 * possibili esiti»: la risposta è una, e il test dice quale.
 */
const { test, expect } = require("../support/test");

/** Percorsi che tentano di uscire dalla cartella di lavoro. */
const PERCORSI_MALEVOLI = [
    "../../../../etc/passwd",
    "../../storage/data/users.json",
    "/../../etc/passwd",
    "/../../storage/data/users.json",
];

/** Le risposte con cui l'applicazione può rifiutare: sono tutte un rifiuto. */
const RIFIUTI = [400, 401, 403, 404, 410, 419];

/** Nessuna risposta deve contenere quel che il percorso voleva leggere. */
function nessunaFuga(/** @type {string} */ corpo, /** @type {string} */ quale) {
    expect(corpo, `${quale}: la risposta contiene il file delle utenze di sistema`).not.toMatch(/root:x:0:/);
    expect(corpo, `${quale}: la risposta contiene le impronte delle password`).not.toMatch(/"password_hash"\s*:/);
}

/*
 * 2026-09-20 — qui c'erano due casi su `/files/save-image` e
 * `/files/save-tex`, tolti insieme alle due rotte (giro morto del vecchio
 * editor TikZ). Al loro posto gli endpoint della stessa famiglia **in
 * servizio**, uno per ciascuna delle due difese che quelle rotte
 * esercitavano: il nome del file (`Validator::filename`) e il percorso
 * (`Validator::webPath`).
 *
 * Il caso su `save-tex` era anche un verde che non misurava: mandava
 * `filePath` e `texContent`, mentre il controller leggeva `fileName` e
 * `fileContent`. Il 400 arrivava per il campo mancante, non per il percorso.
 * Qui il percorso malevolo sta nel campo che la difesa guarda davvero.
 */
test.describe("Sicurezza — percorsi che escono dalla cartella", () => {
    test("il salvataggio di un sorgente TeX rifiuta i nomi che escono", async ({ adminApi }) => {
        for (const percorso of PERCORSI_MALEVOLI) {
            const risposta = await adminApi.http.send("POST", "/files/save-latex", {
                form: { fileName: `${percorso}.tex`, fileContent: "\\documentclass{article}" },
            });
            expect(RIFIUTI, `«${percorso}» doveva essere rifiutato, ha risposto ${risposta.status}`).toContain(risposta.status);
            nessunaFuga(risposta.text, `save-latex «${percorso}»`);
        }
    });

    test("l'elenco dei file rifiuta i percorsi che escono", async ({ adminApi }) => {
        for (const percorso of PERCORSI_MALEVOLI) {
            const risposta = await adminApi.http.send("GET", "/files/list", { params: { directory: percorso } });
            expect(RIFIUTI, `«${percorso}» doveva essere rifiutato, ha risposto ${risposta.status}`).toContain(risposta.status);
            nessunaFuga(risposta.text, `files/list «${percorso}»`);
        }
    });

    test("le vecchie rotte dei file non servono più niente", async ({ adminPage }) => {
        for (const percorso of ["/eser/../../etc/passwd", "/verifiche/../../storage/data/users.json"]) {
            const risposta = await adminPage.request.get(percorso, { maxRedirects: 0 });
            expect(RIFIUTI, `«${percorso}» ha risposto ${risposta.status()}`).toContain(risposta.status());
            nessunaFuga(await risposta.text(), `rotta legacy «${percorso}»`);
        }
    });
});

test.describe("Sicurezza — limite alle richieste", () => {
    test("oltre la soglia l'applicazione dice di riprovare più tardi", async ({ adminApi }) => {
        const SOGLIA = 120; // quante ne può fare un amministratore in un minuto
        const MASSIMO = SOGLIA + 60; // margine: la finestra scorre mentre si prova
        const intestazioni = { "X-Pantedu-Rate-Limit": "enforce" };
        const chiedi = () => adminApi.http.send("POST", "/api/probe", { form: {}, headers: intestazioni });

        // Una richiesta sola non viene mai respinta: il limite non deve
        // ostacolare l'uso normale.
        const prima = await chiedi();
        expect(prima.status, "la prima richiesta passa").not.toBe(429);

        // Poi si insiste, a gruppi: una alla volta la finestra di un minuto
        // scorre e il contatore non arriva mai in fondo. Non si conta a quale
        // richiesta esatta scatti — il contatore è dell'utenza, e nella stessa
        // finestra ci finiscono anche le richieste degli altri test — ma che
        // scatti, e che dica come rimediare.
        let respinta = null;
        for (let fatte = 1; fatte < MASSIMO && !respinta; fatte += 30) {
            const risposte = await Promise.all(Array.from({ length: 30 }, chiedi));
            respinta = risposte.find((r) => r.status === 429) ?? null;
        }

        expect(respinta, `dopo ${MASSIMO} richieste il limite non è mai scattato`).toBeTruthy();
        expect(respinta?.body?.["error"], "la risposta spiega che cos'è successo").toBe("rate limit exceeded");
        expect(Number(respinta?.body?.["retry_after"]), "e dice fra quanto riprovare").toBeGreaterThan(0);
    });
});
