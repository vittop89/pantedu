// @vitest-environment-options {"settings":{"disableIframePageLoading":true}}
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import {
    openModal, closeModal, extractFields, patchDeiMetadati, exercisesSeedPt,
} from "../../js/modules/features/sidepage-modal-content.js";

/**
 * Il modale ✎ non inventa e non cancella metadati (19/9/2026, analisi
 * C-export-bodypt e F-metadati-modifica-mappa).
 *
 * Misurato prima della correzione: il «Salva» di una mappa mandava
 * `metadata` = {layout: "exercises", body_pt: 4 blocchi d'esempio,
 * mappa: {href, display: "show"}}, e il server lo sostituiva ai metadati
 * salvati: via `mappa.href_hide` e `mappa.drawio_id`, `display` da hide a show,
 * e il 📥 compariva sulla mappa. Un esercizio perdeva `contract_key`, cioè i
 * suoi quesiti (/contract passava da 200 a 404).
 *
 * Adesso in modifica il modale manda solo `metadata_patch`, le chiavi che
 * l'utente ha cambiato, e il server le fonde (tests/Integration/
 * ModaleConservaMetadatiTest.php). Qui si guarda il corpo della richiesta.
 */

const flush = () => new Promise((r) => setTimeout(r, 0));

let chiamate = [];

function fetchFinto() {
    return vi.fn(async (url, opzioni = {}) => {
        const u = String(url);
        chiamate.push({ url: u, method: opzioni.method || "GET", body: opzioni.body ? String(opzioni.body) : "" });
        if (u === "/auth/csrf") return { ok: true, status: 200, json: async () => ({ token: "gettone" }) };
        if (/^\/api\/teacher\/content\/\d+\/update$/.test(u)) {
            return { ok: true, status: 200, json: async () => ({ ok: true, has_body_pt: false, doc_roles: "" }) };
        }
        return { ok: false, status: 404, json: async () => ({}) };
    });
}

/** Il corpo del POST /update, o null se non è partito. */
function corpoDelSalvataggio(id) {
    const c = chiamate.find((x) => x.method === "POST" && x.url === `/api/teacher/content/${id}/update`);
    return c ? new URLSearchParams(c.body) : null;
}

/** Quello che il server fa con la patch (MetadatiDelContenuto::fondi). */
function applicaPatch(meta, patch) {
    const fuso = JSON.parse(JSON.stringify(meta));
    for (const [k, v] of Object.entries(patch)) {
        if (v === null) delete fuso[k]; else fuso[k] = v;
    }
    return fuso;
}

function modale() {
    return document.querySelector(".fm-modal-backdrop");
}

async function salva(id) {
    modale().querySelector(".fm-modal-form").dispatchEvent(new Event("submit", { cancelable: true, bubbles: true }));
    await vi.waitFor(() => expect(corpoDelSalvataggio(id)).not.toBeNull());
    return corpoDelSalvataggio(id);
}

/** I metadati come li salverebbe il server dopo questo corpo. */
function metadatiDopo(salvati, corpo) {
    if (corpo.has("metadata")) return JSON.parse(corpo.get("metadata"));
    return applicaPatch(salvati, corpo.has("metadata_patch") ? JSON.parse(corpo.get("metadata_patch")) : {});
}

beforeEach(() => {
    document.body.innerHTML = "";
    window.FM = {};
    chiamate = [];
    globalThis.fetch = fetchFinto();
});

afterEach(() => {
    closeModal();
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

const META_MAPPA = {
    mappa: {
        href: "https://example.org/mappa",
        href_hide: "https://example.org/nascosto",
        drawio_id: "abc123",
        display: "hide",
    },
};
const MAPPA = {
    id: 7, content_type: "mappa", title: "Calcolatrice grafica", topic: "0.0",
    map_blob_path: "7/m.bin", map_version: 1, visibility: "draft",
    metadata_json: JSON.stringify(META_MAPPA),
};

describe("✎ su una mappa", () => {
    it("cambiare il titolo non manda metadati: niente layout, niente body_pt, il link resta com'era", async () => {
        openModal({ type: "mappa", mode: "edit", row: MAPPA });
        expect(modale().querySelector('input[name="doc_mode"]'), "una mappa non ha un modello da scrivere").toBeNull();
        modale().querySelector('input[name="title"]').value = "Calcolatrice grafica bis";

        const corpo = await salva(7);

        expect(corpo.get("title")).toBe("Calcolatrice grafica bis");
        expect(corpo.has("metadata"), "i metadati interi sostituirebbero quelli salvati").toBe(false);
        expect(corpo.has("metadata_patch"), "non è cambiato nessun metadato").toBe(false);
        expect(metadatiDopo(META_MAPPA, corpo)).toEqual(META_MAPPA);
    });

    it("cambiare il link cambia solo il link: href_hide, drawio_id e display restano", async () => {
        openModal({ type: "mappa", mode: "edit", row: MAPPA });
        modale().querySelector('input[name="href"]').value = "https://example.org/mappa-nuova";

        const corpo = await salva(7);

        expect(corpo.has("metadata")).toBe(false);
        expect(JSON.parse(corpo.get("metadata_patch"))).toEqual({
            mappa: { ...META_MAPPA.mappa, href: "https://example.org/mappa-nuova" },
        });
    });

    it("svuotare il link toglie solo il link", async () => {
        openModal({ type: "mappa", mode: "edit", row: MAPPA });
        modale().querySelector('input[name="href"]').value = "";

        const corpo = await salva(7);

        const resto = { ...META_MAPPA.mappa };
        delete resto.href;
        expect(metadatiDopo(META_MAPPA, corpo)).toEqual({ mappa: resto });
    });

    it("una mappa senza la chiave mappa non la riceve se il link non cambia", async () => {
        const senza = { ...MAPPA, metadata_json: "{}" };
        openModal({ type: "mappa", mode: "edit", row: senza });

        const corpo = await salva(7);

        expect(metadatiDopo({}, corpo)).toEqual({});
    });
});

describe("✎ su un esercizio o una verifica a contratto", () => {
    for (const tipo of ["esercizio", "verifica"]) {
        it(`${tipo}: contract_key resta, e non nasce né layout né body_pt`, async () => {
            const meta = { contract_key: `institutes/1/private/2/${tipo}/MAT/9_9.contract.json` };
            openModal({ type: tipo, mode: "edit", row: {
                id: 9, content_type: tipo, title: "Equazioni", topic: "9.9", visibility: "draft",
                metadata_json: JSON.stringify(meta),
            } });
            expect(modale().querySelector('input[name="doc_mode"]')).toBeNull();
            modale().querySelector('input[name="title"]').value = "Equazioni bis";

            const corpo = await salva(9);

            expect(corpo.has("metadata")).toBe(false);
            expect(metadatiDopo(meta, corpo)).toEqual(meta);
        });
    }

    it("con il modello salvato, il modello resta com'è e il corpo non viaggia", async () => {
        const meta = { layout: "exercises", body_pt: exercisesSeedPt(), contract_key: "k.contract.json" };
        openModal({ type: "esercizio", mode: "edit", row: {
            id: 10, content_type: "esercizio", title: "E", topic: "1.0", visibility: "draft",
            metadata_json: JSON.stringify(meta),
        } });
        expect(modale().querySelector('input[name="doc_mode"]').value).toBe("exercises");

        const corpo = await salva(10);

        expect(corpo.has("metadata")).toBe(false);
        expect(corpo.has("metadata_patch")).toBe(false);
    });
});

describe("✎ su un documento Personalizzabile", () => {
    const META = {
        layout: "custom", category: "bes",
        body_pt: [{ _type: "block", style: "normal", children: [{ _type: "span", text: "Testo", marks: [] }] }],
        doc_roles: ["D"],
    };
    const riga = (meta = META) => ({
        id: 11, content_type: "document", title: "Piano", topic: "1.0", visibility: "draft",
        metadata_json: JSON.stringify(meta),
    });

    it("il modello si vede e il ruolo non cambia se non lo si tocca", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        expect(modale().querySelector('input[name="doc_mode"]').value).toBe("custom");

        const corpo = await salva(11);

        expect(corpo.has("metadata"), "il corpo del documento non viaggia con il modale").toBe(false);
        expect(corpo.has("metadata_patch")).toBe(false);
    });

    it("scegliere un ruolo manda solo il ruolo", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        modale().querySelector('input[name="doc_roles"][value="C"]').checked = true;

        const corpo = await salva(11);

        expect(JSON.parse(corpo.get("metadata_patch"))).toEqual({ doc_roles: ["C"] });
    });

    it("«Nessuno» toglie il ruolo, e il corpo resta", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        modale().querySelector('input[name="doc_roles"][value=""]').checked = true;

        const corpo = await salva(11);

        expect(JSON.parse(corpo.get("metadata_patch"))).toEqual({ doc_roles: null });
        const resto = { ...META };
        delete resto.doc_roles;
        expect(metadatiDopo(META, corpo)).toEqual(resto);
    });

    it("più ruoli salvati restano tutti se il ruolo non si tocca", async () => {
        const conDue = { ...META, doc_roles: ["D", "C"] };
        openModal({ type: "document", mode: "edit", row: riga(conDue) });

        const corpo = await salva(11);

        expect(metadatiDopo(conDue, corpo)).toEqual(conDue);
    });

    // 20/9/2026, revisione della PR #145 — il confronto della categoria stava
    // con `defaultValue`, che nasce dallo stesso attributo `value=` del campo
    // nascosto: era sempre uguale, quindi il ramo non poteva scattare. Adesso
    // il confronto è con la categoria salvata, che è la domanda vera.
    it("la categoria scritta nel campo nascosto parte nella patch", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        const campo = modale().querySelector('input[name="category"]');
        expect(campo.value, "il campo nascosto parte dalla categoria salvata").toBe("bes");
        campo.value = "risorse";

        const corpo = await salva(11);

        expect(JSON.parse(corpo.get("metadata_patch") ?? "null")).toEqual({ category: "risorse" });
        expect(metadatiDopo(META, corpo)).toEqual({ ...META, category: "risorse" });
    });

    it("controprova: la categoria non toccata non entra nella patch", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        modale().querySelector('input[name="doc_roles"][value="C"]').checked = true;

        const corpo = await salva(11);

        expect(JSON.parse(corpo.get("metadata_patch"))).toEqual({ doc_roles: ["C"] });
    });

    it("controprova: un campo nascosto svuotato non toglie la categoria salvata", async () => {
        openModal({ type: "document", mode: "edit", row: riga() });
        modale().querySelector('input[name="category"]').value = "";

        const corpo = await salva(11);

        expect(corpo.has("metadata_patch"), "il campo nascosto vuoto vuol dire «non so», non «togli»").toBe(false);
    });
});

describe("la creazione resta com'era", () => {
    it("«Stile esercizi» nasce con il suo modello e il body_pt iniziale", async () => {
        openModal({ type: "esercizio", mode: "create", sectionKey: "eser" });
        await flush();
        const form = modale().querySelector(".fm-modal-form");
        form.querySelector('input[name="doc_mode"][value="exercises"]').checked = true;
        form.querySelector('input[name="title"]').value = "Nuovo";

        const campi = extractFields(form, "esercizio", {}, "create");

        expect(campi.metadata.layout).toBe("exercises");
        expect(campi.metadata.body_pt).toEqual(exercisesSeedPt());
        expect(campi.metadata_patch).toBeUndefined();
    });

    it("il seme è quello che lo strumento di pulizia cerca (tests/Fixtures)", () => {
        const fixture = JSON.parse(readFileSync(resolve(__dirname, "../Fixtures/seme-esercizi-body-pt.json"), "utf8"));
        expect(exercisesSeedPt()).toEqual(fixture);
        // Anche l'ordine delle chiavi: lo strumento confronta alla lettera.
        expect(JSON.stringify(exercisesSeedPt())).toBe(JSON.stringify(fixture));
    });
});

describe("patchDeiMetadati", () => {
    it("le chiavi cambiate con il valore nuovo, le tolte con null, le uguali no", () => {
        expect(patchDeiMetadati(
            { a: 1, b: { x: 1 }, c: [1], d: "via" },
            { a: 1, b: { x: 2 }, c: [1], e: "nuova" },
        )).toEqual({ b: { x: 2 }, d: null, e: "nuova" });
        expect(patchDeiMetadati({}, {})).toEqual({});
    });
});
