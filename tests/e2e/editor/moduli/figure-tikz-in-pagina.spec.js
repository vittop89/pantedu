// @ts-check
/**
 * Il disegno delle figure TikZ dentro la pagina. @tex
 * Riscrittura di g22_s15_tikz_render.spec.js.
 *
 * Una figura si scrive come un blocco `<script type="text/tikz">`: il modulo
 * lo manda a compilare e lo sostituisce con l'immagine. Quando funziona, in
 * pagina non resta nessun blocco da compilare e nessun riquadro d'errore.
 *
 * Le tre strade che ci arrivano sono la stessa: la pagina di lettura,
 * l'anteprima mentre si scrive, e l'anteprima dentro l'editor. Sono verificate
 * tutte e tre perché ognuna passa da un contenitore diverso, e più volte è
 * capitato che una funzionasse e le altre no.
 *
 * Cosa cambia rispetto a prima: la compilazione è quella vera, non una finta
 * risposta preparata dal test — e soprattutto `/auth/csrf` non è più
 * intercettato: il gettone arriva dalla sessione, come in ogni altra spec.
 */
const { test, expect } = require("../../support/test");

/** Una figura semplice, che compila in fretta. */
const FIGURA = "\\begin{tikzpicture}\\draw[->] (0,0) -- (2,2);\\end{tikzpicture}";

test.describe("Editor — figure TikZ disegnate in pagina", () => {
    test("il modulo espone quel che serve per disegnare", async ({ bancoEditor }) => {
        const funzioni = await bancoEditor.page.evaluate(async () => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const mod = await import("/js/modules/editor/tikz-render-client.js");
            return {
                disegna: typeof mod.renderAll,
                normalizza: typeof mod.normalizeTikz,
                impronta: typeof mod.sha256Hex,
            };
        });
        expect(funzioni, "disegno, normalizzazione e impronta del sorgente").toEqual({
            disegna: "function",
            normalizza: "function",
            impronta: "function",
        });
    });

    /**
     * Disegna la figura dentro un contenitore appena creato e riporta l'esito.
     * @param {import("@playwright/test").Page} pagina
     * @param {string} classe classe del contenitore, come nella pagina vera
     */
    async function disegnaIn(pagina, classe) {
        return pagina.evaluate(async ([nomeClasse, figura]) => {
            // @ts-ignore — modulo dell'applicazione caricato dal browser: il percorso non è risolvibile qui
            const { renderAll } = await import("/js/modules/editor/tikz-render-client.js");
            const contenitore = document.createElement("div");
            contenitore.className = nomeClasse;
            contenitore.innerHTML = `<script type="text/tikz">${figura}</` + "script>";
            document.body.appendChild(contenitore);

            const esito = await renderAll(contenitore, { fallbackToTikzJax: false });
            const risultato = {
                errori: esito.errors ?? [],
                immagini: contenitore.querySelectorAll("svg").length,
                blocchiRimasti: contenitore.querySelectorAll('script[type="text/tikz"]').length,
                riquadriDiErrore: contenitore.querySelectorAll(".fm-tikz-error-messages-block").length,
            };
            contenitore.remove();
            return risultato;
        }, /** @type {[string, string]} */ ([classe, FIGURA]));
    }

    const CONTENITORI = [
        { classe: "fm-contract-wrap", nome: "nella pagina di lettura" },
        { classe: "fm-editor-preview", nome: "nell'anteprima mentre si scrive" },
        { classe: "fm-latex-viewer", nome: "nell'anteprima dell'editor" },
    ];

    for (const { classe, nome } of CONTENITORI) {
        test(`la figura viene disegnata ${nome} @tex`, async ({ bancoEditor }) => {
            test.setTimeout(300_000);
            const esito = await disegnaIn(bancoEditor.page, classe);
            expect(esito.errori, "nessun errore di disegno").toEqual([]);
            expect(esito.immagini, "l'immagine c'è").toBe(1);
            expect(esito.blocchiRimasti, "e il blocco da compilare non c'è più").toBe(0);
            expect(esito.riquadriDiErrore, "nessun riquadro d'errore in pagina").toBe(0);
        });
    }
});
