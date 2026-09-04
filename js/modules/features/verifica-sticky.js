/**
 * Phase 16 — Sticky stacking per i `.fm-collapsible.active` e i titoli h1
 * delle sezioni verifica. Replica 1:1 della logica master legacy
 * `script_sel-mod.js :: updateStickyTops` (commit 8a71c7e).
 *
 * Scope (solo admin):
 *   - sezione `[id^="type_verAll"]` (related-verifiche iniettata su
 *     /studio/esercizio per editing cross-referenziato)
 *   - wrapper `.fm-contract-wrap[data-kind="verifica"]` (topic page
 *     /studio/verifica/...)
 *
 * Algoritmo:
 *   1. `base = (upbar visibile ? 86 : 0) + scrollbarInfo.offsetHeight`.
 *   2. Scan tutti gli h1 delle sezioni + tutti i `.fm-collapsible.active`
 *      in DOM order.
 *   3. stackTop parte da `base`. Ogni elemento "consuma" la sua height:
 *        - h1: già `position:sticky` via CSS → aggiorna solo `top`.
 *        - `.fm-collapsible.active`: se il suo placeholder `.js-coll-ph`
 *          ha superato `stackTop`, lo converte a `position:fixed` con
 *          `left/width` dal rect naturale e il placeholder riempie il
 *          vuoto nel flusso. Altrimenti ripristina il flusso normale.
 *   4. Aggiornamenti su scroll/resize + MutationObserver per classi
 *      upbar-hidden / scrollbarInfo-hidden / `.active`.
 *
 * IMPORTANTE: il CSS standard `position:sticky` non supporta lo stacking
 * di più elementi al medesimo `top` (tutti convergono alla stessa Y).
 * Per questo usiamo `position:fixed` gestito da JS + placeholder.
 */

const COLLAPSIBLE_FALLBACK_H = 30;
const SI_STICKY_TOP = 86;
/* G9.15 — quando topbar moderna (.fm-topbar fixed) e' attiva, la upbar
 * legacy e' nascosta (display:none) e la scrollbarInfo e' un drawer
 * fixed (banner top:46) solo se body.fm-info-open. Calcoliamo
 * dinamicamente l'offset basato su BoundingClientRect della topbar. */
const TOPBAR_FALLBACK_H = 46;

function isInScope() {
    return document.body.classList.contains("fm-admin-access")
        && (document.querySelector('[id^="type_verAll"]')
         || document.querySelector('.fm-contract-wrap[data-kind="verifica"]')
         // Phase 24.77 — anche le pagine ESERCIZIO (stesso ContractRenderer).
         || document.querySelector('.fm-contract-wrap[data-kind="esercizio"]'));
}

function sectionScopeSelector(tag) {
    // Trova elementi dentro gli scope: type_verAll + contract-wrap verifica/esercizio.
    return `body.fm-admin-access [id^="type_verAll"] ${tag},`
        + ` body.fm-admin-access .fm-contract-wrap[data-kind="verifica"] ${tag},`
        + ` body.fm-admin-access .fm-contract-wrap[data-kind="esercizio"] ${tag}`;
}

const _stickyData = new Map();

function _ensureData(col) {
    let d = _stickyData.get(col);
    if (!d) {
        const ph = document.createElement("div");
        ph.className = "fm-js-coll-ph";
        ph.style.height = "0px";
        col.parentNode.insertBefore(ph, col);
        // `.selection` (A/R checkboxes in `.PosCheckEs`) segue il collapsible
        // quando viene fissato: il legacy lo posiziona a sinistra, sopra la
        // colonna dei .PosCheckEs. Salvo ref + origWidth per ripristino.
        const problem = col.closest(".fm-groupcollex");
        const sel = problem ? problem.querySelector(".selection") : null;
        d = {
            ph,
            isFixed: false,
            origWidth: col.style.width || null,
            selection: sel,
            origSelWidth: sel ? (sel.style.width || null) : null,
        };
        _stickyData.set(col, d);
    }
    return d;
}

function _restoreSelection(d) {
    if (!d.selection) return;
    d.selection.style.removeProperty("position");
    d.selection.style.removeProperty("top");
    d.selection.style.removeProperty("left");
    d.selection.style.removeProperty("z-index");
    if (d.origSelWidth !== null) d.selection.style.width = d.origSelWidth;
    else d.selection.style.removeProperty("width");
}

function _restore(col, d) {
    col.style.removeProperty("position");
    col.style.removeProperty("top");
    col.style.removeProperty("left");
    col.style.removeProperty("z-index");
    if (d.origWidth !== null) col.style.width = d.origWidth;
    else col.style.removeProperty("width");
    d.ph.style.height = "0px";
    _restoreSelection(d);
    d.isFixed = false;
}

function _free(col) {
    const d = _stickyData.get(col);
    if (!d) return;
    _restore(col, d);
    d.ph?.remove();
    _stickyData.delete(col);
}

function _restoreToolbar(tb) {
    tb.style.removeProperty("position");
    tb.style.removeProperty("top");
    tb.style.removeProperty("z-index");
}

/** Computa il top base dinamico (sotto upbar+infoVer). Identico alla formula
 *  in checkin-handlers.js `updateProblemStickyOffset`, ma autonoma.
 *
 *  G9.15 — supporto modalita' topbar moderna:
 *    body.fm-topbar-active → upbar legacy nascosta, fm-topbar fixed top:0;
 *    Usa altezza fm-topbar (default 46) + scrollbarInfo banner se aperto
 *    (body.fm-info-open). NON considera display:none/upbar-hidden classi
 *    legacy che non vengono settate dal nuovo flow. */
function computeBaseTop() {
    if (document.body.classList.contains("fm-topbar-active")) {
        const tb = document.getElementById("fm-topbar");
        const tbH = tb && !tb.hidden ? Math.round(tb.getBoundingClientRect().height) : TOPBAR_FALLBACK_H;
        let extra = 0;
        if (document.body.classList.contains("fm-info-open")) {
            const si = document.getElementById("scrollbarInfo");
            if (si && getComputedStyle(si).display !== "none") {
                extra = Math.round(si.getBoundingClientRect().height);
            }
        }
        return Math.max(0, tbH + extra);
    }
    // Legacy path
    const upbar = document.querySelector(".fm-upbar");
    const si = document.getElementById("scrollbarInfo");
    const upbarVisible = upbar && !upbar.classList.contains("upbar-hidden")
                       && getComputedStyle(upbar).display !== "none";
    const siVisible = si && !si.classList.contains("fm-scrollbar-info-hidden")
                    && getComputedStyle(si).display !== "none";
    const base = upbarVisible ? SI_STICKY_TOP : 0;
    const extra = siVisible ? si.getBoundingClientRect().height : 0;
    return Math.max(0, Math.round(base + extra));
}

export function updateStickyTops() {
    if (!isInScope()) return;

    const collH = parseInt(
        getComputedStyle(document.documentElement).getPropertyValue("--heightCollapsible")
    ) || COLLAPSIBLE_FALLBACK_H;

    // Pulisci dati per collapsible non più attivi o rimossi dal DOM.
    _stickyData.forEach((_d, col) => {
        if (!col.classList.contains("active") || !document.contains(col)) {
            _free(col);
        }
    });

    let stackTop = computeBaseTop();

    // 1. TOOLBAR EDITOR (sticky per prima, sopra h1 e collapsible). Se l'editor
    // globale è presente e visibile, sticka al top dello stack — l'utente vuole
    // la toolbar SEMPRE visibile durante l'editing.
    const toolbar = document.getElementById("fm-editor-toolbar-global");
    if (toolbar && getComputedStyle(toolbar).display !== "none") {
        toolbar.style.position = "sticky";
        toolbar.style.top = `${stackTop  }px`;
        toolbar.style.zIndex = "25";
        stackTop += toolbar.offsetHeight;
    } else if (toolbar) {
        _restoreToolbar(toolbar);
    }

    // 2. H1 dei titoli + .fm-collapsible.active stackati in DOM order.
    const titoli = Array.from(document.querySelectorAll(sectionScopeSelector('> .fm-titolo > h1')));
    const actives = Array.from(document.querySelectorAll(sectionScopeSelector('.fm-collapsible.active')));
    const all = titoli.concat(actives).sort((a, b) => {
        return a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1;
    });

    for (const el of all) {
        if (el.tagName === "H1") {
            el.style.top = `${stackTop  }px`;
            const mb = parseInt(getComputedStyle(el).marginBottom) || 0;
            stackTop += el.offsetHeight + mb;
            continue;
        }
        // .fm-collapsible.active → position:fixed + placeholder, con `.selection`
        // ancorata alla stessa Y (replica legacy).
        const d = _ensureData(el);
        const phRect = d.ph.getBoundingClientRect();
        if (phRect.top < stackTop) {
            if (!d.isFixed) {
                const r = el.getBoundingClientRect();
                d.ph.style.height = `${collH  }px`;
                // Phase 24.77 — width/left con `!important`: G20.7 impone
                // `width:auto !important` su `.fm-collapsible` (flex 1 1 auto per
                // riempire la riga in-flow). Da fixed quell'auto = shrink-to-
                // content → la barra collassava. L'inline deve vincere l'!important.
                el.style.setProperty("left", `${r.left}px`, "important");
                el.style.setProperty("width", `${r.width}px`, "important");
                el.style.position = "fixed";
                if (d.selection) {
                    const sr = d.selection.getBoundingClientRect();
                    d.selection.style.setProperty("left", `${sr.left}px`, "important");
                    d.selection.style.setProperty("width", `${sr.width}px`, "important");
                    d.selection.style.zIndex = "30";
                    d.selection.style.position = "fixed";
                }
                d.isFixed = true;
            }
            el.style.top = `${stackTop  }px`;
            if (d.selection) d.selection.style.top = `${stackTop  }px`;
        } else if (d.isFixed) {
            _restore(el, d);
        }
        stackTop += collH;
    }
}

let _bound = false;
export function bindVerificaStickyObservers() {
    if (_bound) return;
    _bound = true;

    window.addEventListener("scroll", updateStickyTops, { passive: true });
    window.addEventListener("resize", () => {
        // Su resize: ricalcola left/width dei collapsible fixati dal rect del placeholder.
        _stickyData.forEach((d, col) => {
            if (!d.isFixed) return;
            const phRect = d.ph.getBoundingClientRect();
            col.style.left = `${phRect.left  }px`;
            col.style.width = `${d.ph.offsetWidth  }px`;
        });
        updateStickyTops();
    }, { passive: true });

    // MutationObserver: reagisce a toggle upbar-hidden / scrollbarInfo-hidden
    // e a .active su .fm-collapsible (open/close problem).
    new MutationObserver((muts) => {
        let relevant = false;
        for (const m of muts) {
            if (m.type === "attributes" && m.attributeName === "class") {
                const el = m.target;
                if (el.id === "scrollbarInfo"
                    || el.classList?.contains("fm-upbar")
                    || el.classList?.contains("fm-collapsible")) {
                    relevant = true; break;
                }
            } else if (m.type === "childList") {
                relevant = true; break;
            }
        }
        if (relevant) {
            // Doppio fire: immediato + post-animazione (300ms fade toggleScrollbarInfo).
            updateStickyTops();
            setTimeout(updateStickyTops, 50);
            setTimeout(updateStickyTops, 400);
        }
    }).observe(document.body, {
        attributes: true, attributeFilter: ["class"],
        subtree: true, childList: true,
    });
}

function onInit() {
    bindVerificaStickyObservers();
    updateStickyTops();
    // Retry dopo MathJax typeset (il contenuto cresce → ricalcolo needed).
    window.addEventListener("fm:mathjax-ready", () => {
        setTimeout(updateStickyTops, 50);
    });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", onInit);
} else {
    onInit();
}
window.addEventListener("fm:navigated", () => {
    // Su SPA navigation: libera tutti i collapsible fissati (il DOM precedente
    // è stato smontato, i dati cached puntano a nodi scollegati).
    _stickyData.forEach((_d, col) => _free(col));
    setTimeout(onInit, 50);
});

window.FM = window.FM || {};
window.FM.updateStickyTops = updateStickyTops;
