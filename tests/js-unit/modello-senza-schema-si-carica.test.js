import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Un modello senza schema si carica dal suo body_pt (23/9/2026).
 *
 * Revisione architetturale del 23/9/2026, A-23. «Crea modello»
 * (RisdocAdminController::createTemplate) fa nascere il modello con il solo
 * `body_pt` e senza `schema_path`. Il server ora lo apre
 * (TemplateViewController::show, prova tests/Integration/CreaModelloSiApreTest):
 * resta il browser. In modifica l'adattatore legge il master e funzionava già;
 * il docente, senza una compilazione salvata, chiedeva lo schema, riceveva
 * `schema_not_set` e il documento non si caricava. Adesso, se lo schema non
 * c'è, parte dal body_pt del modello. Se lo schema c'è, il master non si
 * chiede nemmeno: per i modelli di sempre non cambia niente.
 */

let RisdocTemplateAdapter;
let richieste;

const ID = 4242;
const CORPO = [
    { _type: "sectionHeader", title: "Modello nuovo", level: 1 },
    { _type: "block", style: "normal", children: [{ _type: "span", text: "Contenuti da definire", marks: [] }] },
];

function json(corpo, url, status = 200) {
    return {
        ok: status < 400, status, redirected: false, url,
        headers: { get: () => "application/json" },
        clone: () => json(corpo, url, status),
        json: async () => corpo,
        text: async () => JSON.stringify(corpo),
    };
}

/** Un server finto: niente compilazioni, lo schema come si chiede, il master col suo corpo. */
function server({ schema, master }) {
    return vi.fn(async (url) => {
        const u = String(url);
        richieste.push(u);
        if (u.endsWith(`/api/risdoc/templates/${ID}/compilations`)) return json({ compilations: [] }, u);
        if (u.endsWith(`/api/risdoc/templates/${ID}/schema`)) {
            return schema ? json(schema, u) : json({ error: "schema_not_set" }, u, 404);
        }
        if (u.startsWith(`/api/risdoc/templates/${ID}?`)) {
            return json({ ok: true, template: { id: ID, body_pt: master === null ? null : JSON.stringify(master) } }, u);
        }
        return json({ error: "not_found" }, u, 404);
    });
}

beforeEach(async () => {
    richieste = [];
    ({ RisdocTemplateAdapter } = await import(
        "../../js/components/pt-document/adapters/risdoc-template-adapter.js"));
});

afterEach(() => {
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

describe("Risdoc — un modello senza schema", () => {
    it("il docente lo apre dal body_pt del modello", async () => {
        globalThis.fetch = server({ schema: null, master: CORPO });
        const pt = await new RisdocTemplateAdapter(ID).load();
        expect(pt).toEqual(CORPO);
        expect(richieste.some((u) => u.endsWith("/schema"))).toBe(true);
    });

    it("senza schema e senza corpo l'errore dello schema arriva a chi carica", async () => {
        globalThis.fetch = server({ schema: null, master: null });
        await expect(new RisdocTemplateAdapter(ID).load()).rejects.toThrow(/schema_not_set/);
    });

    it("con lo schema il master non si chiede", async () => {
        globalThis.fetch = server({
            schema: { sections: [{ id: "s1", title: "Sezione", default: [CORPO[1]] }] },
            master: [{ _type: "block", style: "normal", children: [] }],
        });
        const pt = await new RisdocTemplateAdapter(ID).load();
        expect(Array.isArray(pt)).toBe(true);
        expect(richieste.some((u) => u.startsWith(`/api/risdoc/templates/${ID}?`))).toBe(false);
    });
});
