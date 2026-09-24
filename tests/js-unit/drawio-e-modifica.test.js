// @vitest-environment-options {"settings":{"disableIframePageLoading":true}}
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { openModal, closeModal } from "../../js/modules/features/sidepage-modal-content.js";
import { addInlineItemActions, tipoDaModificare } from "../../js/modules/features/sidepage-inline-actions.js";

/**
 * Due segnalazioni dell'utente sulle mappe (15/9/2026).
 *
 * 1. «Nuova mappa drawio (vuota)» apriva un riquadro bianco, in cui non si
 *    poteva fare niente. Il modale apriva `embed.diagrams.net` e, dal 9/9, gli
 *    mandava il messaggio «carica» con destinatario la NOSTRA origine: il
 *    browser lo scartava in silenzio, e drawio restava ad aspettarlo.
 * 2. Una mappa creata nel Laboratorio, con ✎, apriva «Modifica esercizio»:
 *    il modale prendeva il tipo della sidepage, non quello del contenuto. Ogni
 *    sidepage crea qualsiasi tipo di documento (ADR-027).
 *
 * L'iframe non si carica (`disableIframePageLoading`): qui si guarda che cosa
 * la pagina chiede e a chi scrive, senza rete.
 */

const flush = () => new Promise((r) => setTimeout(r, 0));

function rispostaFetch(mappa) {
    return vi.fn(async (url) => {
        const u = String(url);
        if (u === `/api/teacher/content/${mappa.id}`) {
            return { ok: true, status: 200, json: async () => ({ content: mappa }) };
        }
        return { ok: false, status: 404, json: async () => ({}) };
    });
}

beforeEach(() => {
    document.body.innerHTML = "";
    window.FM = {};
});

afterEach(() => {
    closeModal();
    document.querySelectorAll(".fm-drawio-overlay").forEach((n) => n.remove());
    vi.restoreAllMocks();
    delete globalThis.fetch;
    delete window.alert;
});

describe("la mappa nuova dal modale di creazione", () => {
    it("apre l'editor ospitato qui, e il messaggio «carica» va all'origine del riquadro", async () => {
        globalThis.fetch = rispostaFetch({ id: 0 });
        openModal({ type: "esercizio", mode: "create", sectionKey: "lab" });
        document.querySelector(".fm-modal-drawio-open").click();

        const iframe = document.querySelector(".fm-drawio-overlay iframe");
        const src = new URL(iframe.getAttribute("src"), window.location.href);
        expect(src.origin).toBe(window.location.origin);
        expect(src.pathname).toBe("/drawio-app/index.html");

        // Senza caricamento l'iframe non ha una finestra: gliene si dà una che
        // registra a chi le si scrive.
        const scritti = [];
        const finestra = {
            postMessage: (msg, destinatario) => scritti.push({ msg: JSON.parse(msg), destinatario }),
        };
        Object.defineProperty(iframe, "contentWindow", { value: finestra, configurable: true });
        window.dispatchEvent(new MessageEvent("message", {
            data: JSON.stringify({ event: "init" }),
            source: finestra,
        }));

        expect(scritti).toEqual([{ msg: { action: "load", xml: "" }, destinatario: src.origin }]);
    });
});

describe("✎ su un contenuto", () => {
    const MAPPA = {
        id: 7, content_type: "mappa", title: "Calcolatrice grafica", topic: "0.0",
        map_blob_path: "7/m.bin", map_version: 1, visibility: "draft", metadata_json: "{}",
    };

    function sidepageLaboratorio() {
        document.body.innerHTML = `
            <div class="fm-sb-panel" id="fm-sp-lab" data-sidepage="lab">
                <ul class="fm-db-block"><li data-content-id="7"><a href="#">Calcolatrice grafica</a></li></ul>
            </div>`;
        const panel = document.getElementById("fm-sp-lab");
        addInlineItemActions(panel, "esercizio");
        return panel;
    }

    it("una mappa nel Laboratorio si modifica come mappa, con l'editor drawio", async () => {
        globalThis.fetch = rispostaFetch(MAPPA);
        sidepageLaboratorio().querySelector(".fm-item-edit").click();
        await flush(); await flush();

        const modale = document.querySelector(".fm-modal-backdrop");
        expect(modale?.querySelector("header code, code")?.textContent).toBe("mappa");
        expect(modale.querySelector(".fm-modal-drawio-edit-btn")).not.toBeNull();
    });

    it("«Apri editor drawio» carica l'editor se la pagina non l'aveva, invece di chiedere di ricaricare", async () => {
        globalThis.fetch = rispostaFetch(MAPPA);
        const avviso = vi.fn();
        window.alert = avviso;
        // Il modulo dell'editor si caricava solo nel contesto degli esercizi
        // (bootstrap.js): altrove `window.FM.DrawioEditor` non c'era.
        openModal({ type: "mappa", mode: "edit", row: MAPPA });

        document.querySelector(".fm-modal-drawio-edit-btn").click();
        await vi.waitFor(() => {
            expect(globalThis.fetch.mock.calls.map(([u]) => String(u))).toContain("/api/maps/7/signed-url?mode=view");
        });
        expect(avviso).not.toHaveBeenCalled();
    });

    it("il tipo è quello del contenuto; quello della sidepage resta solo nella stessa famiglia", () => {
        expect(tipoDaModificare({ content_type: "mappa" }, "esercizio")).toBe("mappa");
        expect(tipoDaModificare({ content_type: "document" }, "esercizio")).toBe("document");
        expect(tipoDaModificare({ content_type: "esercizio" }, "mappa")).toBe("esercizio");
        expect(tipoDaModificare({ content_type: "verifica" }, "verifica")).toBe("verifica");
        // «risdoc» e «bes» sono documenti: il modale usa il tipo grezzo per la categoria.
        expect(tipoDaModificare({ content_type: "document" }, "risdoc")).toBe("risdoc");
        expect(tipoDaModificare({ content_type: "document" }, "bes")).toBe("bes");
        // Una riga senza tipo riconosciuto non cambia niente.
        expect(tipoDaModificare({}, "esercizio")).toBe("esercizio");
        expect(tipoDaModificare({ content_type: "lab" }, "esercizio")).toBe("esercizio");
    });
});
