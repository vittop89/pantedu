/**
 * resize-sync.js — Sincronizzazione bidirezionale altezza textarea ⇄ preview.
 *
 * Contesto: nell'editor inline (checkin-handlers.js) ogni campo è composto
 * da una textarea sorgente (`.fm-editor-field`, `resize:vertical` CSS) e
 * un div preview affianco (`.fm-editor-preview`, MathJax/TikZ rendered).
 * L'utente può trascinare l'angolo del textarea: la preview deve seguire.
 * Viceversa, se la preview cresce per contenuto (es. SVG TikZ alto), la
 * textarea deve adeguarsi (minHeight) per restare allineata.
 *
 * STRATEGIA (anti-loop):
 *   Direzione A — ta → pv (drag manuale del corner-resize CSS):
 *     ResizeObserver su `ta`. Quando ta.offsetHeight cambia, applica
 *     `pv.style.height = ta.offsetHeight + "px"`. Forziamo anche
 *     `maxHeight:none` per superare il `max-height:300px/200px` inline.
 *
 *   Direzione B — pv → ta (contenuto preview cresce naturalmente):
 *     MutationObserver sul subtree di pv (NON ResizeObserver: il pv ha
 *     ora un'altezza esplicita e ResizeObserver non scatterebbe più sulle
 *     mutazioni di contenuto). Su mutation, misuriamo l'altezza NATURALE
 *     del contenuto temporaneamente azzerando pv.style.height, leggiamo
 *     pv.scrollHeight, ripristiniamo. Se la naturale > ta corrente,
 *     imponiamo `ta.style.minHeight = naturale + "px"`. NON tocchiamo
 *     `ta.style.height` — l'utente resta libero di rimpicciolire (entro
 *     il minHeight, cioè finché il preview ha quel contenuto).
 *
 * Anti-loop:
 *   - Direzione A non innesca B perché B è MutationObserver (reagisce
 *     solo a cambi DOM, non a cambi dimensione).
 *   - Direzione B non innesca A perché impostare ta.minHeight non
 *     altera ta.offsetHeight (ta è già >= minHeight).
 *   - Caso limite: se ta era < pvNaturale, ta cresce → A scatta → set
 *     pv.style.height = ta.offsetHeight (= pvNaturale). Pv non muta DOM
 *     → B non riscatta. Convergente.
 *
 * Idempotenza: dataset flag `fmResizeSync=1`.
 *
 * Cleanup: handle salvato su `ta._fmResizeSync = { disconnect }`. Inoltre
 * un MutationObserver sul parent del ta scollega automaticamente quando
 * ta viene rimosso dal DOM (chiusura editor) → no memory leak.
 *
 * Compatibilità futura CodeMirror: l'osservazione è agganciata al primo
 * argomento (`ta`) e al secondo (`pv`); non assume tagName. Quando
 * il textarea sarà sostituito da un wrapper CM, basterà passare il wrapper
 * (la lettura di offsetHeight è generica).
 *
 * @param {HTMLElement} ta  Sorgente (textarea o wrapper editor)
 * @param {HTMLElement} pv  Preview pane
 * @param {{minDelta?:number, debounceMs?:number}} [opts]
 * @returns {{disconnect:()=>void} | null}
 */
export function installResizeSync(ta, pv, opts = {}) {
    if (!ta || !pv) return null;
    if (ta.dataset.fmResizeSync === "1") return ta._fmResizeSync || null;
    if (typeof ResizeObserver === "undefined") return null;
    if (typeof MutationObserver === "undefined") return null;

    const minDelta   = Number.isFinite(opts.minDelta)   ? opts.minDelta   : 2;
    const debounceMs = Number.isFinite(opts.debounceMs) ? opts.debounceMs : 80;

    // STRATEGIA: NO ResizeObserver. Il flex container row ha
    // align-items:stretch quindi pv segue automaticamente la height di ta
    // senza JS, A CONDIZIONE che pv non abbia max-height inline che lo
    // limita. Rimuoviamo SOLO il max-height del preview e lasciamo che CSS
    // flex stretch faccia il sync. Niente loop possibile (no observer).
    //
    // Trade-off vs versione observer: se il contenuto di pv supera il ta,
    // appare scrollbar interno (acceptable). Il ta segue il drag manuale
    // del corner-resize CSS, pv stretch via flex.
    const _origPvMaxHeight = pv.style.maxHeight;
    pv.style.maxHeight = "none";

    const lastTaH = 0;        // unused — dummy
    let mutTimer = 0;       // unused
    let lastBSet = 0;       // unused
    let lastNaturalSet = 0; // unused
    void lastTaH; void mutTimer; void lastBSet; void lastNaturalSet;

    // Stub: ResizeObserver disabilitato. Vedi nota sopra.
    const obsTa = { observe() {}, disconnect() {} };

    // ── DIREZIONE B: contenuto pv muta → forse cresciuto → alza ta.minHeight.
    // Misurazione altezza NATURALE: salva pv.style.height, lo azzera, legge
    // scrollHeight, ripristina. Tutto sincrono prima del paint, no flicker.
    const measureNaturalPvH = () => {
        const savedH = pv.style.height;
        pv.style.height = "auto";
        const natural = pv.scrollHeight;
        pv.style.height = savedH;
        return natural;
    };

    const onPvMutate = () => {
        if (mutTimer) clearTimeout(mutTimer);
        mutTimer = setTimeout(() => {
            mutTimer = 0;
            if (!ta.isConnected || !pv.isConnected) return;
            // Throttle: max 1 set di minHeight ogni 500ms (anti-loop safety)
            if (Date.now() - lastBSet < 500) return;
            const natural = measureNaturalPvH();
            const taH = ta.offsetHeight;
            // Solo se contenuto preview supera ta corrente → impone minHeight.
            // Anti-loop: se la natural e' uguale (o quasi) all'ultima impostata,
            // skippa — siamo gia' stabili.
            if (Math.abs(natural - lastNaturalSet) < minDelta) return;
            // Non tocchiamo height: l'utente resta padrone del corner-resize.
            if (natural > taH + minDelta) {
                ta.style.minHeight = `${natural  }px`;
                lastBSet = Date.now();
                lastNaturalSet = natural;
            }
        }, debounceMs);
    };

    // DIREZIONE B DISABILITATA — causava loop infinito di crescita anche
    // dopo molti tentativi di filtraggio. La crescita dinamica del preview
    // (TikZ async, MathJax typeset) re-triggera B che alza ta.minHeight,
    // A vede il cambio, set pv.style.height, re-trigger SVG width/height
    // attrs, B re-misura, loop. Soluzione minima: solo direzione A.
    // L'utente trascina manualmente il corner del textarea per far crescere
    // entrambi (A → pv segue ta). Se preview cresce sopra ta per contenuto,
    // appare scroll interno (acceptable).
    const mutObs = { disconnect() {} };
    void onPvMutate; // unused per ora, lasciato per re-enable futuro
    void measureNaturalPvH;

    obsTa.observe(ta);

    // MutationObserver sul parent del ta: se ta viene staccato dal DOM
    // (chiusura editor / re-render row), scolleghiamo tutto.
    let domObs = null;
    const parent = ta.parentNode;
    if (parent) {
        domObs = new MutationObserver(() => {
            if (!ta.isConnected) handle.disconnect();
        });
        domObs.observe(parent, { childList: true, subtree: false });
    }

    const handle = {
        disconnect() {
            try { obsTa.disconnect(); } catch (_) {}
            try { mutObs.disconnect(); } catch (_) {}
            if (domObs) { try { domObs.disconnect(); } catch (_) {} }
            if (mutTimer) clearTimeout(mutTimer);
            // Ripristina maxHeight originale per evitare side-effect se
            // l'elemento viene riusato (e.g. cache row reuse).
            pv.style.maxHeight = _origPvMaxHeight;
            delete ta.dataset.fmResizeSync;
            delete pv.dataset.fmResizeSync;
            delete ta._fmResizeSync;
        },
    };

    ta.dataset.fmResizeSync = "1";
    pv.dataset.fmResizeSync = "1";
    ta._fmResizeSync = handle;

    return handle;
}

/** Scollega il sync precedentemente installato (idempotente). */
export function uninstallResizeSync(ta) {
    const h = ta && ta._fmResizeSync;
    if (h && typeof h.disconnect === "function") h.disconnect();
}
