import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from "vitest";

/**
 * I comandi di modifica del pannello BES/DSA si danno solo a chi può
 * modificare (19/9/2026).
 *
 * Misurato in locale da ospite con la credenziale di classe: nel pannello
 * «BES/DSA - RECUPERI» c'erano due «+» visibili, e il clic apriva la finestra
 * «➕ Crea bes» con il caricamento di file fino a 50 MB. Il server rifiutava il
 * salvataggio (401), ma l'interfaccia invitava a caricare. In più, a ogni
 * apertura del pannello partivano due chiamate riservate ai docenti
 * (/api/risdoc/templates e /api/risdoc/teacher/instances), con 401.
 *
 * Il segno di permesso è la matita .js-edit-section, che il server scrive solo
 * per docenti e amministratori, insieme alla classe fm-can-edit sul body. Qui:
 * senza il segno niente «+», niente chiamate riservate, niente azioni sulle
 * voci; con il segno, tutto com'era.
 */

let chieste = [];
const DOCUMENTO = {
    id: 51, title: "Mappa per il recupero", topic: "1", content_type: "document",
    visibility: "published", subject_code: "MAT", indirizzo: "SCI", classe: "2",
    metadata_json: JSON.stringify({ category: "altro" }),
};
const finto = vi.fn(async (url) => {
    const u = new URL(String(url), "http://localhost");
    chieste.push(u);
    const json = (corpo, status = 200) => new Response(JSON.stringify(corpo), {
        status, headers: { "Content-Type": "application/json" },
    });
    if (u.pathname.startsWith("/api/risdoc/")) return json({ error: "unauthenticated" }, 401);
    if (u.pathname === "/api/study/content.json" || u.pathname === "/api/teacher/content") {
        return json({ ok: true, rows: [DOCUMENTO] });
    }
    return json({ ok: true });
});

beforeAll(async () => {
    globalThis.fetch = finto;
    window.FM = window.FM || {};
    window.FM.Dialog = window.FM.Dialog || { prompt: async () => null, confirm: async () => false, alert: async () => {} };
    await import("../../js/modules/features/risdoc-sidepage.js");
    await import("../../js/modules/features/section-edit-mode.js");
});

function pagina({ puoModificare }) {
    document.body.className = puoModificare ? "fm-can-edit" : "fm-no-edit";
    document.body.dataset.fmCanEdit = puoModificare ? "1" : "0";
    const matita = puoModificare
        ? '<button class="fm-btn fm-btn--xs js-edit-section" type="button" aria-label="Modifica sezione"><strong>✎</strong></button>'
        : "";
    document.body.innerHTML = `
        <nav class="sidebar">
            <select id="sel-iis"><option value="SCI" selected>Scientifico</option></select>
            <select id="sel-cls"><option value="2" selected>2</option></select>
            <select id="sel-mater"><option value="MAT" selected>Matematica</option></select>
            <div id="fm-sb-scroll">
                <div class="fm-sb-panel" id="fm-sp-bes" data-sidepage="bes" data-group-mode="category">${matita}</div>
            </div>
        </nav>`;
}

/** Carica il pannello BES e aspetta che section-edit-mode abbia fatto la sua parte. */
async function caricaBes() {
    const disegnato = new Promise((r) => document.addEventListener("fm:risdoc-sidepage-rendered", r, { once: true }));
    window.FM.RisdocSidepage.reload("bes");
    await disegnato;
    await new Promise((r) => setTimeout(r, 0));
    return document.getElementById("fm-sp-bes");
}

const riservate = () => chieste.filter((u) => u.pathname === "/api/risdoc/templates" || u.pathname === "/api/risdoc/teacher/instances");

beforeEach(() => {
    chieste = [];
});

afterEach(() => {
    document.body.innerHTML = "";
    document.body.className = "";
});

describe("pannello BES/DSA, a chi studia", () => {
    it("niente «+», niente chiamate riservate, niente azioni sulle voci", async () => {
        pagina({ puoModificare: false });
        const pannello = await caricaBes();

        expect(pannello.querySelectorAll("ul.fm-db-block").length, "le categorie si disegnano").toBeGreaterThan(0);
        expect(pannello.querySelector('li[data-content-id="51"]'), "il documento pubblicato c'è").not.toBeNull();
        expect(pannello.querySelectorAll(".fm-section-add"), "nessun «+»").toHaveLength(0);
        expect(pannello.querySelectorAll(".fm-item-actions"), "nessuna azione ✎🗑 sulle voci").toHaveLength(0);
        expect(riservate().map((u) => u.pathname), "nessuna chiamata riservata ai docenti").toEqual([]);
        expect(chieste.some((u) => u.pathname === "/api/study/content.json"), "i contenuti arrivano dall'API di studio").toBe(true);
    });
});

describe("pannello BES/DSA, a chi può modificare", () => {
    it("un «+» per categoria, le chiamate dei modelli e le azioni sulle voci", async () => {
        pagina({ puoModificare: true });
        const pannello = await caricaBes();

        const categorie = pannello.querySelectorAll("ul.fm-db-block").length;
        expect(categorie).toBeGreaterThan(0);
        expect(pannello.querySelectorAll(".fm-section-add"), "un «+» per categoria").toHaveLength(categorie);
        expect(pannello.querySelector(".fm-section-add").dataset.fmSecAddBound, "e il «+» è collegato").toBe("1");
        expect(pannello.querySelectorAll('li[data-content-id="51"] .fm-item-actions'), "le azioni sulla voce").toHaveLength(1);
        expect(riservate().map((u) => u.pathname).sort()).toEqual(["/api/risdoc/teacher/instances", "/api/risdoc/templates"]);
        expect(chieste.some((u) => u.pathname === "/api/teacher/content"), "i contenuti arrivano dall'API del docente").toBe(true);
    });
});

describe("un «+» disegnato per sbaglio", () => {
    it("senza fm-can-edit sul body non si collega, e il clic non apre niente", async () => {
        const { bindSectionAddButtons } = await import("../../js/modules/features/sidepage-inline-actions.js");
        pagina({ puoModificare: false });
        const pannello = document.getElementById("fm-sp-bes");
        pannello.insertAdjacentHTML("beforeend",
            '<ul class="fm-db-block"><li class="fm-db-head"><button type="button" class="fm-btn fm-section-add" data-fm-type="bes">➕</button></li></ul>');
        bindSectionAddButtons(pannello, "bes");
        const piu = pannello.querySelector(".fm-section-add");
        expect(piu.dataset.fmSecAddBound, "non collegato").toBeUndefined();
        piu.click();
        await new Promise((r) => setTimeout(r, 0));
        expect(document.querySelector(".fm-modal-backdrop"), "nessuna finestra").toBeNull();

        // Nell'altro verso: con fm-can-edit si collega.
        document.body.classList.add("fm-can-edit");
        bindSectionAddButtons(pannello, "bes");
        expect(piu.dataset.fmSecAddBound).toBe("1");
    });
});
