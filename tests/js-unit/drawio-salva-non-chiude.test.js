// @vitest-environment-options {"settings":{"disableIframePageLoading":true}}
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";

/**
 * Segnalazione dell'utente (20/9/2026): nell'editor drawio il pulsante
 * «Salva» salvava e poi **chiudeva** il riquadro. Chi salva a metà lavoro non
 * vuole uscire: vuole vedere che il salvataggio è andato e continuare.
 *
 * I pulsanti dell'editor sono tre, e drawio li distingue nel messaggio che
 * manda alla pagina (`js/app.min.js`, modalità embed con `saveAndExit=1`):
 *
 *   - «Salva»          → { event: "save", xml }
 *   - «Salva ed esci»  → { event: "save", xml, exit: true }
 *   - «Esci»           → { event: "exit" }
 *
 * Il riquadro si chiude sugli ultimi due, non sul primo. Prima di questa
 * correzione si chiudeva su tutti e tre: dopo il salvataggio partiva un
 * `setTimeout` di 400 ms che faceva `teardown()` senza guardare `exit`.
 *
 * Qui non c'è rete: `fetch` è finto, e `/auth/csrf` non risponde (il gettone
 * resta vuoto). Non è un aggiramento del controllo CSRF — la rotta che lo
 * verifica è finta anch'essa: qui si guarda solo che cosa fa il riquadro.
 */

const MAPPA = {
    id: 9,
    content_type: "mappa",
    title: "Teorema di Pitagora",
    map_blob_path: "9/m.bin",
    map_version: 3,
    visibility: "draft",
    metadata_json: "{}",
};

const XML = '<mxfile><diagram>vecchio</diagram></mxfile>';
const XML_NUOVO = '<mxfile><diagram>nuovo</diagram></mxfile>';

/** La rete finta: la riga, l'URL firmato, l'XML e il salvataggio. */
function reteFinta() {
    return vi.fn(async (url) => {
        const u = String(url);
        if (u === "/api/teacher/content/9") {
            return { ok: true, status: 200, json: async () => ({ content: MAPPA }) };
        }
        if (u === "/api/maps/9/signed-url?mode=view") {
            return { ok: true, status: 200, json: async () => ({ ok: true, url: "/blob/9" }) };
        }
        if (u === "/blob/9") {
            return { ok: true, status: 200, text: async () => XML };
        }
        if (u === "/api/maps/9/update") {
            return { ok: true, status: 200, json: async () => ({ ok: true, map_version: 4 }) };
        }
        return { ok: false, status: 404, json: async () => ({}), text: async () => "" };
    });
}

/**
 * Apre l'editor e torna gli appigli per parlargli: il riquadro, la finestra
 * finta dell'iframe (serve come `source` dei messaggi) e l'esito della
 * promessa, che resta `undefined` finché il riquadro non si chiude.
 */
async function apriEditor() {
    const { openDrawioEditor } = await import("../../js/modules/features/drawio-editor.js");
    const esito = { valore: undefined };
    const promessa = openDrawioEditor({ contentId: 9, mode: "edit" })
        .then((v) => { esito.valore = v; });

    await vi.waitFor(() => {
        expect(document.querySelector(".fm-drawio-overlay iframe")).not.toBeNull();
    });
    const riquadro = document.querySelector(".fm-drawio-overlay");
    const iframe = riquadro.querySelector("iframe");
    const finestra = { postMessage: () => {} };
    Object.defineProperty(iframe, "contentWindow", { value: finestra, configurable: true });

    return { riquadro, finestra, esito, promessa };
}

/** Manda al riquadro un messaggio come lo manderebbe drawio. */
function daDrawio(finestra, messaggio) {
    window.dispatchEvent(new MessageEvent("message", {
        data: JSON.stringify(messaggio),
        source: finestra,
    }));
}

beforeEach(() => {
    document.body.innerHTML = "";
    window.FM = {};
    globalThis.fetch = reteFinta();
});

afterEach(() => {
    document.querySelectorAll(".fm-drawio-overlay").forEach((n) => n.remove());
    vi.restoreAllMocks();
    delete globalThis.fetch;
});

describe("Editor drawio — che cosa chiude il riquadro", () => {
    it("«Salva» salva e lascia aperto, con scritto che è andata", async () => {
        const { riquadro, finestra, esito } = await apriEditor();

        daDrawio(finestra, { event: "save", xml: XML_NUOVO });

        await vi.waitFor(() => {
            expect(globalThis.fetch.mock.calls.map(([u]) => String(u))).toContain("/api/maps/9/update");
        });
        // Il tempo che passava prima che il riquadro si chiudesse da solo.
        await new Promise((r) => setTimeout(r, 600));

        expect(document.body.contains(riquadro)).toBe(true);
        expect(riquadro.querySelector(".fm-drawio-status").textContent).toContain("Salvataggio riuscito");
        expect(esito.valore).toBeUndefined();
    });

    it("il salvataggio manda la versione che ha in mano, e dopo tiene quella nuova", async () => {
        const { finestra } = await apriEditor();

        daDrawio(finestra, { event: "save", xml: XML_NUOVO });
        await vi.waitFor(() => {
            expect(globalThis.fetch.mock.calls.map(([u]) => String(u))).toContain("/api/maps/9/update");
        });

        const chiamata = globalThis.fetch.mock.calls.find(([u]) => String(u) === "/api/maps/9/update");
        expect(String(chiamata[1].body)).toContain("map_version=3");
        expect(String(chiamata[1].body)).toContain(encodeURIComponent("nuovo"));
    });

    it("«Salva ed esci» salva e chiude, e dice a chi l'ha aperto che è stato salvato", async () => {
        const { riquadro, finestra, esito, promessa } = await apriEditor();

        daDrawio(finestra, { event: "save", xml: XML_NUOVO, exit: true });

        await promessa;
        expect(document.body.contains(riquadro)).toBe(false);
        expect(esito.valore).toEqual({ saved: true, version: 4 });
    });

    it("«Esci» dopo un salvataggio chiude e dice che è stato salvato", async () => {
        const { riquadro, finestra, esito, promessa } = await apriEditor();

        daDrawio(finestra, { event: "save", xml: XML_NUOVO });
        await vi.waitFor(() => {
            expect(globalThis.fetch.mock.calls.map(([u]) => String(u))).toContain("/api/maps/9/update");
        });
        daDrawio(finestra, { event: "exit" });

        await promessa;
        expect(document.body.contains(riquadro)).toBe(false);
        expect(esito.valore).toEqual({ saved: true, version: 4 });
    });

    it("la ✕ dopo un salvataggio chiude e non fa perdere il salvataggio a chi l'ha aperto", async () => {
        const { riquadro, finestra, esito, promessa } = await apriEditor();

        daDrawio(finestra, { event: "save", xml: XML_NUOVO });
        await vi.waitFor(() => {
            expect(globalThis.fetch.mock.calls.map(([u]) => String(u))).toContain("/api/maps/9/update");
        });
        riquadro.querySelector(".fm-drawio-close").click();

        await promessa;
        expect(document.body.contains(riquadro)).toBe(false);
        // Con `saved: false` chi ha aperto l'editor non aggiornava la barra:
        // il salvataggio c'era stato, ma la voce restava quella vecchia.
        expect(esito.valore).toEqual({ saved: true, version: 4 });
    });

    it("la ✕ senza aver salvato niente dice che non è stato salvato", async () => {
        const { riquadro, esito, promessa } = await apriEditor();

        riquadro.querySelector(".fm-drawio-close").click();

        await promessa;
        expect(esito.valore).toEqual({ saved: false, version: 3 });
    });
});
