// @ts-check
/**
 * Che cosa trova nella barra chi studia con la credenziale di classe (19/9/2026).
 *
 * Misurato in locale prima della correzione, da ospite con una credenziale
 * di seconda:
 *
 *   - nel pannello «BES/DSA - RECUPERI» due «+» visibili, e il clic apriva la
 *     finestra «➕ Crea bes» con il caricamento di file fino a 50 MB; il server
 *     rifiutava il salvataggio (401), ma l'interfaccia invitava a caricare. A
 *     ogni apertura del pannello partivano due chiamate riservate ai docenti,
 *     con 401;
 *   - nel selettore delle materie undici voci, otto senza nessun materiale;
 *   - nella barra «➕ Aggiungi» («Inserisci la credenziale di un altro
 *     docente»), nello scenario 1, dove l'altro docente non esiste.
 *
 * Adesso: nessun comando di modifica, nessuna chiamata riservata, le scritture
 * rispondono 401 con il gettone vero; nel selettore solo le materie in cui
 * c'è qualcosa; la scritta «Credenziale del docente, nessun account» resta e
 * il link per un altro docente no (l'istanza della suite è nello scenario 1:
 * l'altro verso sta in tests/Integration/AltroDocentePerScenarioTest.php).
 *
 * Contenuti e credenziale li crea la suite e li cancella il registro di
 * pulizia; gli errori di console li raccoglie la diagnostica.
 */
const { test, expect, installEventRecorder } = require("../support/test");

/** Le chiamate che servono solo al docente: modelli e istanze dei documenti. */
const RISERVATE = ["/api/risdoc/templates", "/api/risdoc/teacher/instances"];

/** I tipi di contenuto che una materia può avere (TeacherContentRepository::TYPES). */
const TIPI = ["esercizio", "mappa", "verifica", "document"];

test.describe("Pubblico — che cosa vede chi studia con la credenziale", () => {
    test("nella barra non c'è nessun comando di modifica, e le scritture rispondono 401", async ({
        page, accessoClasse, portachiaviBarra, credentialFactory, contentFactory, teacherApi, naming, env,
    }) => {
        const { indirizzo, classe } = env.terna;
        await contentFactory.exercise({ terna: env.terna, title: naming.unique("chi-studia-comandi"), groups: 1, itemsPerGroup: 1, publish: true });
        const credenziale = await credentialFactory.create({ indirizzo, classe });

        await installEventRecorder(page.context());
        /** @type {string[]} */
        const riservate = [];
        page.on("request", (r) => {
            const percorso = new URL(r.url()).pathname;
            if (RISERVATE.includes(percorso)) riservate.push(`${r.method()} ${percorso}`);
        });

        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);
        await portachiaviBarra.vaiAllaHome();

        // Scenario 1: la scritta resta, il link per un altro docente no.
        await expect(portachiaviBarra.collegamenti, "la scritta dice che si è entrati senza account").toContainText("Credenziale del docente, nessun account");
        await expect(portachiaviBarra.collegamenti.getByRole("link", { name: /Aggiungi/ }), "nessun «➕ Aggiungi»: un altro docente non c'è").toHaveCount(0);

        const sezioni = await portachiaviBarra.sezioni();
        expect(sezioni.length, "la barra ha le sezioni di chi studia").toBeGreaterThan(0);
        for (const sezione of sezioni) {
            await portachiaviBarra.apriSezione(sezione);
        }
        const comandi = page.locator("#fm-sb-scroll").locator(".fm-section-add, .js-edit-section, .fm-item-actions, .fm-newcat-btn");
        await expect(comandi, `nessun comando di modifica nelle sezioni ${sezioni.join(", ")}`).toHaveCount(0);
        expect(riservate, "nessuna chiamata riservata ai docenti").toEqual([]);

        // Le scritture, con il gettone vero della sessione: 401, perché chi
        // entra con la credenziale non ha un account, quindi nemmeno un ruolo.
        const gettone = String((await (await page.request.get("/auth/csrf")).json()).token ?? "");
        expect(gettone, "il gettone CSRF della sessione").not.toBe("");
        const titolo = naming.unique("non-deve-nascere");
        for (const [percorso, campi] of /** @type {Array<[string, Record<string, string>]>} */ ([
            ["/api/teacher/content", { content_type: "document", title: titolo, topic: titolo, subject: env.terna.materia, indirizzo, classe }],
            ["/api/maps", { title: titolo }],
            ["/api/risdoc/templates/1/instances", { instance_label: titolo }],
        ])) {
            const esito = await page.request.post(percorso, {
                form: { ...campi, _csrf: gettone },
                headers: { Accept: "application/json", "X-CSRF-Token": gettone },
            });
            expect(esito.status(), `POST ${percorso} da ospite con la credenziale`).toBe(401);
        }
        const creati = await teacherApi.content.list({ q: titolo, limit: 10 });
        expect(creati.rows, "e dal lato del docente non è nato niente").toHaveLength(0);
    });

    test("nel selettore delle materie ci sono solo quelle con materiali", async ({
        page, accessoClasse, portachiaviBarra, credentialFactory, contentFactory, naming, env,
    }) => {
        const { indirizzo, classe, materia } = env.terna;
        await contentFactory.exercise({ terna: env.terna, title: naming.unique("chi-studia-materie"), groups: 1, itemsPerGroup: 1, publish: true });
        const credenziale = await credentialFactory.create({ indirizzo, classe });

        await accessoClasse.vaiA();
        await accessoClasse.entra(credenziale.username, credenziale.password);
        await portachiaviBarra.vaiAllaHome();

        const selettore = page.locator("#sel-mater");
        await expect(selettore, "il selettore è quello di chi studia").toHaveAttribute("data-fm-materie-di-chi-studia", "1");
        const opzioni = await selettore.locator("option").evaluateAll(
            (voci) => voci.map((o) => /** @type {HTMLOptionElement} */ (o).value).filter((v) => v !== ""));
        expect(opzioni, "c'è la materia dell'esercizio appena pubblicato").toContain(materia);

        for (const m of opzioni) {
            let righe = 0;
            for (const tipo of TIPI) {
                const r = await page.request.get("/api/study/content.json", {
                    params: { type: tipo, indirizzo, classe, subject: m, limit: "1" },
                    headers: { Accept: "application/json" },
                });
                expect(r.status(), `content.json ${tipo} ${m}`).toBe(200);
                righe += (await r.json()).rows.length;
                if (righe > 0) break;
            }
            expect(righe, `la materia ${m} offerta dal selettore ha almeno un materiale`).toBeGreaterThan(0);
        }

        // L'API con cui il selettore si ricalcola al cambio di classe dice lo stesso.
        const api = await page.request.get("/api/study/materie.json", {
            params: { classe, indirizzo },
            headers: { Accept: "application/json" },
        });
        expect(api.status()).toBe(200);
        /** @type {{ filtrate: boolean, materie: Array<{ code: string }> }} */
        const j = await api.json();
        expect(j.filtrate).toBe(true);
        expect(j.materie.map((x) => x.code).sort(), "stesse materie del selettore").toEqual([...opzioni].sort());
    });
});
