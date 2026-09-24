// @ts-check
/**
 * Forma delle risposte: quel che le API promettono a chi le legge.
 * Riscrittura della prima metà di interactions.spec.js.
 *
 * Non verificano un percorso dell'utente ma un contratto: se una rotta smette
 * di rispondere con l'elenco che la pagina si aspetta, la pagina resta vuota
 * senza dire niente. Sono i controlli che si guardano dopo aver toccato un
 * controller.
 *
 * Il caso più utile è quello del conteggio: la ricerca degli esercizi dichiara
 * quante righe manda, e il numero dev'essere quello delle righe — uno scarto di
 * uno lì dentro si vede solo alla fine della paginazione.
 *
 * Cosa cambia rispetto a prima: niente login nelle spec (erano tredici, uno per
 * test), e le credenziali del docente non vengono più compilate a mano dentro
 * un test per una rotta che l'amministratore non può leggere.
 */
const { test, expect } = require("../support/test");

test.describe("Qualità — contratti delle API dell'amministrazione", () => {
    test("l'amministratore è riconosciuto come tale", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/admin/whoami");
        expect(risposta["ok"], "la rotta risponde").toBe(true);
        expect(/** @type {any} */ (risposta["user"])?.role, "col ruolo di amministratore").toBe("administrator");
    });

    test("il registro degli accessi risponde con le voci recenti", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/admin/access-log", { limit: "5" });
        expect(risposta["ok"], "la rotta risponde").toBe(true);
        expect(Array.isArray(risposta["recent"]), "con l'elenco delle voci recenti").toBe(true);
    });

    test("le statistiche accettano i tipi previsti e rifiutano gli altri", async ({ adminApi }) => {
        const buone = await adminApi.http.getJson("/admin/access-stats", { type: "daily_stats" });
        expect(buone["ok"], "il tipo giornaliero risponde").toBe(true);

        const inventato = await adminApi.http.send("GET", "/admin/access-stats", { params: { type: "INVENTATO" } });
        expect(inventato.status, "un tipo che non esiste viene rifiutato").toBe(400);
    });

    test("il registro di diagnostica risponde con i propri file", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/admin/debug-log", { lines: "3" });
        expect(risposta["ok"], "la rotta risponde").toBe(true);
        expect(typeof risposta["logs"], "con i file di registro").toBe("object");
    });

    test("le iscrizioni in sospeso rispondono come elenco", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/admin/registrations");
        expect(Array.isArray(risposta["pending"]), "la rotta risponde con un elenco").toBe(true);
    });
});

test.describe("Qualità — contratti delle API pubbliche e dei contenuti", () => {
    test("il curriculum pubblico ha le sue tre dimensioni", async ({ page }) => {
        const risposta = await (await page.request.get("/curriculum")).json();
        expect(risposta.ok, "la rotta risponde senza account").toBe(true);
        for (const dimensione of ["indirizzi", "classi", "materie"]) {
            expect(risposta.curriculum, `il curriculum ha «${dimensione}»`).toHaveProperty(dimensione);
            expect(Array.isArray(risposta.curriculum[dimensione]), `«${dimensione}» è un elenco`).toBe(true);
        }
    });

    test("il curriculum letto dall'amministratore ha la stessa forma", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/curriculum");
        expect(risposta["ok"], "la rotta risponde").toBe(true);
        for (const dimensione of ["indirizzi", "classi", "materie"]) {
            expect(risposta["curriculum"], `il curriculum ha «${dimensione}»`).toHaveProperty(dimensione);
        }
    });

    test("la ricerca degli esercizi dichiara quante righe manda, e sono quelle", async ({ adminApi, env }) => {
        const risposta = await adminApi.http.getJson("/exercises/search.json", {
            materia: env.terna.materia,
            limit: "10",
        });
        expect(risposta["ok"], "la ricerca risponde").toBe(true);
        const righe = /** @type {{ materia: string }[]} */ (risposta["rows"]);

        if (typeof risposta["count"] === "number") {
            expect(risposta["count"], "il conteggio dichiarato è quello delle righe").toBe(righe.length);
        }
        for (const riga of righe) {
            expect(riga.materia, "e ogni riga rispetta il filtro").toBe(env.terna.materia);
        }
    });

    test("il limite della ricerca viene rispettato", async ({ adminApi }) => {
        const risposta = await adminApi.http.getJson("/exercises/search.json", { limit: "3" });
        expect(risposta["ok"], "la ricerca risponde").toBe(true);
        expect(/** @type {unknown[]} */ (risposta["rows"]).length, "non arrivano più righe di quante chieste")
            .toBeLessThanOrEqual(3);
    });

    test("l'elenco delle fonti comuni ha i codici che l'editor propone", async ({ teacherApi }) => {
        // Sono i libri di testo con cui si dichiara da dove viene un quesito.
        // Le due rotte leggono lo stesso registro del docente da due parti
        // diverse: `/api/sources/common` lo serve nella forma storica che usa
        // il selettore, `/api/teacher/origins.json` ne dà i soli codici. Se
        // divergono, il selettore mostra un'origine che poi non si può
        // scegliere. Non si verifica un codice in particolare: i libri sono
        // dell'istituto, e un'installazione nuova ne ha altri.
        const risposta = await teacherApi.http.getJson("/api/sources/common");
        const fonti = /** @type {Record<string, unknown>} */ (risposta["sources"] ?? {});
        const codici = Object.keys(fonti).sort();
        expect(codici.length, "le fonti ci sono").toBeGreaterThan(0);

        const origini = /** @type {string[]} */ (await teacherApi.http.getJson("/api/teacher/origins.json"));
        expect([...origini].sort(), "le origini proposte sono esattamente le fonti").toEqual(codici);

        for (const [codice, dati] of Object.entries(fonti)) {
            const fonte = /** @type {Record<string, unknown>} */ (dati);
            expect(fonte["code"], `la fonte «${codice}» porta il proprio codice`).toBe(codice);
            expect(String(fonte["title"] ?? ""), `e il titolo del libro`).not.toBe("");
        }
    });

    test("le verifiche del docente arrivano con i campi che la pagina usa", async ({ teacherApi }) => {
        const risposta = await teacherApi.http.getJson("/api/teacher/content", { content_type: "verifica" });
        expect(risposta["ok"], "la rotta risponde").toBe(true);

        const righe = /** @type {Record<string, unknown>[]} */ (risposta["rows"] ?? []);
        for (const riga of righe.slice(0, 10)) {
            for (const campo of ["id", "content_type", "title", "created_at"]) {
                expect(riga, `alla riga ${String(riga["id"])} manca «${campo}»`).toHaveProperty(campo);
            }
        }
    });
});

test.describe("Qualità — moduli dell'applicazione nel browser", () => {
    test("l'elenco degli indirizzi ha tutti i suoi spazi dei nomi", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => !!window.FM?.["Endpoints"], null, { timeout: 30_000 });

        const mancanti = await page.evaluate(() => {
            const elenco = /** @type {Record<string, unknown>} */ (window.FM?.["Endpoints"] ?? {});
            const previsti = [
                "auth", "files", "exercises", "tikz", "editor", "verifiche", "update",
                "check", "admin", "teacher", "teacherContent", "study", "analytics", "templates",
            ];
            return previsti.filter((nome) => typeof elenco[nome] !== "object" || elenco[nome] === null);
        });
        expect(mancanti, `spazi dei nomi mancanti: ${mancanti.join(", ")}`).toEqual([]);
    });

    test("nessun indirizzo primario punta più a una pagina PHP", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => !!window.FM?.["Endpoints"], null, { timeout: 30_000 });

        const residui = await page.evaluate(() => {
            const elenco = /** @type {Record<string, Record<string, unknown>>} */ (window.FM?.["Endpoints"] ?? {});
            const fuori = [];
            for (const [spazio, indirizzi] of Object.entries(elenco)) {
                // «legacy» e «templates» sono dichiaratamente vecchi.
                if (spazio === "legacy" || spazio === "templates") continue;
                for (const [nome, valore] of Object.entries(indirizzi ?? {})) {
                    if (typeof valore === "string" && /\.php(\?|$)/.test(valore)) fuori.push(`${spazio}.${nome} = ${valore}`);
                }
            }
            return fuori;
        });
        expect(residui, `indirizzi ancora su pagine PHP:\n${residui.join("\n")}`).toEqual([]);
    });

    test("il client HTTP dell'applicazione espone le sue due funzioni", async ({ page }) => {
        await page.goto("/");
        await page.waitForFunction(() => !!window.FM?.["Api"], null, { timeout: 30_000 });

        const funzioni = await page.evaluate(() => {
            const api = /** @type {Record<string, unknown>} */ (window.FM?.["Api"] ?? {});
            return { get: typeof api["getJson"], post: typeof api["postJson"] };
        });
        expect(funzioni, "getJson e postJson ci sono").toEqual({ get: "function", post: "function" });
    });
});

test.describe("Qualità — file statici dell'applicazione", () => {
    test("il modulo di avvio è servito come JavaScript", async ({ page }) => {
        const risposta = await page.request.get("/js/modules/bootstrap.js");
        expect(risposta.ok(), "il file c'è").toBe(true);
        expect(risposta.headers()["content-type"], "ed è servito come JavaScript").toMatch(/javascript/);
    });

    test("il modulo degli indirizzi esporta il proprio elenco", async ({ page }) => {
        const risposta = await page.request.get("/js/modules/core/endpoints.js");
        expect(risposta.ok(), "il file c'è").toBe(true);
        expect(await risposta.text(), "e dichiara l'elenco").toContain("export const Endpoints");
    });
});
