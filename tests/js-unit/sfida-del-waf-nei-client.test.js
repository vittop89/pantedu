import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";

/**
 * La sfida del WAF si risolve in ogni client HTTP, non in uno solo
 * (revisione architetturale del 23/9/2026, A-18).
 *
 * Quando la sessione del WAF scade, il server risponde 403 con
 * `code: "waf_challenge"` e il gettone del proof-of-work. Solo `wafFetch`
 * (core/dom-utils.js) sapeva risolverla e ripetere la richiesta: `Api`
 * (core/api.js) e `fetchCsrf` usavano `fetch` diretta, e il chiamante
 * riceveva il 403. Segnalato in produzione come salvataggi di quesiti e
 * gruppi falliti in silenzio.
 *
 * Qui il «browser» è finto in due punti: la rete (`fetch`) e il risolutore
 * della sfida (`/js/waf/fingerprint.js`, che nella pagina vera fa il PoW e
 * annuncia `waf:resolved`). Il codice che decide se ripetere è quello vero.
 */

let Api;
let fetchCsrf;
let invalidateCsrfCache;

/** Le risposte che il server darà, nell'ordine, alle richieste non-CSRF. */
let copione;
/** Le richieste arrivate alla rete, CSRF compreso. */
let richieste;
/** I gettoni di sfida che la pagina ha provato a risolvere. */
let sfideRisolte;

function risposta(stato, corpo) {
    return new Response(JSON.stringify(corpo), {
        status: stato,
        headers: { "Content-Type": "application/json" },
    });
}

const SFIDA = { code: "waf_challenge", pow: "gettone-di-sfida", powBits: 8, mode: "invisible" };

beforeAll(async () => {
    ({ Api } = await import("../../js/modules/core/api.js"));
    ({ fetchCsrf, invalidateCsrfCache } = await import("../../js/modules/core/dom-utils.js"));
});

beforeEach(() => {
    copione = [];
    richieste = [];
    sfideRisolte = [];
    invalidateCsrfCache();
    try { sessionStorage.clear(); } catch { /* nessun sessionStorage */ }

    vi.stubGlobal("fetch", vi.fn(async (url, opzioni = {}) => {
        richieste.push({ url: String(url), metodo: opzioni.method || "GET", corpo: opzioni.body });
        const prossima = copione.shift();
        if (!prossima) throw new Error(`richiesta non prevista: ${url}`);
        return prossima;
    }));

    // Il risolutore: la pagina inserisce lo script della sfida, lui la
    // risolve e lo annuncia. Nessun file viene scaricato.
    const inserisci = document.head.appendChild.bind(document.head);
    vi.spyOn(document.head, "appendChild").mockImplementation((el) => {
        if (el.tagName === "SCRIPT" && String(el.src).includes("/js/waf/fingerprint.js")) {
            sfideRisolte.push(el.getAttribute("data-waf-pow"));
            queueMicrotask(() => window.dispatchEvent(new CustomEvent("waf:resolved", { detail: { ok: true } })));
            return el;
        }
        return inserisci(el);
    });
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

describe("Api (core/api.js) e la sfida del WAF", () => {
    it("GET: dopo la sfida risolta il chiamante riceve la risposta buona", async () => {
        copione.push(risposta(403, SFIDA), risposta(200, { quesiti: [1, 2] }));

        await expect(Api.getJson("/api/teacher/content")).resolves.toEqual({ quesiti: [1, 2] });
        expect(sfideRisolte).toEqual(["gettone-di-sfida"]);
        expect(richieste.map((r) => r.url)).toEqual(["/api/teacher/content", "/api/teacher/content"]);
    });

    it("POST: la richiesta ripetuta porta lo stesso corpo, e il salvataggio arriva", async () => {
        copione.push(
            risposta(200, { token: "gettone-csrf" }),
            risposta(403, SFIDA),
            risposta(200, { ok: true, version: 4 }),
        );

        await expect(Api.postJson("/api/teacher/content/9/update", { titolo: "Moto" }))
            .resolves.toEqual({ ok: true, version: 4 });
        const salvataggi = richieste.filter((r) => r.url.endsWith("/update"));
        expect(salvataggi).toHaveLength(2);
        expect(salvataggi[1].corpo).toBe(salvataggi[0].corpo);
        expect(salvataggi[1].metodo).toBe("POST");
    });

    it("un 403 che non è la sfida non si ripete: arriva al chiamante com'è", async () => {
        copione.push(risposta(403, { code: "forbidden", error: "forbidden" }));

        await expect(Api.getJson("/api/admin/users")).rejects.toThrow("http_403");
        expect(richieste).toHaveLength(1);
        expect(sfideRisolte).toEqual([]);
    });
});

describe("fetchCsrf e la sfida del WAF", () => {
    it("anche il gettone CSRF passa dalla sfida: senza, ogni POST partiva con un gettone vuoto", async () => {
        copione.push(risposta(403, SFIDA), risposta(200, { token: "gettone-csrf" }));

        await expect(fetchCsrf()).resolves.toBe("gettone-csrf");
        expect(sfideRisolte).toEqual(["gettone-di-sfida"]);
    });
});
