import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Segnalazione dell'utente (21/9/2026): «se tolgo la spunta in "Includi
 * intestazione istituto (loghi) nel PDF" e genero il PDF, l'intestazione esce
 * lo stesso».
 *
 * Il server sapeva già farlo: `ExportController::buildFiles` commenta la riga
 * dell'intestazione in main.tex quando `state.includeHeader` è falso. A non
 * funzionare era la parte davanti — nell'adapter dei modelli risdoc quelle due
 * funzioni erano stub: una rispondeva sempre «inclusa», l'altra «fatto» senza
 * scrivere niente, e il messaggio di conferma («Intestazione istituto esclusa
 * dal PDF») arrivava lo stesso. Un verde che non misurava niente.
 *
 * Qui si misura che la scelta esista davvero: che si rilegga, che parta verso
 * il server dentro lo stato della compilazione, e nei due versi (tolta e
 * rimessa).
 */

let RisdocTemplateAdapter;

/** Le richieste viste dal finto server, in ordine. */
let richieste;

function rispostaJson(corpo, url) {
    return {
        ok: true,
        status: 200,
        redirected: false,
        url,
        headers: { get: () => "application/json" },
        clone: () => rispostaJson(corpo, url),
        json: async () => corpo,
        text: async () => JSON.stringify(corpo),
    };
}

beforeEach(async () => {
    richieste = [];
    globalThis.fetch = vi.fn(async (url, opts = {}) => {
        richieste.push({ url: String(url), body: opts.body ? String(opts.body) : "" });
        if (String(url).includes("/auth/csrf")) return rispostaJson({ token: "gettone" }, url);
        return rispostaJson({ ok: true, id: 7 }, url);
    });
    ({ RisdocTemplateAdapter } = await import(
        "../../js/components/pt-document/adapters/risdoc-template-adapter.js"));
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

/** Lo stato salvato con la compilazione, letto dalla richiesta al server. */
function statoSpedito() {
    const salvataggio = richieste.find((r) => r.url.includes("/compilations"));
    if (!salvataggio) throw new Error("nessun salvataggio della compilazione");
    const data = new URLSearchParams(salvataggio.body).get("data");
    return JSON.parse(String(data)).state;
}

describe("Intestazione dell'istituto nel PDF (modelli risdoc)", () => {
    it("senza scelta l'intestazione c'è: è il comportamento di sempre", async () => {
        const a = new RisdocTemplateAdapter(16);

        await expect(a.loadIncludeHeader()).resolves.toBe(true);
    });

    it("la scelta di toglierla si rilegge", async () => {
        const a = new RisdocTemplateAdapter(16);

        await a.saveIncludeHeader(false);

        await expect(a.loadIncludeHeader()).resolves.toBe(false);
    });

    it("una compilazione salvata senza intestazione riapre senza intestazione", async () => {
        // È la strada vera: lo stato arriva dalla compilazione del docente,
        // non da una scelta fatta in questa pagina.
        const a = new RisdocTemplateAdapter(16, { state: { classe: "3", includeHeader: false } });

        await expect(a.loadIncludeHeader()).resolves.toBe(false);
    });

    it("la scelta parte verso il server dentro lo stato della compilazione", async () => {
        const a = new RisdocTemplateAdapter(16, { state: { indirizzo: "SCI", classe: "3" } });

        await a.saveIncludeHeader(false);
        await a.save([]);

        const stato = statoSpedito();
        expect(stato.includeHeader).toBe(false);
        expect(stato.classe, "il resto dello stato resta dov'era").toBe("3");
    });

    it("e rimettendola torna inclusa, non resta spenta per sempre", async () => {
        const a = new RisdocTemplateAdapter(16);

        await a.saveIncludeHeader(false);
        await a.saveIncludeHeader(true);
        await a.save([]);

        expect(await a.loadIncludeHeader()).toBe(true);
        expect(statoSpedito().includeHeader).toBe(true);
    });
});
