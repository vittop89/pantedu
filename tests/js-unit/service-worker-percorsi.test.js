import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import vm from "node:vm";

/**
 * Quali navigazioni prende in mano il service worker (public/sw.js).
 *
 * Il 15/9/2026 il primo clic su «Collega Drive» ha mostrato la pagina offline
 * del service worker, pur essendo arrivato al server; ricaricando, il
 * collegamento è andato. Non si è riprodotto: con il vero sw.js, un rimando a
 * un'altra origine, il doppio clic e gli header di produzione, Chromium arriva
 * sempre a destinazione. Il passaggio da e verso Google, però, non ha niente da
 * guadagnare da una pagina offline: il service worker se ne tiene fuori, e se
 * ricapita si vede l'errore vero del browser.
 *
 * Qui si esegue il file vero in un contesto finto e si guarda se il gestore di
 * `fetch` chiama `respondWith`. Nei due versi: fuori per Drive, dentro per le
 * altre pagine sotto `/teacher/` e per il cruscotto.
 *
 * Dal 23/9/2026 anche **che cosa resta in cache** (revisione architetturale
 * del 23/9, A-72 e A-73), con cache finte che si possono guardare dentro:
 * - il QR del portachiavi (/accesso-classe/pacchetto.svg) e le API
 *   dell'amministrazione il service worker non li tocca;
 * - una risposta `no-store`, una 500 o una risposta opaca non entrano, e ciò
 *   che non potrebbe entrare, se c'è già, non si consegna: in tutte e tre le
 *   strategie (cache-first, network-first, stale-while-revalidate);
 * - sotto /api/ entra **solo** il JSON che il server dichiara `public`: i JSON
 *   privati dei docenti (`private, max-age=…`, ETag) restano fuori, e senza
 *   rete torna il 503 in JSON (decisione del 23/9, scritta in cima a sw.js);
 * - pagine e asset statici che non dicono `no-store` entrano;
 * - la versione nuova butta via le cache della v14, e il messaggio
 *   PURGE_AUTH butta quelle di pagine e API;
 * e dove si buttano le cache di pagine e API (`vaPulitaLaCache`, e `avvia`,
 * che bootstrap.js chiama a ogni pagina): anche sulle pagine rese per un
 * ospite, non solo su /login.
 *
 * I nomi delle cache si leggono dal sorgente (CACHE_VERSION): scritti a mano,
 * al prossimo cambio di versione le prove che mettono una copia in cache
 * l'avrebbero messa in una cache che il service worker non apre, e sarebbero
 * passate senza guardare niente. Ognuna ha accanto la controprova che la
 * copia, quando si può consegnare, viene consegnata davvero.
 */

const ORIGINE = "https://pantedu.example";
const SORGENTE = readFileSync(resolve(__dirname, "../../public/sw.js"), "utf8");
const VERSIONE = /^const CACHE_VERSION = "([^"]+)";$/m.exec(SORGENTE)?.[1];
if (!VERSIONE) throw new Error("CACHE_VERSION non trovata in public/sw.js: i nomi delle cache nelle prove sarebbero inventati");
const STATICA = `pantedu-static-${VERSIONE}`;
const PAGINE = `pantedu-pages-${VERSIONE}`;
const API = `pantedu-api-${VERSIONE}`;

let gestori;

beforeAll(() => {
    gestori = {};
    const sorgente = SORGENTE;
    const self = {
        location: { origin: ORIGINE },
        addEventListener: (tipo, gestore) => { gestori[tipo] = gestore; },
        skipWaiting: () => {},
        clients: { claim: async () => {} },
    };
    vm.runInNewContext(sorgente, {
        self,
        caches: { open: async () => ({ match: async () => undefined, put: async () => {} }), match: async () => undefined, keys: async () => [] },
        fetch: vi.fn(async () => new Response("")),
        URL,
        Headers,
        Response,
        console,
        setTimeout,
        clearTimeout,
    });
});

/** Una navigazione del browser verso `percorso`: il service worker risponde lui? */
function laPrendeInMano(percorso) {
    const respondWith = vi.fn();
    gestori.fetch({
        request: {
            method: "GET",
            url: ORIGINE + percorso,
            mode: "navigate",
            headers: new Headers({ Accept: "text/html" }),
        },
        respondWith,
    });
    return respondWith.mock.calls.length > 0;
}

describe("service worker: le navigazioni del collegamento a Drive", () => {
    it("il gestore di fetch c'è", () => {
        expect(typeof gestori.fetch).toBe("function");
    });

    it.each([
        "/teacher/drive/connect",
        "/teacher/drive/connect-migration",
        "/teacher/drive/callback?state=abc&code=xyz",
    ])("%s va al browser, senza il service worker", (percorso) => {
        expect(laPrendeInMano(percorso)).toBe(false);
    });

    it.each([
        "/area-docente/dashboard",
        "/teacher/maps",
        "/teacher/drivers",
    ])("controprova: %s resta al service worker", (percorso) => {
        expect(laPrendeInMano(percorso)).toBe(true);
    });
});

// ----------------- Che cosa resta in cache (23/9/2026) -----------------

/**
 * Il service worker vero, con cache finte che si possono guardare dentro e una
 * rete che ogni prova decide. `rete` riceve la richiesta e restituisce una
 * Response, o lancia per dire «senza rete».
 *
 * @param {{ apiStaleOk?: string[] }} [opzioni]  percorsi da aggiungere ad
 *   API_STALE_OK, che oggi è vuoto: senza, lo stale-while-revalidate delle API
 *   non si raggiunge.
 */
function ambiente({ apiStaleOk = [] } = {}) {
    /** @type {Map<string, Map<string, Response>>} nome della cache → (url → risposta) */
    const cache = new Map();
    const apri = (nome) => {
        if (!cache.has(nome)) cache.set(nome, new Map());
        return cache.get(nome);
    };
    const chiave = (r) => (typeof r === "string" ? new URL(r, ORIGINE).href : r.url);
    const cacheFinte = {
        open: async (nome) => {
            const c = apri(nome);
            return {
                match: async (r) => c.get(chiave(r))?.clone(),
                put: async (r, risposta) => { c.set(chiave(r), risposta); },
            };
        },
        match: async (r) => {
            for (const c of cache.values()) {
                const trovata = c.get(chiave(r));
                if (trovata) return trovata.clone();
            }
            return undefined;
        },
        keys: async () => [...cache.keys()],
        delete: async (nome) => cache.delete(nome),
    };
    let rete = async () => { throw new TypeError("Failed to fetch"); };
    const gestori = {};
    const contesto = vm.createContext({
        self: {
            location: { origin: ORIGINE },
            addEventListener: (tipo, gestore) => { gestori[tipo] = gestore; },
            skipWaiting: () => {},
            clients: { claim: async () => {} },
        },
        caches: cacheFinte,
        fetch: (req) => rete(req),
        URL,
        Headers,
        Response,
        console,
        setTimeout,
        clearTimeout,
    });
    vm.runInContext(SORGENTE, contesto);
    // Le dichiarazioni `const` del sorgente stanno nell'ambito globale del
    // contesto, e un secondo script le vede.
    for (const p of apiStaleOk) vm.runInContext(`API_STALE_OK.push(${JSON.stringify(p)})`, contesto);
    return {
        gestori,
        cache,
        apri,
        /** @param {(req: any) => Promise<Response>} fn */
        rete(fn) { rete = fn; },
        senzaRete() { rete = async () => { throw new TypeError("Failed to fetch"); }; },
        /** L'URL sta in una qualunque delle cache? */
        inCache: (percorso) => [...cache.values()].some((c) => c.has(ORIGINE + percorso)),
        /** L'URL sta nella cache `nome`? */
        inQuella: (nome, percorso) => cache.get(nome)?.has(ORIGINE + percorso) ?? false,
        /** Mette una copia nella cache `nome`, come se ci fosse entrata prima. */
        metti(nome, percorso, copia) { apri(nome).set(ORIGINE + percorso, copia); },
        /**
         * Una GET del browser verso `percorso`: chi risponde, e con che cosa.
         * @param {string} percorso
         * @param {{ navigazione?: boolean, accept?: string }} [opzioni]
         */
        async chiedi(percorso, { navigazione = false, accept } = {}) {
            let promessa;
            const respondWith = vi.fn((p) => { promessa = p; });
            gestori.fetch({
                request: {
                    method: "GET",
                    url: ORIGINE + percorso,
                    mode: navigazione ? "navigate" : "cors",
                    headers: new Headers(accept ? { Accept: accept } : {}),
                },
                respondWith,
            });
            return { presa: respondWith.mock.calls.length > 0, risposta: promessa ? await promessa : undefined };
        },
        /** Un messaggio dalla pagina al service worker; si aspetta il suo waitUntil. */
        async messaggio(dati) {
            let attesa;
            gestori.message({ data: dati, waitUntil: (p) => { attesa = p; } });
            await attesa;
        },
    };
}

/** Una risposta del server, con le intestazioni che contano qui. */
function risposta(corpo, { tipo = "application/json; charset=UTF-8", cache, stato = 200 } = {}) {
    const intestazioni = { "Content-Type": tipo };
    if (cache) intestazioni["Cache-Control"] = cache;
    return new Response(corpo, { status: stato, headers: intestazioni });
}

/**
 * Una risposta opaca, come quella di un rimando seguito in modo manuale
 * (`opaqueredirect`): stato 0, `ok` falso, nessuna intestazione leggibile.
 * Response di Node non ne costruisce, quindi si finge.
 */
function opaca() {
    const r = { type: "opaqueredirect", status: 0, ok: false, headers: new Headers(), clone: () => opaca() };
    return r;
}

// Com'è oggi quasi ogni risposta dell'applicazione: la sessione PHP mette
// questo Cache-Control su tutto ciò che non ne dichiara un altro (misurato il
// 23/9 sul server locale).
const DELLA_SESSIONE = "no-store, no-cache, must-revalidate";
// Com'è una risposta con ETag (Response::withETag): i contenuti del docente.
const CON_ETAG = "private, max-age=0, must-revalidate";
// Una risposta che il server dichiara conservabile da chiunque.
const PUBBLICA = "public, max-age=300";

describe("service worker: i nomi delle cache vengono dal sorgente", () => {
    it("CACHE_VERSION si legge da public/sw.js", () => {
        expect(VERSIONE).toMatch(/^v\d+$/);
        expect(SORGENTE).toContain("const STATIC_CACHE = `pantedu-static-${CACHE_VERSION}`;");
        expect(SORGENTE).toContain("const PAGES_CACHE  = `pantedu-pages-${CACHE_VERSION}`;");
        expect(SORGENTE).toContain("const API_CACHE    = `pantedu-api-${CACHE_VERSION}`;");
    });
});

describe("service worker: il QR del portachiavi e le API dell'amministrazione", () => {
    it.each([
        ["come pagina (il link apre una scheda)", { navigazione: true, accept: "text/html,*/*" }],
        ["come immagine", { accept: "image/svg+xml,image/*" }],
    ])("/accesso-classe/pacchetto.svg %s: il service worker non lo tocca e non lo conserva", async (_, opzioni) => {
        const amb = ambiente();
        amb.rete(async () => risposta("<svg>QR con i gettoni</svg>", { tipo: "image/svg+xml; charset=utf-8", cache: "no-store" }));
        const { presa } = await amb.chiedi("/accesso-classe/pacchetto.svg", opzioni);
        expect(presa).toBe(false);
        expect(amb.inCache("/accesso-classe/pacchetto.svg")).toBe(false);
    });

    it.each(["/accesso-classe", "/accesso-classe/qr/abc,def"])(
        "%s va al browser, senza il service worker",
        async (percorso) => {
            const amb = ambiente();
            amb.rete(async () => risposta("<html>portachiavi</html>", { tipo: "text/html; charset=UTF-8" }));
            expect((await amb.chiedi(percorso, { navigazione: true, accept: "text/html" })).presa).toBe(false);
        },
    );

    it("le API dell'amministrazione vanno al browser, anche se si dichiarano pubbliche", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta('{"utenti":[]}', { cache: PUBBLICA }));
        const { presa } = await amb.chiedi("/api/admin/users");
        expect(presa).toBe(false);
        expect(amb.inCache("/api/admin/users")).toBe(false);
    });

    it("controprova: un'immagine qualunque resta al service worker, e in cache", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("<svg>logo</svg>", { tipo: "image/svg+xml", cache: "max-age=2592000" }));
        const { presa, risposta: data } = await amb.chiedi("/img/logo.svg", { accept: "image/*" });
        expect(presa).toBe(true);
        expect(await data.text()).toBe("<svg>logo</svg>");
        expect(amb.inQuella(STATICA, "/img/logo.svg")).toBe(true);
    });
});

describe("service worker: le risposte no-store non entrano in cache", () => {
    it.each([
        DELLA_SESSIONE,
        "private, no-store",
        "No-Store",
        "public, no-store",
    ])("una GET sotto /api/ con Cache-Control «%s» arriva, ma non resta", async (direttive) => {
        const amb = ambiente();
        amb.rete(async () => risposta('{"verifiche":[1,2]}', { cache: direttive }));
        const { presa, risposta: data } = await amb.chiedi("/api/verifica/list");
        expect(presa).toBe(true);
        expect(await data.json()).toEqual({ verifiche: [1, 2] });
        expect(amb.inCache("/api/verifica/list")).toBe(false);
    });

    it("una pagina no-store non entra nella cache delle pagine", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("<html>pagina</html>", { tipo: "text/html; charset=UTF-8", cache: DELLA_SESSIONE }));
        const { presa } = await amb.chiedi("/legal/privacy", { navigazione: true, accept: "text/html" });
        expect(presa).toBe(true);
        expect(amb.inCache("/legal/privacy")).toBe(false);
    });

    it("controprova: una pagina che non dice no-store entra nella cache delle pagine", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("<html>pagina</html>", { tipo: "text/html; charset=UTF-8", cache: "max-age=0" }));
        await amb.chiedi("/legal/privacy", { navigazione: true, accept: "text/html" });
        expect(amb.inQuella(PAGINE, "/legal/privacy")).toBe(true);
    });

    it("un asset no-store non entra nella cache statica", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("<svg/>", { tipo: "image/svg+xml", cache: "no-store" }));
        await amb.chiedi("/img/generata.svg", { accept: "image/*" });
        expect(amb.inCache("/img/generata.svg")).toBe(false);
    });
});

describe("service worker: sotto /api/ si conserva solo il JSON dichiarato public", () => {
    it.each([
        ["public con max-age", PUBBLICA],
        ["public da sola", "public"],
        ["in maiuscolo", "Public, Max-Age=60"],
    ])("una GET JSON %s entra nella cache delle API, e senza rete torna indietro", async (_, direttive) => {
        const amb = ambiente();
        amb.rete(async () => risposta('{"elenco":["a"]}', { cache: direttive }));
        await amb.chiedi("/api/institutes");
        expect(amb.inQuella(API, "/api/institutes")).toBe(true);
        amb.senzaRete();
        const { risposta: data } = await amb.chiedi("/api/institutes");
        expect(data.status).toBe(200);
        expect(await data.json()).toEqual({ elenco: ["a"] });
    });

    it.each([
        ["con ETag (i contenuti del docente)", CON_ETAG],
        ["privata con max-age (lo studio, la barra laterale)", "private, max-age=60"],
        ["senza Cache-Control", undefined],
        ["con max-age e basta", "max-age=60"],
        ["public e private insieme", "public, private"],
        ["public con private su un campo", 'public, private="Set-Cookie"'],
    ])("una GET JSON %s non entra, e senza rete torna il 503 in JSON", async (_, direttive) => {
        const amb = ambiente();
        amb.rete(async () => risposta('{"righe":["appunti"]}', { cache: direttive }));
        const { risposta: fresca } = await amb.chiedi("/api/teacher/content");
        expect(await fresca.json()).toEqual({ righe: ["appunti"] });
        expect(amb.inCache("/api/teacher/content")).toBe(false);
        amb.senzaRete();
        const { risposta: data } = await amb.chiedi("/api/teacher/content");
        expect(data.status).toBe(503);
        expect(await data.json()).toEqual({ ok: false, error: "offline" });
    });

    it("controprova: una 200 public in HTML (la pagina di accesso) non entra", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("<!doctype html><title>Login</title>", { tipo: "text/html; charset=UTF-8", cache: PUBBLICA }));
        await amb.chiedi("/api/institutes");
        expect(amb.inCache("/api/institutes")).toBe(false);
    });

    it("una copia privata già nella cache delle API non si consegna: senza rete, 503", async () => {
        const amb = ambiente();
        amb.metti(API, "/api/teacher/content", risposta('{"righe":["di ieri"]}', { cache: CON_ETAG }));
        const { risposta: data } = await amb.chiedi("/api/teacher/content");
        expect(data.status).toBe(503);
    });

    // Il QR di una credenziale e i file dei modelli condivisi stanno sotto /api/
    // ma finiscono con un'estensione da asset: prima passavano dal ramo degli
    // asset statici (cache-first) e la regola di /api/ non li vedeva (23/9/2026).
    it.each([
        ["il QR di una credenziale", "/api/teacher/credentials/5/qr.svg", "image/svg+xml", "private, max-age=60"],
        ["il QR senza Cache-Control", "/api/teacher/credentials/5/qr.svg", "image/svg+xml", undefined],
        ["un file JS di un modello condiviso", "/api/risdoc/shared/abc/modello.js", "text/javascript", "max-age=300"],
        ["un CSS di un modello condiviso", "/api/risdoc/shared/abc/modello.css", "text/css", undefined],
    ])("%s, sotto /api/ con un'estensione da asset, non entra in nessuna cache", async (_, percorso, tipo, direttive) => {
        const amb = ambiente();
        amb.rete(async () => risposta("contenuto", { tipo, cache: direttive }));
        const { risposta: fresca } = await amb.chiedi(percorso);
        expect(fresca.status).toBe(200);
        expect(amb.inCache(percorso)).toBe(false);
    });

    it("una copia di un QR già nella cache statica non si consegna: senza rete, 503 in JSON", async () => {
        const amb = ambiente();
        amb.metti(STATICA, "/api/teacher/credentials/5/qr.svg", risposta("<svg/>", { tipo: "image/svg+xml", cache: "max-age=60" }));
        amb.senzaRete();
        const { risposta: data } = await amb.chiedi("/api/teacher/credentials/5/qr.svg");
        expect(data.status).toBe(503);
    });

    it("controprova: una copia public già nella cache delle API, senza rete, si consegna", async () => {
        const amb = ambiente();
        amb.metti(API, "/api/institutes", risposta('{"elenco":["di ieri"]}', { cache: PUBBLICA }));
        const { risposta: data } = await amb.chiedi("/api/institutes");
        expect(data.status).toBe(200);
        expect(await data.json()).toEqual({ elenco: ["di ieri"] });
    });
});

describe("service worker: network-first, alla consegna si guarda di nuovo", () => {
    /** La pagina offline, come la mette in cache l'installazione. */
    function conPaginaOffline(amb) {
        amb.metti(STATICA, "/offline.html", risposta("<html>offline</html>", { tipo: "text/html" }));
    }

    it("una pagina no-store già in cache, senza rete, non si consegna: arriva offline.html", async () => {
        const amb = ambiente();
        conPaginaOffline(amb);
        amb.metti(PAGINE, "/legal/privacy", risposta("<html>di chi c'era prima</html>", { tipo: "text/html", cache: DELLA_SESSIONE }));
        const { risposta: data } = await amb.chiedi("/legal/privacy", { navigazione: true, accept: "text/html" });
        expect(await data.text()).toBe("<html>offline</html>");
    });

    it("controprova: una pagina conservabile già in cache, senza rete, si consegna", async () => {
        const amb = ambiente();
        conPaginaOffline(amb);
        amb.metti(PAGINE, "/legal/privacy", risposta("<html>privacy</html>", { tipo: "text/html", cache: "max-age=0" }));
        const { risposta: data } = await amb.chiedi("/legal/privacy", { navigazione: true, accept: "text/html" });
        expect(await data.text()).toBe("<html>privacy</html>");
    });

    it("una copia no-store già nella cache delle API, senza rete, non si consegna", async () => {
        const amb = ambiente();
        amb.metti(API, "/api/institutes", risposta('{"elenco":["di ieri"]}', { cache: "public, no-store" }));
        const { risposta: data } = await amb.chiedi("/api/institutes");
        expect(data.status).toBe(503);
    });
});

describe("service worker: cache-first, alla consegna si guarda di nuovo", () => {
    it("una copia no-store già in cache non si consegna: si va in rete", async () => {
        const amb = ambiente();
        amb.metti(STATICA, "/img/vecchia.svg", risposta("<svg>vecchia</svg>", { tipo: "image/svg+xml", cache: "no-store" }));
        amb.rete(async () => risposta("<svg>nuova</svg>", { tipo: "image/svg+xml", cache: "max-age=60" }));
        const { risposta: data } = await amb.chiedi("/img/vecchia.svg", { accept: "image/*" });
        expect(await data.text()).toBe("<svg>nuova</svg>");
    });

    it("controprova: una copia conservabile già in cache si consegna senza andare in rete", async () => {
        const amb = ambiente();
        amb.metti(STATICA, "/img/vecchia.svg", risposta("<svg>vecchia</svg>", { tipo: "image/svg+xml", cache: "max-age=60" }));
        const { risposta: data } = await amb.chiedi("/img/vecchia.svg", { accept: "image/*" });
        expect(await data.text()).toBe("<svg>vecchia</svg>");
    });

    it("un asset di /build/ entra nella cache statica", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("console.log(1)", { tipo: "application/javascript" }));
        await amb.chiedi("/build/assets/app.abc123.js");
        expect(amb.inQuella(STATICA, "/build/assets/app.abc123.js")).toBe(true);
    });
});

describe("service worker: stale-while-revalidate (i moduli /js/)", () => {
    const MODULO = "/js/modules/esempio.js";

    it("un modulo no-store arriva, ma non entra in cache", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("export const x = 1;", { tipo: "text/javascript", cache: "no-store" }));
        const { presa, risposta: data } = await amb.chiedi(MODULO);
        expect(presa).toBe(true);
        expect(await data.text()).toBe("export const x = 1;");
        expect(amb.inCache(MODULO)).toBe(false);
    });

    it("controprova: un modulo che non dice no-store entra nella cache statica", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("export const x = 1;", { tipo: "text/javascript", cache: "max-age=86400" }));
        await amb.chiedi(MODULO);
        expect(amb.inQuella(STATICA, MODULO)).toBe(true);
    });

    it("una copia no-store già in cache non si consegna: arriva quella della rete", async () => {
        const amb = ambiente();
        amb.metti(STATICA, MODULO, risposta("vecchio", { tipo: "text/javascript", cache: "no-store" }));
        amb.rete(async () => risposta("nuovo", { tipo: "text/javascript", cache: "max-age=86400" }));
        const { risposta: data } = await amb.chiedi(MODULO);
        expect(await data.text()).toBe("nuovo");
    });

    it("controprova: una copia conservabile già in cache si consegna subito, e la rete la rinfresca", async () => {
        const amb = ambiente();
        amb.metti(STATICA, MODULO, risposta("vecchio", { tipo: "text/javascript", cache: "max-age=86400" }));
        amb.rete(async () => risposta("nuovo", { tipo: "text/javascript", cache: "max-age=86400" }));
        const { risposta: data } = await amb.chiedi(MODULO);
        expect(await data.text()).toBe("vecchio");
        // La rinfrescata corre sulla coda delle promesse: un giro di timer la
        // lascia finire.
        await new Promise((r) => setTimeout(r, 0));
        expect(await amb.cache.get(STATICA).get(ORIGINE + MODULO).clone().text()).toBe("nuovo");
    });

    describe("sotto /api/, se qualcuno aggiunge una rotta ad API_STALE_OK", () => {
        const ROTTA = "/api/elenco-innocuo";

        it("il suo JSON privato non entra in cache", async () => {
            const amb = ambiente({ apiStaleOk: [ROTTA] });
            amb.rete(async () => risposta('{"x":1}', { cache: "private, max-age=60" }));
            const { presa } = await amb.chiedi(ROTTA);
            expect(presa).toBe(true);
            expect(amb.inCache(ROTTA)).toBe(false);
        });

        it("controprova: il suo JSON public entra nella cache delle API", async () => {
            const amb = ambiente({ apiStaleOk: [ROTTA] });
            amb.rete(async () => risposta('{"x":1}', { cache: PUBBLICA }));
            await amb.chiedi(ROTTA);
            expect(amb.inQuella(API, ROTTA)).toBe(true);
        });

        it("una sua copia privata già in cache non si consegna: arriva quella della rete", async () => {
            const amb = ambiente({ apiStaleOk: [ROTTA] });
            amb.metti(API, ROTTA, risposta('{"x":"vecchio"}', { cache: CON_ETAG }));
            amb.rete(async () => risposta('{"x":"nuovo"}', { cache: CON_ETAG }));
            const { risposta: data } = await amb.chiedi(ROTTA);
            expect(await data.json()).toEqual({ x: "nuovo" });
        });

        it("controprova: una sua copia public già in cache si consegna subito", async () => {
            const amb = ambiente({ apiStaleOk: [ROTTA] });
            amb.metti(API, ROTTA, risposta('{"x":"vecchio"}', { cache: PUBBLICA }));
            amb.rete(async () => risposta('{"x":"nuovo"}', { cache: PUBBLICA }));
            const { risposta: data } = await amb.chiedi(ROTTA);
            expect(await data.json()).toEqual({ x: "vecchio" });
        });
    });
});

describe("service worker: in cache solo le risposte riuscite", () => {
    it("un asset di /build/ che risponde 500 arriva, ma non entra", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta("errore", { tipo: "text/plain", stato: 500 }));
        const { risposta: data } = await amb.chiedi("/build/assets/app.abc123.js");
        expect(data.status).toBe(500);
        expect(amb.inCache("/build/assets/app.abc123.js")).toBe(false);
    });

    it("una 500 in JSON sotto /api/, anche public, non entra: senza rete, 503", async () => {
        const amb = ambiente();
        amb.rete(async () => risposta('{"ok":false}', { cache: PUBBLICA, stato: 500 }));
        await amb.chiedi("/api/institutes");
        expect(amb.inCache("/api/institutes")).toBe(false);
        amb.senzaRete();
        expect((await amb.chiedi("/api/institutes")).risposta.status).toBe(503);
    });

    it("una risposta opaca (un rimando) a una navigazione non entra nella cache delle pagine", async () => {
        const amb = ambiente();
        amb.rete(async () => opaca());
        const { risposta: data } = await amb.chiedi("/legal/privacy", { navigazione: true, accept: "text/html" });
        expect(data.type).toBe("opaqueredirect");
        expect(amb.inCache("/legal/privacy")).toBe(false);
    });
});

describe("service worker: la versione nuova butta via le cache vecchie", () => {
    it("all'attivazione spariscono le cache della v14 (lì dentro può esserci il QR), restano le attuali", async () => {
        const amb = ambiente();
        for (const nome of ["pantedu-static-v14", "pantedu-api-v14", "pantedu-pages-v14", "di-un-altro-sito", STATICA, API]) amb.apri(nome);
        let attesa;
        amb.gestori.activate({ waitUntil: (p) => { attesa = p; } });
        await attesa;
        expect([...amb.cache.keys()].sort()).toEqual(["di-un-altro-sito", API, STATICA].sort());
    });
});

describe("service worker: il messaggio PURGE_AUTH", () => {
    function tutteETre(amb) {
        amb.metti(STATICA, "/img/logo.svg", risposta("<svg/>", { tipo: "image/svg+xml" }));
        amb.metti(PAGINE, "/legal/privacy", risposta("<html/>", { tipo: "text/html" }));
        amb.metti(API, "/api/institutes", risposta("{}", { cache: PUBBLICA }));
    }

    it("butta le cache di pagine e API, e lascia la statica", async () => {
        const amb = ambiente();
        tutteETre(amb);
        await amb.messaggio({ type: "PURGE_AUTH" });
        expect([...amb.cache.keys()]).toEqual([STATICA]);
    });

    it("controprova: un altro messaggio (SKIP_WAITING) non butta niente", async () => {
        const amb = ambiente();
        tutteETre(amb);
        await amb.messaggio({ type: "SKIP_WAITING" });
        expect([...amb.cache.keys()].sort()).toEqual([API, PAGINE, STATICA].sort());
    });
});

describe("vaPulitaLaCache: dove si buttano le cache di pagine e API", () => {
    let vaPulitaLaCache;
    beforeAll(async () => {
        ({ vaPulitaLaCache } = await import("../../js/modules/perf/sw-register.js"));
    });

    /** La barra laterale come la rende views/partials/sidebar.php. */
    function barra({ ospite }) {
        document.body.innerHTML = `<nav class="sidebar fm-sidebar" ${ospite ? 'data-fm-guest="1"' : ""}></nav>`;
    }

    it.each(["/", "/studio/matematica", "/esercizi/42"])(
        "%s resa per un ospite: sì (la scheda abbandonata, la sessione scaduta)",
        (percorso) => {
            barra({ ospite: true });
            expect(vaPulitaLaCache(percorso)).toBe(true);
        },
    );

    it.each(["/", "/area-docente/dashboard", "/studio/matematica"])(
        "controprova: %s resa per chi ha l'accesso o una credenziale di classe: no",
        (percorso) => {
            barra({ ospite: false });
            expect(vaPulitaLaCache(percorso)).toBe(false);
        },
    );

    it.each(["/login", "/register", "/accesso-classe", "/accesso-classe/"])(
        "%s: sì, anche senza barra laterale (layout shell)",
        (percorso) => {
            document.body.innerHTML = "";
            expect(vaPulitaLaCache(percorso)).toBe(true);
        },
    );

    it.each(["/loginx", "/accesso-classe-bis", "/teacher/login-log"])(
        "controprova: %s non è un confine",
        (percorso) => {
            document.body.innerHTML = "";
            expect(vaPulitaLaCache(percorso)).toBe(false);
        },
    );
});

describe("avvia: quello che bootstrap.js fa a ogni pagina", () => {
    let avvia;
    /** I messaggi arrivati al service worker. */
    let messaggi;
    /** Le registrazioni chieste al browser. */
    let registrazioni;

    beforeAll(async () => {
        ({ avvia } = await import("../../js/modules/perf/sw-register.js"));
    });

    beforeEach(() => {
        messaggi = [];
        registrazioni = [];
        const lavoratore = { postMessage: (m) => { messaggi.push(m); } };
        Object.defineProperty(navigator, "serviceWorker", {
            configurable: true,
            value: {
                controller: lavoratore,
                ready: Promise.resolve({ active: lavoratore }),
                addEventListener: () => {},
                register: async (url) => {
                    registrazioni.push(url);
                    return { addEventListener: () => {}, update: async () => {} };
                },
            },
        });
        // register() mette un controllo ogni ora: che non resti acceso.
        vi.useFakeTimers({ toFake: ["setInterval"] });
    });

    afterEach(() => {
        vi.useRealTimers();
        delete navigator.serviceWorker;
    });

    /** Un documento con la barra laterale come la rende sidebar.php, o senza. */
    function documento(barra) {
        const doc = document.implementation.createHTMLDocument("");
        if (barra === "ospite") doc.body.innerHTML = '<nav class="sidebar fm-sidebar" data-fm-guest="1"></nav>';
        if (barra === "con accesso") doc.body.innerHTML = '<nav class="sidebar fm-sidebar"></nav>';
        return doc;
    }

    it.each([
        ["/login", "nessuna"],
        ["/accesso-classe", "nessuna"],
        ["/", "ospite"],
        ["/esercizi/42", "ospite"],
    ])("%s (barra: %s): registra e butta le cache di pagine e API", async (percorso, barra) => {
        await avvia(percorso, documento(barra));
        expect(registrazioni).toEqual(["/sw.js"]);
        expect(messaggi).toEqual([{ type: "PURGE_AUTH" }]);
    });

    it.each([
        ["/area-docente/dashboard", "con accesso"],
        ["/", "con accesso"],
    ])("controprova: %s (barra: %s) registra e non butta niente", async (percorso, barra) => {
        await avvia(percorso, documento(barra));
        expect(registrazioni).toEqual(["/sw.js"]);
        expect(messaggi).toEqual([]);
    });

    it("bootstrap.js chiama avvia, e non tiene una decisione sua", () => {
        const boot = readFileSync(resolve(__dirname, "../../js/modules/bootstrap.js"), "utf8");
        expect(boot).toMatch(/import\("\.\/perf\/sw-register\.js"\)\.then\(\(m\) => m\.avvia\(\)\)/);
        expect(boot).not.toMatch(/\bm\.(register|purgeAuthCaches|vaPulitaLaCache)\(/);
    });
});
