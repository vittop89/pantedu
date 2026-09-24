/**
 * Una voce di verifica con una figura TikZ, aperta nell'editor e richiusa
 * senza toccare niente, torna al server con la sua figura.
 *
 * Il guasto del 18 settembre 2026 (verifica 75, esercizi 14 e 16): il docente
 * ha aperto e salvato due voci dalla pagina di studio, nel pannello delle
 * verifiche correlate. Nel contratto la soluzione, che era [tikz, testo,
 * formula, …], è diventata un blocco di testo solo:
 *
 *   - es. 14: il testo cominciava con `<script nonce="" type="text/tikz" …>`.
 *     In produzione la CSP è rigida e SecurityHeadersMiddleware::stampScriptNonce
 *     metteva `nonce` davanti a `type` in ogni `<script>` (fino al 23/9/2026,
 *     A-16: ora il nonce lo portano solo gli script dell'app, e la prova resta
 *     per l'HTML salvato con quella forma); il browser ne nasconde
 *     il valore e il DOM dice `nonce=""`. Il riconoscitore dei blocchi TikZ
 *     voleva `type` subito dopo `<script`, lo script restava nel campo come
 *     HTML e il campo diventava testo;
 *   - es. 16: il testo cominciava con «[TikZ render error]». Il servizio TeX
 *     non rispondeva, tikz-render-client aveva messo al posto dello script un
 *     riquadro rosso che non conservava il sorgente, e il riquadro è stato
 *     salvato come contenuto. La figura è persa.
 *
 * La prova rifà la strada vera, pezzo per pezzo, con il codice vero:
 *
 *   1. l'HTML di ContractRenderer (fixtures/verifica-con-tikz.html, tenuto
 *      allineato al renderer da tests/Unit/Rendering/HtmlDellaVerificaConTikzTest.php);
 *   2. il `nonce` come lo lascia il browser in produzione, oppure niente come
 *      in sviluppo;
 *   3. uno dei tre stati in cui può trovarsi ogni figura quando il docente
 *      apre l'editor: lo script non ancora reso (gruppo chiuso), l'SVG reso
 *      (renderAll con una risposta dalla cache), il riquadro d'errore
 *      (renderAll con la rete che non risponde);
 *   4. l'apertura dell'editor come in openItemEditor (checkin-handlers.js) e
 *      buildSection (section-builder-full.js, con le sue dipendenze
 *      d'interfaccia spente);
 *   5. il salvataggio come in _captureEditorFields: blocchiDalCampo sul campo.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import HTML_RESO from "./fixtures/verifica-con-tikz.html?raw";
import GRUPPO from "./fixtures/verifica-con-tikz.json";
import { createBuildSectionView } from "../../js/modules/editor/section-builder-full.js";
import { collapseTikzBlocks, collapseGeoGebraBlocks } from "../../js/modules/editor/inline-blocks-markers.js";
import { makeEditableField } from "../../js/modules/editor/editable-field-factory.js";
import {
    sorgenteDelContenitore, sorgenteSenzaEtichetta, blocchiDalCampo,
} from "../../js/modules/editor/campo-in-blocchi.js";

const VOCE = Object.fromEntries(GRUPPO.items.map((v) => [v.id, v]));

// Una figura come la restituisce la cache del servizio TeX (dvisvgm).
const SVG_DAL_SERVIZIO =
    "<svg xmlns='http://www.w3.org/2000/svg' width='40' height='30' viewBox='0 0 40 30'>"
    + "<defs><path id='g0-1' d='M0 0L1 1'/></defs>"
    + "<g id='page1'><use xlink:href='#g0-1' x='3' y='4'/><path d='M0 0L40 30' stroke='#000'/></g></svg>";

/** Il timbro di SecurityHeadersMiddleware::stampScriptNonce, tolto il
 *  23/9/2026 (stessa espressione), con il valore già nascosto come lo mostra
 *  il browser: la forma dell'HTML salvato fino ad allora. */
function conNonceComeInProduzione(html) {
    return html.replace(/<script\b(?![^>]*\bnonce=)/gi, '<script nonce=""');
}

const { buildSection } = createBuildSectionView({
    makeEditableField,
    collapseTikzBlocks, collapseGeoGebraBlocks,
    expandedValue: (ta) => ta.value,
    bindPreview() {}, updatePreview() {},
    enhanceTextarea() {},
    undoManager: { attach() {} },
    attachListKeyHandlers() {},
    getBlockDialogs: async () => ({}),
});

/** Apre l'editor su una voce e la richiude: i blocchi che partirebbero. */
function apriERichiudi(voce) {
    const quesito = buildSection("Quesito", sorgenteDelContenitore(voce.querySelector(".fm-collection")));
    const soluzione = buildSection("Soluzione", sorgenteSenzaEtichetta(voce.querySelector(".fm-sol")) || "");
    return {
        quesito: blocchiDalCampo(quesito.querySelector(".fm-editor-field")),
        soluzione: blocchiDalCampo(soluzione.querySelector(".fm-editor-field")),
    };
}

/** La pagina con le figure nello stato richiesto. Il contenitore non è
 *  attaccato al documento: il rendering automatico di tikz-render-client
 *  (MutationObserver su body) non ci arriva, lo si chiama a mano. */
async function paginaCon(stato, { nonce }) {
    const pagina = document.createElement("div");
    pagina.className = "fm-contract-wrap";
    pagina.innerHTML = nonce ? conNonceComeInProduzione(HTML_RESO) : HTML_RESO;
    if (stato === "script") return pagina;

    // Il modulo si ricarica a ogni prova: tiene in memoria le figure già rese,
    // e una figura resa in una prova non deve diventare SVG nella successiva.
    vi.resetModules();
    const { renderAll } = await import("../../js/modules/editor/tikz-render-client.js");
    vi.stubGlobal("fetch", async (url, opts = {}) => {
        if (stato === "svg" && !opts.method && String(url).startsWith("/tikz/render?hash=")) {
            return new Response(SVG_DAL_SERVIZIO, { status: 200, headers: { "Content-Type": "image/svg+xml" } });
        }
        // Stato «errore»: il servizio non risponde. Come il 18/9, la figura
        // finisce nel riquadro rosso.
        throw new TypeError("Failed to fetch");
    });
    const esito = await renderAll(pagina, { defaultScope: "public" });
    // La prova sta guardando lo stato che crede.
    if (stato === "svg") expect(esito.ok).toBe(2);
    if (stato === "errore") expect(esito.errors).toHaveLength(2);
    return pagina;
}

function voce(pagina, id) {
    const el = pagina.querySelector(`.fm-collection__item[data-id="${id}"]`);
    expect(el, `la voce ${id} nella pagina resa`).not.toBeNull();
    return el;
}

/** Ogni stringa di un albero di blocchi, per cercarci dentro. */
function testi(blocchi) {
    return JSON.stringify(blocchi);
}

const STATI = [
    ["lo script non ancora reso", "script"],
    ["l'SVG reso dalla cache", "svg"],
    ["il riquadro d'errore (servizio TeX muto)", "errore"],
];

afterEach(() => {
    vi.unstubAllGlobals();
});

describe.each([
    ["in produzione (CSP rigida, nonce sugli script)", true],
    ["in sviluppo (niente nonce)", false],
])("TikZ nel salvataggio, %s", (_nome, nonce) => {
    beforeEach(() => {
        vi.resetModules();
    });

    describe.each(STATI)("con %s", (_descrizione, stato) => {
        it("la soluzione dell'es. 14 torna con il suo blocco tikz, identico", async () => {
            const pagina = await paginaCon(stato, { nonce });
            const { soluzione } = apriERichiudi(voce(pagina, "voce-14"));

            const tikz = soluzione.filter((b) => b.type === "tikz");
            expect(tikz).toEqual([VOCE["voce-14"].solution[0]]);
            expect(soluzione[0].type).toBe("tikz");
        });

        it("la soluzione dell'es. 16 torna con il suo blocco tikz, identico", async () => {
            const pagina = await paginaCon(stato, { nonce });
            const { soluzione } = apriERichiudi(voce(pagina, "voce-16"));

            expect(soluzione.filter((b) => b.type === "tikz")).toEqual([VOCE["voce-16"].solution[0]]);
        });

        it("nessun blocco porta testo della pagina resa", async () => {
            const pagina = await paginaCon(stato, { nonce });
            for (const id of ["voce-14", "voce-16"]) {
                const { quesito, soluzione } = apriERichiudi(voce(pagina, id));
                for (const blocco of [...quesito, ...soluzione]) {
                    if (blocco.type === "tikz") continue;
                    const t = testi(blocco);
                    expect(t, `${id}: ${t.slice(0, 120)}`).not.toContain("[TikZ render error]");
                    expect(t).not.toContain("fm-tikz-error");
                    expect(t).not.toMatch(/<script\b[^>]*text\/tikz/i);
                    expect(t).not.toMatch(/<svg\b/i);
                    expect(t).not.toContain("data-tikz-");
                }
            }
        });
    });
});


/**
 * Il quesito dell'es. 14: un testo e una lista `type="a" start="2"` con dentro
 * un blocco `latex`. Il 18 settembre anche il quesito è cambiato senza che il
 * docente lo toccasse.
 *
 * Due cose diverse, e qui restano separate:
 *
 *   - la STRUTTURA della lista (ordered, list_style, start, un elemento per
 *     voce) deve tornare uguale, e non deve portarsi dentro pezzi della pagina
 *     resa. Questo lo garantisce la correzione TikZ e si prova qui sotto;
 *   - i blocchi `latex` dentro il testo non tornano: il serializzatore fonde
 *     testo e formule in un blocco `text` solo. Non è un effetto del guasto
 *     TikZ ed è fuori da questa correzione — si vede sotto, in una prova che
 *     PRETENDE di fallire, così il giorno che qualcuno separa di nuovo i
 *     blocchi `latex` questa diventa rossa e va riscritta.
 */
describe("andata e ritorno del quesito con la lista", () => {
    beforeEach(() => { vi.resetModules(); });
    afterEach(() => { vi.unstubAllGlobals(); });

    it.each(STATI)("con %s la lista torna con la sua forma", async (_d, stato) => {
        const pagina = await paginaCon(stato, { nonce: true });
        const { quesito } = apriERichiudi(voce(pagina, "voce-14"));
        const atteso = VOCE["voce-14"].question;

        expect(quesito).toHaveLength(atteso.length);
        expect(quesito[0]).toEqual(atteso[0]);

        const lista = quesito[1];
        expect(lista.type).toBe("list");
        expect(lista.ordered).toBe(atteso[1].ordered);
        expect(lista.list_style).toBe(atteso[1].list_style);
        expect(lista.start).toBe(atteso[1].start);
        expect(lista.items).toHaveLength(atteso[1].items.length);
        // La seconda voce non ha formule: quella deve tornare identica.
        expect(lista.items[1]).toEqual(atteso[1].items[1]);
        // E il testo della prima voce c'è tutto, formula compresa: i pezzi si
        // leggono dal contratto di partenza, non riscritti a mano qui.
        const primo = JSON.stringify(lista.items[0]);
        for (const pezzo of atteso[1].items[0]) {
            expect(primo, pezzo.content).toContain(JSON.stringify(pezzo.content).slice(1, -1));
        }
    });

    it.fails("RESTA APERTO: i blocchi latex nella lista non tornano separati", async () => {
        const pagina = await paginaCon("script", { nonce: true });
        const { quesito } = apriERichiudi(voce(pagina, "voce-14"));
        expect(quesito).toEqual(VOCE["voce-14"].question);
    });
});
