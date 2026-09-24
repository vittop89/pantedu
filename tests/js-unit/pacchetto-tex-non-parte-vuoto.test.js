import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Il pacchetto TeX porta quello che il docente ha scritto.
 *
 * Trovato il 21/9/2026 lavorando sull'intestazione del PDF: i bottoni «ZIP» e
 * «VSCode» chiamavano l'export mandando solo il gettone e `mode=zip`. Il
 * server, non trovando `form_state`, costruiva il pacchetto dal modello vuoto:
 * il docente si scaricava un archivio senza una riga del suo lavoro. Misurato
 * sullo stesso endpoint, stessa sessione, stesso modello: senza `form_state`
 * il valore compilato non c'è, con `form_state` c'è — quindi il difetto era
 * nel chiamante.
 *
 * Nessuna prova l'aveva visto perché la suite end-to-end chiama l'API e il suo
 * aiutante `form_state` lo aggiunge sempre: misurava il server, che funziona,
 * e mai il codice del bottone. Questa prova guarda esattamente quello che esce
 * dal browser.
 */

let RisdocTemplateAdapter;
let richieste;

function rispostaJson(corpo, url) {
    return {
        ok: true, status: 200, redirected: false, url,
        headers: { get: () => "application/json" },
        clone: () => rispostaJson(corpo, url),
        json: async () => corpo,
        text: async () => JSON.stringify(corpo),
    };
}

/** Dal 21/9/2026 l'esportazione risponde con l'archivio, non con un indirizzo. */
function rispostaPacchetto(url) {
    const intestazioni = {
        "content-type": "application/zip",
        "content-disposition": 'attachment; filename="modello.zip"',
    };
    return {
        ok: true, status: 200, redirected: false, url,
        headers: { get: (k) => intestazioni[String(k).toLowerCase()] ?? null },
        clone: () => rispostaPacchetto(url),
        blob: async () => new Blob(["PK"]),
    };
}

beforeEach(async () => {
    richieste = [];
    globalThis.fetch = vi.fn(async (url, opts = {}) => {
        richieste.push({ url: String(url), body: opts.body ? String(opts.body) : "" });
        if (String(url).includes("/auth/csrf")) return rispostaJson({ token: "gettone" }, url);
        return rispostaPacchetto(url);
    });
    ({ RisdocTemplateAdapter } = await import(
        "../../js/components/pt-document/adapters/risdoc-template-adapter.js"));
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

/** Il corpo della richiesta di esportazione, già sciolto. */
function corpoDellExport() {
    const r = richieste.find((x) => x.url.includes("/export"));
    if (!r) throw new Error("nessuna richiesta di esportazione");
    return new URLSearchParams(r.body);
}

const COMPILATO = {
    fields: { profilo_classe: "CLASSE TRANQUILLA E PARTECIPE" },
    state: { classe: "3", indirizzo: "SCI", includeHeader: false },
    body_pt: [{ _type: "block", children: [{ _type: "span", text: "CLASSE TRANQUILLA E PARTECIPE" }] }],
};

describe("Il pacchetto TeX di un modello", () => {
    it("porta con sé quello che il docente ha compilato", async () => {
        const a = new RisdocTemplateAdapter(16);

        await a.exportTex(COMPILATO);

        const corpo = corpoDellExport();
        expect(corpo.get("form_state"), "senza questo il pacchetto esce vuoto").toBeTruthy();
        const mandato = JSON.parse(String(corpo.get("form_state")));
        expect(mandato.fields.profilo_classe).toBe("CLASSE TRANQUILLA E PARTECIPE");
        expect(mandato.body_pt, "e anche il documento, non solo i campi").toHaveLength(1);
    });

    it("porta anche la spunta dell'intestazione, come il percorso del PDF", async () => {
        const a = new RisdocTemplateAdapter(16);

        await a.exportTex(COMPILATO);

        const mandato = JSON.parse(String(corpoDellExport().get("form_state")));
        expect(mandato.state.includeHeader).toBe(false);
        expect(mandato.state.classe, "e il contesto resta dov'era").toBe("3");
    });

    it("senza niente da mandare chiede il modello vuoto, e non un campo vuoto", async () => {
        // È il caso del bottone di riga nell'elenco: lì un documento aperto non
        // c'è. Mandare `form_state=` vuoto non è la stessa cosa: il server
        // proverebbe a leggerlo.
        const a = new RisdocTemplateAdapter(16);

        await a.exportTex();

        const corpo = corpoDellExport();
        expect(corpo.has("form_state")).toBe(false);
        // `mode` non si manda più: valeva «zip oppure Overleaf», e Overleaf è
        // uscito il 21/9/2026 (ADR-045).
        expect(corpo.has("mode")).toBe(false);
    });

    it("il gettone va sempre, con o senza contenuto", async () => {
        const a = new RisdocTemplateAdapter(16);

        await a.exportTex(COMPILATO);

        expect(corpoDellExport().get("_csrf")).toBe("gettone");
    });
});
