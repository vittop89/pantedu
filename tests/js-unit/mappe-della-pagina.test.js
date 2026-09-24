import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import {
    mappeDellaPagina,
    nomeDelFile,
    scaricaMappa,
    rispondiAlVisualizzatore,
    mandaAlleCorniciGiaPronte,
} from "../../js/modules/features/mappe-della-pagina.js";

/**
 * Le mappe della pagina di studio lette dall'isola JSON (23/9/2026, revisione
 * architetturale A-19, R-3 passo 3).
 *
 * Il server (StudyPageRenderer) scriveva l'XML di ogni mappa dentro due
 * `<script>` in linea; adesso lo scrive in
 * `<script type="application/json" data-fm-mappe-xml>`, codificato con
 * JSON_HEX_TAG e gli altri (A-3), e il comportamento dei due script sta nel
 * modulo. L'isola qui sotto è quella che scrive PHP per un file con un
 * `</script>` dentro: `<` e `>` arrivano come \u003C e \u003E, e il modulo
 * deve ridare il file uguale. La controparte PHP è
 * tests/Integration/MappeNegliScriptDellaPaginaTest.php.
 */

const XML = '<mxfile><diagram id="d">x</diagram><n><![CDATA[</script><script>alert(1)</script>]]></n></mxfile>';
// Come json_encode([41 => $xml], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
// | JSON_HEX_QUOT | JSON_FORCE_OBJECT): il valore con i cinque caratteri in \u.
const VALORE = JSON.stringify(XML).slice(1, -1)
    .replace(/\\"/g, "\\u0022").replace(/</g, "\\u003C").replace(/>/g, "\\u003E")
    .replace(/&/g, "\\u0026").replace(/'/g, "\\u0027");
const ISOLA = `{"41":"${VALORE}"}`;

function pagina(html) {
    document.body.innerHTML = html;
}

const VISUALIZZATORE = "https://viewer.diagrams.net/?lightbox=1&embed=1&proto=json&dark=0&nav=1";

/**
 * Una cornice del visualizzatore con una finestra finta, per contare i messaggi.
 * Il `src` si legge con un getAttribute finto: un `src` vero farebbe caricare
 * la pagina a happy-dom, dalla rete.
 */
function cornice(id, src = VISUALIZZATORE) {
    const iframe = document.createElement("iframe");
    iframe.id = "fm-mappa-iframe-" + id;
    const finestra = { postMessage: vi.fn() };
    Object.defineProperty(iframe, "contentWindow", { get: () => finestra });
    const originale = iframe.getAttribute.bind(iframe);
    iframe.getAttribute = (nome) => (nome === "src" ? src : originale(nome));
    document.body.appendChild(iframe);
    return finestra;
}

beforeEach(() => {
    document.body.innerHTML = "";
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe("mappe della pagina: l'isola JSON", () => {
    it("ridà il file uguale, anche con un </script> dentro", () => {
        pagina(`<div class="fm-mappa-study"><script type="application/json" data-fm-mappe-xml>${ISOLA}</script></div>`);
        expect(ISOLA).not.toContain("</script");
        expect(mappeDellaPagina()).toEqual({ 41: XML });
    });

    it("senza isola non ci sono mappe, e un'isola rotta non fa danni", () => {
        expect(mappeDellaPagina()).toEqual({});
        pagina('<script type="application/json" data-fm-mappe-xml>{rotto</script>');
        expect(mappeDellaPagina()).toEqual({});
    });

    it("legge solo le isole delle mappe, non altri dati JSON della pagina", () => {
        pagina(`<script type="application/json" id="altro">{"41":"no"}</script>`
            + `<script type="application/json" data-fm-mappe-xml>{"7":"<mxfile/>"}</script>`);
        expect(mappeDellaPagina()).toEqual({ 7: "<mxfile/>" });
    });
});

describe("«Scarica .drawio»", () => {
    it("scarica il file della mappa del pulsante, col titolo come nome", () => {
        pagina(`<div class="fm-mappa-wrap"><div class="fm-titolo-quesito">1.1 — Moto: a/b</div>`
            + `<button class="fm-mappa-download-btn" data-fm-content-id="41"></button></div>`
            + `<script type="application/json" data-fm-mappe-xml>${ISOLA}</script>`);
        const blobs = [];
        URL.createObjectURL = vi.fn((b) => { blobs.push(b); return "blob:finto"; });
        URL.revokeObjectURL = vi.fn();
        const nomi = [];
        vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function () { nomi.push(this.download); });

        const partito = scaricaMappa(document.querySelector(".fm-mappa-download-btn"), mappeDellaPagina());

        expect(partito).toBe(true);
        expect(nomi).toEqual(["1.1 — Moto_ a_b.drawio"]);
        expect(blobs).toHaveLength(1);
        expect(blobs[0].type).toBe("application/xml");
    });

    it("senza il file in pagina avvisa e non scarica niente", () => {
        pagina('<button class="fm-mappa-download-btn" data-fm-content-id="99"></button>');
        const avviso = vi.fn();
        window.alert = avviso;
        URL.createObjectURL = vi.fn();
        expect(scaricaMappa(document.querySelector("button"), {})).toBe(false);
        expect(avviso).toHaveBeenCalledOnce();
        expect(URL.createObjectURL).not.toHaveBeenCalled();
    });

    it("il nome del file perde i caratteri che un sistema operativo rifiuta", () => {
        expect(nomeDelFile('a\\b/c:d*e?f"g<h>i|j')).toBe("a_b_c_d_e_f_g_h_i_j");
        expect(nomeDelFile("   ")).toBe("mappa");
        expect(nomeDelFile("x".repeat(100))).toHaveLength(80);
    });
});

describe("il visualizzatore delle mappe grandi", () => {
    it("all'init della sua cornice manda il suo file, e a nessun'altra", () => {
        pagina(`<script type="application/json" data-fm-mappe-xml>{"41":"<mxfile>a</mxfile>","42":"<mxfile>b</mxfile>"}</script>`);
        const prima = cornice(41);
        const seconda = cornice(42);

        const risposto = rispondiAlVisualizzatore({ data: '{"event":"init"}', source: seconda, origin: "https://viewer.diagrams.net" });

        expect(risposto).toBe(true);
        expect(prima.postMessage).not.toHaveBeenCalled();
        expect(seconda.postMessage).toHaveBeenCalledOnce();
        const [messaggio, destinazione] = seconda.postMessage.mock.calls[0];
        expect(JSON.parse(messaggio)).toEqual({ action: "load", xml: "<mxfile>b</mxfile>", autosave: 0 });
        expect(destinazione).toBe("https://viewer.diagrams.net");
    });

    it("manda il file solo all'origine della cornice, mai a tutti", () => {
        pagina(`<script type="application/json" data-fm-mappe-xml>{"41":"<mxfile>a</mxfile>","42":"<mxfile>b</mxfile>"}</script>`);
        const senzaSrc = cornice(41, "");
        const buona = cornice(42);

        expect(mandaAlleCorniciGiaPronte()).toBe(1);
        expect(senzaSrc.postMessage).not.toHaveBeenCalled();
        for (const [, destinazione] of buona.postMessage.mock.calls) {
            expect(destinazione).not.toBe("*");
            expect(destinazione).toBe("https://viewer.diagrams.net");
        }
    });

    it("non risponde a un init che arriva da un'altra origine", () => {
        pagina(`<script type="application/json" data-fm-mappe-xml>{"41":"<mxfile>a</mxfile>"}</script>`);
        const sua = cornice(41);

        expect(rispondiAlVisualizzatore({ data: '{"event":"init"}', source: sua, origin: "https://altro.example" })).toBe(false);
        expect(sua.postMessage).not.toHaveBeenCalled();
    });

    it("non risponde ad altri messaggi, né a finestre che non sono le sue cornici", () => {
        pagina(`<script type="application/json" data-fm-mappe-xml>{"41":"<mxfile>a</mxfile>"}</script>`);
        const sua = cornice(41);
        const estranea = { postMessage: vi.fn() };

        expect(rispondiAlVisualizzatore({ data: '{"event":"init"}', source: estranea })).toBe(false);
        expect(rispondiAlVisualizzatore({ data: '{"event":"save"}', source: sua })).toBe(false);
        expect(rispondiAlVisualizzatore({ data: "non json", source: sua })).toBe(false);
        expect(sua.postMessage).not.toHaveBeenCalled();
    });

    it("le cornici già in pagina quando parte il modulo ricevono subito il file", () => {
        pagina(`<script type="application/json" data-fm-mappe-xml>{"41":"<mxfile>a</mxfile>","43":"<mxfile>c</mxfile>"}</script>`);
        const sua = cornice(41);
        // La 43 è una mappa piccola: ha il file (per il download) ma nessuna cornice embed.

        expect(mandaAlleCorniciGiaPronte()).toBe(1);
        expect(JSON.parse(sua.postMessage.mock.calls[0][0]).xml).toBe("<mxfile>a</mxfile>");
    });
});
