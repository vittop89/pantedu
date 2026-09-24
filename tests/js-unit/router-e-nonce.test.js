import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";

/**
 * Il router SPA ricrea solo gli script della risposta che portano il suo nonce
 * (23/9/2026, revisione architetturale A-16).
 *
 * `fm-router.js` inietta la pagina con `innerHTML`, che non esegue gli
 * `<script>`, e poi li ricrea uno per uno perché partano. Con la CSP rigorosa
 * uno script creato da uno script fidato gira sempre ('strict-dynamic'), nonce
 * o no: ricrearli tutti voleva dire eseguire anche uno `<script>` arrivato dal
 * contenuto, che al caricamento diretto della stessa pagina il browser avrebbe
 * bloccato. Adesso, se la risposta ha la CSP rigorosa, si ricreano solo gli
 * script con il suo nonce e le isole di dati.
 *
 * Il router è uno script classico (non un modulo): lo si esegue nella finestra
 * di happy-dom, con una `fetch` finta che risponde con l'intestazione e la
 * pagina. Uno script «ricreato» è un nodo nuovo: si riconosce perché il nodo
 * che `innerHTML` aveva messo non è più nel documento.
 */

const QUI = dirname(fileURLToPath(import.meta.url));
const ROUTER = readFileSync(join(QUI, "..", "..", "js", "fm-router.js"), "utf8");
const NONCE = "QUJDREVGR0hJSktMTU5PUA==";

const PAGINA = `
<h1>Pagina</h1>
<script nonce="${NONCE}" data-chi="app">window.__app = 1;</script>
<script nonce="${NONCE}" type="module" src="/js/entries/x.js" data-chi="app-modulo"></script>
<script type="text/tikz" data-chi="tikz">\\begin{tikzpicture}\\end{tikzpicture}</script>
<script type="application/json" data-chi="isola">{"a":1}</script>
<script data-chi="contenuto">window.__contenuto = 1;</script>
<script nonce="altro" data-chi="nonce-sbagliato">window.__altro = 1;</script>
`;

/** La navigazione con la risposta finta; torna quali script sono stati ricreati. */
async function naviga(intestazioni) {
    const originali = new Map();
    const contenitore = document.getElementById("fm-content");
    const osserva = new MutationObserver((mutazioni) => {
        for (const m of mutazioni) {
            for (const n of m.addedNodes) {
                if (n.nodeName === "SCRIPT" && !originali.has(n.dataset.chi)) originali.set(n.dataset.chi, n);
            }
        }
    });
    osserva.observe(contenitore, { childList: true, subtree: true });
    globalThis.fetch = vi.fn(async (url) => ({
        redirected: false,
        url: String(url),
        headers: new Headers(intestazioni),
        text: async () => PAGINA,
    }));

    // Non una rotta di esercizi: lì il router, senza MathJax, ricarica la pagina intera.
    await window.fmRouter.navigate(location.origin + "/privacy/informativa");
    expect(globalThis.fetch).toHaveBeenCalledOnce();
    await new Promise((r) => setTimeout(r, 0));
    osserva.disconnect();

    const ricreati = [];
    for (const [chi, nodo] of originali) {
        if (!nodo.isConnected && contenitore.querySelector(`script[data-chi="${chi}"]`)) ricreati.push(chi);
    }
    return ricreati.sort();
}

beforeEach(() => {
    document.body.innerHTML = '<main id="fm-content"></main>';
    delete window.fmRouter;
    new Function(ROUTER)();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe("router SPA e nonce della CSP", () => {
    it("con la CSP rigorosa ricrea gli script con il nonce della risposta e le isole di dati, non gli altri", async () => {
        const ricreati = await naviga({
            "Content-Security-Policy": `default-src 'self'; script-src 'self' 'nonce-${NONCE}' 'strict-dynamic' blob:`,
        });
        expect(ricreati).toEqual(["app", "app-modulo", "isola", "tikz"]);
        // Quelli senza il nonce giusto restano dove li ha messi innerHTML, inerti.
        const contenitore = document.getElementById("fm-content");
        expect(contenitore.querySelector('script[data-chi="contenuto"]')).not.toBeNull();
        expect(contenitore.querySelector('script[data-chi="nonce-sbagliato"]')).not.toBeNull();
    });

    it("con la CSP rilassata li ricrea tutti, come prima", async () => {
        const ricreati = await naviga({
            "Content-Security-Policy": "default-src 'self'; script-src 'self' 'unsafe-inline' blob:",
        });
        expect(ricreati).toEqual(["app", "app-modulo", "contenuto", "isola", "nonce-sbagliato", "tikz"]);
    });

    it("in report-only non blocca niente, come prima: la policy lì si osserva soltanto", async () => {
        const ricreati = await naviga({
            "Content-Security-Policy-Report-Only": `script-src 'self' 'nonce-${NONCE}' 'strict-dynamic'`,
        });
        expect(ricreati).toHaveLength(6);
    });
});
