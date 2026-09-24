import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";

/**
 * Il pannello Verifiche si ridisegna quando arriva la configurazione della
 * barra con un raggruppamento diverso da quello con cui è stato disegnato
 * (19/9/2026, analisi C-export-bodypt).
 *
 * Misurato in sviluppo con /api/sidebar/config ritardata di 1,5 s: il pannello
 * si disegnava per categoria (base del registro), da /api/teacher/content, che
 * non diceva has_body_pt; la configurazione diceva per materia, ma popolaTutte
 * salta i pannelli già popolati, e il pannello restava com'era: niente 📥.
 */

let chieste = [];
const finto = vi.fn(async (url) => {
    chieste.push(new URL(String(url), "http://localhost"));
    return new Response(JSON.stringify({ ok: true, rows: [] }), {
        status: 200, headers: { "Content-Type": "application/json" },
    });
});

let hydrate;
let pannelliDaRidisegnare;

beforeAll(async () => {
    globalThis.fetch = finto;
    ({ hydrate } = await import("../../js/modules/features/sidepage-registry.js"));
    ({ pannelliDaRidisegnare } = await import("../../js/modules/features/db-sidepage.js"));
    // init() parte in un microtask all'importazione.
    await new Promise((r) => setTimeout(r, 0));
});

/** Pannello Verifiche già disegnato con il raggruppamento `disegnato`. */
function pannelloVerifiche(disegnato) {
    document.body.innerHTML = `
        <nav class="sidebar">
            <select id="sel-iis"><option value="SCI" selected>Scientifico</option></select>
            <select id="sel-cls"><option value="3" selected>3</option></select>
            <select id="sel-mater"><option value="MAT" selected>Matematica</option></select>
            <div class="fm-sb-panel" id="fm-sp-verif" data-sidepage="verif">
                <button class="js-edit-section" type="button">✎</button>
                <ul class="fm-db-block" data-section-kind="${disegnato}"><li data-content-id="5"><a href="#">V</a></li></ul>
            </div>
        </nav>`;
    const pannello = document.getElementById("fm-sp-verif");
    // happy-dom non impagina: il pannello aperto ha una misura.
    pannello.getBoundingClientRect = () => ({ width: 240, height: 400, top: 0, left: 0, right: 240, bottom: 400 });
    return pannello;
}

const configurazione = (group_mode) => [{
    key: "verif", loader: "db", type: "verifica", group_mode,
    allowed_content_types: ["verifica"], custom_categories: true,
}];

const elenchi = () => chieste.filter((u) => /\/(teacher\/content|study\/content\.json)$/.test(u.pathname));

beforeEach(() => {
    chieste = [];
});

afterEach(() => {
    document.body.innerHTML = "";
});

describe("pannello Verifiche dopo la configurazione", () => {
    it("disegnato per categoria, la configurazione dice per materia: si ridisegna per materia", async () => {
        pannelloVerifiche("category");
        hydrate(configurazione("subject"));
        await vi.waitFor(() => expect(elenchi().length).toBeGreaterThan(0));
        const [u] = elenchi();
        expect(u.pathname, "il caricamento per materia legge da /api/study/content.json, con has_body_pt").toBe("/api/study/content.json");
        expect(u.searchParams.get("subject")).toBe("MAT");
    });

    it("disegnato per materia, la configurazione dice per categoria: si ridisegna per categoria", async () => {
        pannelloVerifiche("subject");
        hydrate(configurazione("category"));
        await vi.waitFor(() => expect(elenchi().length).toBeGreaterThan(0));
        expect(elenchi()[0].searchParams.get("with_metadata")).toBe("1");
    });

    it("stesso raggruppamento: non si ridisegna (niente richiesta in più)", async () => {
        pannelloVerifiche("subject");
        hydrate(configurazione("subject"));
        await new Promise((r) => setTimeout(r, 20));
        expect(elenchi()).toHaveLength(0);
    });

    it("pannelliDaRidisegnare: solo i pannelli visibili, disegnati e diversi", () => {
        const pannello = pannelloVerifiche("category");
        const visibile = (el) => el === pannello;
        const defs = [
            { key: "verif", type: "verifica", group: "subject" },
            { key: "mappe", type: "mappa", group: "subject" },
        ];
        expect(pannelliDaRidisegnare(defs, (k) => document.getElementById(`fm-sp-${k}`), visibile).map((d) => d.key)).toEqual(["verif"]);
        expect(pannelliDaRidisegnare(defs, (k) => document.getElementById(`fm-sp-${k}`), () => false)).toEqual([]);
        expect(pannelliDaRidisegnare([{ key: "verif", type: "verifica", group: "category" }], (k) => document.getElementById(`fm-sp-${k}`), visibile)).toEqual([]);
    });
});
