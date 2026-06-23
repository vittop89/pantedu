/**
 * dom-block-extractor.js — Single source of truth per estrarre i blocchi
 * "data-raw" (text/latex/badge) + TikZ (rendered/inert) + GeoGebra + liste
 * DSA da un .fm-collection__item del contract render.
 *
 * Usato da:
 *   - js/modules/features/topbar-modern.js (SalvaTEX → /api/verifica/save-tex)
 *   - js/modules/print/verifiche-print-ui.js (Stampa verifica panel admin)
 *
 * Convenzione: il selettore include `.fm-dsa-li-list` SPECIFICAMENTE
 * (non `ol, ul` generico) perché .fm-collection__item è figlio di
 * <ol class="fm-collexercise"> legacy: un filter su `closest("ol, ul")`
 * scarterebbe tutto. La classe `fm-dsa-li-list` viene emessa da
 * ContractRenderer per ogni blocco list (sia question che solution
 * dopo refactor #4).
 */

/**
 * Ripristina il SORGENTE LaTeX nei `.fm-latex` typeset da MathJax dentro un
 * CLONE. MathJax sostituisce il testo `\(...\)` con un `<mjx-container>` di
 * GLIFI Unicode (es. √ U+221A, 𝑥 U+1D465); se questi finiscono nel TEX, pdflatex
 * fallisce con "Unicode character not set up for use with LaTeX". L'attributo
 * `data-raw` conserva la sorgente non compilata: la re-iniettiamo come
 * testo così il Sanitizer riceve `\(...\)` e non i glifi. Va chiamato SOLO su
 * cloni (i branch lista/tabella emettono outerHTML, non `data-raw`, quindi senza
 * questo passo il math annidato veniva perso).
 *
 * @param {Element} clone — nodo CLONATO (mai il DOM live)
 */
function restoreLatexSourceInClone(clone) {
    if (!clone.querySelectorAll) return;
    // .fm-latex: data-raw = `\(...\)` puro → textContent (sicuro anche con < >).
    clone.querySelectorAll(".fm-latex[data-raw]").forEach((s) => {
        const src = s.getAttribute("data-raw");
        if (src) s.textContent = src;
    });
    // .fm-text: data-raw = HTML sorgente con math INLINE `\(...\)`. MathJax
    // tipesetta l'inline → <mjx-container> di glifi nel rendered; dentro liste/
    // tabelle (outerHTML) quei glifi finivano nel TEX (√, 𝑥 → "not set up").
    // Ripristiniamo l'innerHTML al data-raw SOLO se contiene math tipesettato
    // (guard: evita re-parse inutile e il rischio di < non-math nel testo).
    clone.querySelectorAll(".fm-text[data-raw]").forEach((s) => {
        const src = s.getAttribute("data-raw");
        if (src != null && /<mjx-|mjx-container/i.test(s.innerHTML)) {
            s.innerHTML = src;
        }
    });
}

/**
 * Concatena un array di nodi DOM in una stringa HTML/text adatta ad essere
 * inviata al backend (Sanitizer la convertirà in LaTeX).
 *
 * @param {Element[]} nodes — risultato di querySelectorAll
 * @returns {string} payload HTML concatenato
 */
export function collectRawNodes(nodes) {
    if (!nodes.length) return "";
    const parts = [];
    nodes.forEach((n) => {
        // TikZ rendered: ricostruisci <script type="text/tikz">.
        // Il SVG ha data-tikz-tagopen + data-tikz-body URL-encoded.
        if (n.matches?.("svg[data-tikz-hash]")) {
            const tagOpen = decodeURIComponent(
                n.getAttribute("data-tikz-tagopen") || '<script type="text/tikz">',
            );
            const body = decodeURIComponent(n.getAttribute("data-tikz-body") || "");
            parts.push(`\n${  tagOpen  }${body  }</` + `script>\n`);
            return;
        }
        // TikZ inert (non ancora renderizzato).
        if (n.matches?.("script[type^='text/tikz']")) {
            parts.push(`\n${  n.outerHTML  }\n`);
            return;
        }
        // GeoGebra wrapper: emetti as-is, Sanitizer estrarrà data-ggb-*+SVG.
        if (n.matches?.(".fm-geogebra-wrap")) {
            parts.push(`\n${  n.outerHTML  }\n`);
            return;
        }
        // Liste DSA: emetti outerHTML preservando struttura ol/ul/li.
        // Sanitizer convertirà a \begin{enumerate}/\item nel TEX finale.
        // G27.dsa — pre-process clone: per ogni <li> con data-fm-dsa-state
        // = "F"|"GF", inietta "(*F*) "/"(*GF*) " come primo text node, cosi
        // il sanitizer lo include nel contenuto del \item. Su CLONE per
        // non mutare il DOM live. Anche su <li> nested (sub-list, options).
        if (n.matches?.(".fm-dsa-li-list")) {
            const clone = n.cloneNode(true);
            clone.querySelectorAll("li[data-fm-dsa-state]").forEach((li) => {
                const state = li.getAttribute("data-fm-dsa-state");
                if (state !== "F" && state !== "GF") return;
                // Skip pulsanti F/GF (non vogliamo duplicare il marker).
                li.querySelectorAll(".fm-dsa-li-buttons").forEach((b) => b.remove());
                // Inietta il prefisso testuale come primo nodo di contenuto.
                const prefix = document.createTextNode(`(*${state}*) `);
                // Per outer-list: il content e' in .fm-dsa-li-content.
                const contentSpan = li.querySelector(":scope > .fm-dsa-li-content");
                if (contentSpan) {
                    contentSpan.insertBefore(prefix, contentSpan.firstChild);
                } else {
                    // Per sub/options: il content e' direttamente nel <li>.
                    li.insertBefore(prefix, li.firstChild);
                }
            });
            // Cleanup pulsanti F/GF residui (rimossi sopra solo per li con state).
            clone.querySelectorAll(".fm-dsa-li-buttons").forEach((b) => b.remove());
            restoreLatexSourceInClone(clone);
            parts.push(`\n${  clone.outerHTML  }\n`);
            return;
        }
        // Tabella RM: emetti outerHTML, Sanitizer convertirà a `\begin{tabular}`
        // (vedi Sanitizer::convertRmTable). Preserva markup nested (cells con
        // liste/inline format) per round-trip completo.
        // G27.dsa — clone + pre-process delle <li data-fm-dsa-state> dentro
        // le celle RM (analogo al branch .fm-dsa-li-list): inietta prefisso
        // testuale "(*F*) "/"(*GF*) " e rimuove pulsanti UI.
        if (n.matches?.("table.fm-rm-table")) {
            const clone = n.cloneNode(true);
            // Phase 24.78 — celle T (text) / N (number): cloneNode copia gli
            // ATTRIBUTI ma NON la PROPERTY `.value` digitata in-sessione. Senza
            // questo sync il valore (la soluzione del docente) non finisce
            // nell'outerHTML → il Sanitizer non lo rende in LaTeX. Iteriamo input
            // originali↔clone in parallelo (stessa struttura) e settiamo
            // l'attributo value dalla property live.
            const srcInputs = n.querySelectorAll("input.fm-rm-text, input.fm-rm-num");
            const dstInputs = clone.querySelectorAll("input.fm-rm-text, input.fm-rm-num");
            srcInputs.forEach((src, i) => {
                const dst = dstInputs[i];
                if (dst && src.value !== "") dst.setAttribute("value", src.value);
            });
            clone.querySelectorAll("li[data-fm-dsa-state]").forEach((li) => {
                const state = li.getAttribute("data-fm-dsa-state");
                if (state !== "F" && state !== "GF") return;
                li.querySelectorAll(".fm-dsa-li-buttons").forEach((b) => b.remove());
                const prefix = document.createTextNode(`(*${state}*) `);
                const contentSpan = li.querySelector(":scope > .fm-dsa-li-content");
                if (contentSpan) {
                    contentSpan.insertBefore(prefix, contentSpan.firstChild);
                } else {
                    li.insertBefore(prefix, li.firstChild);
                }
            });
            clone.querySelectorAll(".fm-dsa-li-buttons").forEach((b) => b.remove());
            restoreLatexSourceInClone(clone);
            parts.push(`\n${  clone.outerHTML  }\n`);
            return;
        }
        // Default: nodi data-raw (text/latex/badge).
        const raw = n.getAttribute("data-raw") || "";
        if (!raw) return;
        const isBlock = n.tagName === "P" || n.tagName === "DIV";
        const clean = raw.replace(/\n{3,}/g, "\n\n").trim();
        if (clean) parts.push(isBlock ? `\n${clean}\n` : clean);
    });
    return parts.join(" ").replace(/\s+\n/g, "\n").replace(/\n\s+/g, "\n").trim();
}

/**
 * Selettore unico per nodi semantici da estrarre. Single source of truth.
 */
const RAW_SELECTOR =
    ".fm-text[data-raw], .fm-latex[data-raw], .fm-badge[data-raw], " +
    "svg[data-tikz-hash], script[type^='text/tikz'], .fm-geogebra-wrap, " +
    ".fm-dsa-li-list, table.fm-rm-table";

/**
 * Filter: skip nodi annidati DENTRO una .fm-dsa-li-list (parent emette
 * outerHTML che già include sub-content). NB: usa `parentElement.closest`
 * (NON `closest`) per non matchare se stesso quando il nodo HA la classe
 * fm-dsa-li-list (caso liste annidate: sub-ul ha self-class ma è dentro
 * outer-ol e va skippato).
 */
function notInsideList(n) {
    const parent = n.parentElement;
    return !parent || !parent.closest(".fm-dsa-li-list");
}

/** Filter: skip nodi annidati DENTRO una table.fm-rm-table. Il <table> emette
 *  outerHTML che già contiene cells.fm-text/.fm-latex/liste → senza questo
 *  filtro ogni cella sarebbe duplicata fuori dalla tabella. */
function notInsideRmTable(n) {
    if (n.matches?.("table.fm-rm-table")) return true;
    const parent = n.parentElement;
    return !parent || !parent.closest("table.fm-rm-table");
}

/** Filter: skip nodi che sono DENTRO una soluzione/giustificazione.
 *  `.fm-li-inline` contiene SIA `.fm-collection` SIA `.fm-sol`/`.fm-giustsol` come children;
 *  senza questo filter `problemNodes` catturerebbe anche i nodi della soluzione
 *  → duplicazione (sol appare anche nel content del quesito). */
function notInsideSol(n) {
    return !n.closest(".fm-sol, .fm-giustsol, .fm-giustifica");
}

/**
 * Estrae da un .fm-collection__item DOM il payload separato problem (.fm-collection)
 * vs solution (.fm-sol/.fm-giustsol/.giustifica).
 *
 * IMPORTANTE: `.fm-sol` e `.fm-giustsol` sono SIBLING di `.fm-collection` dentro il
 * `.fm-collection__item`, NON children. Quindi serve scoping multiplo.
 *
 * Strategy:
 *   1. problemScope = primo `.fm-li-inline` (verifica) o `.fm-collection` (esercizio)
 *   2. solScopes = TUTTI i `.fm-sol/.fm-giustsol/.giustifica` dentro l'item
 *   3. Su ognuno: querySelectorAll(RAW_SELECTOR) + filter(notInsideList)
 *   4. Ritorna {html, sol} concatenati via collectRawNodes
 *
 * Fallback: se nessun nodo data-raw matchato, usa .fm-collection.innerHTML
 * (per esercizi senza il rendering "moderno" data-raw).
 *
 * @param {Element} el — `.fm-collection__item` div
 * @returns {{html: string, sol: string}}
 */
export function extractItemHtml(el) {
    const problemScope = el.querySelector(".fm-li-inline") || el.querySelector(".fm-collection");
    const solScopes = el.querySelectorAll(".fm-sol, .fm-giustsol, .fm-giustifica");

    const problemNodes = problemScope
        ? Array.from(problemScope.querySelectorAll(RAW_SELECTOR))
            .filter(notInsideList)
            .filter(notInsideRmTable)
            .filter(notInsideSol)
        : [];

    const solNodes = [];
    solScopes.forEach((sol) => {
        Array.from(sol.querySelectorAll(RAW_SELECTOR))
            .filter(notInsideList)
            .filter(notInsideRmTable)
            .forEach((n) => solNodes.push(n));
    });

    // Fallback: nessun nodo "moderno" → innerHTML grezzo del collex
    if (problemNodes.length === 0 && solNodes.length === 0) {
        const collex = el.querySelector(".fm-collection");
        return { html: collex ? collex.innerHTML : el.innerHTML, sol: "" };
    }

    return {
        html: collectRawNodes(problemNodes),
        sol: collectRawNodes(solNodes),
    };
}

/**
 * G27.badge — Estrae i metadata badge da un `.fm-collection__item`. Centralizzato
 * cosi' i due call site della Selection (verifiche-print-ui e topbar-modern)
 * usano la stessa logica di lookup. Ritorna {origin, badge} pronti per essere
 * spread nel payload item; campi vuoti se non presenti nel DOM.
 *
 * Sorgente dati DOM (server-rendered da ContractRenderer):
 *   - <select.origin>             → source_key (item.origin)
 *   - <span.fm-badge data-page=…  → badge.{page,ex_num,difficulty,bg_color}
 *
 * @param {Element} el — `.fm-collection__item` element
 * @returns {{origin: string, badge: object|null}}
 */
export function extractItemBadge(el) {
    if (!el) return { origin: "", badge: null };
    const originSel = el.querySelector(".origin");
    const origin    = originSel?.value && originSel.value !== "origine" ? originSel.value : "";
    const badgeEl   = el.querySelector(".fm-badge");
    if (!badgeEl) return { origin, badge: null };
    const ds = badgeEl.dataset || {};
    const badge = {};
    if (ds.page !== undefined && ds.page !== "")             badge.page       = String(ds.page);
    if (ds.exNum !== undefined && ds.exNum !== "")           badge.ex_num     = String(ds.exNum);
    if (ds.bgColor !== undefined && ds.bgColor !== "")       badge.bg_color   = String(ds.bgColor);
    if (ds.difficulty !== undefined && ds.difficulty !== "") badge.difficulty = parseInt(ds.difficulty, 10) || 0;
    return { origin, badge: Object.keys(badge).length ? badge : null };
}

/**
 * G27.dsa — Estrae il marker DSA item-level (F/GF) dal `.fm-collection__item`.
 * Lo stato e' nel `data-fm-dsa-state` del `.dsa-wrapper-container` dentro
 * il primo `.fm-li-inline` dell'item (vedi dsa-marks.js). Ritorna "F", "GF",
 * o stringa vuota se nessun marker attivo.
 *
 * @param {Element} el — `.fm-collection__item` element
 * @returns {string} "F" | "GF" | ""
 */
export function extractItemMark(el) {
    if (!el) return "";
    const wrap = el.querySelector(".fm-li-inline > .dsa-wrapper-container, .fm-li-inline .fm-badge-row .dsa-wrapper-container");
    const state = wrap?.dataset?.fmDsaState || "";
    return (state === "F" || state === "GF") ? state : "";
}

/**
 * Estrae il testo introduttivo del `.fm-groupcollex` (group intro) come HTML.
 * Mirror del flow item: produce HTML che il Sanitizer server-side converte
 * in LaTeX (b/i/u → \textbf/\textit/\underline, list → enumerate/itemize, ecc.).
 *
 * - Strippa `.giustifica` (label "Giustifica adeguatamente le risposte"
 *   re-added dal renderer per VF/RM, non parte dell'intro editabile).
 * - Strippa `.fm-title-edit` (editing mode wrapper).
 *
 * @param {Element} problem — `.fm-groupcollex` element
 * @returns {string} HTML dell'intro, vuoto se non trovato
 */
export function extractProblemIntroHtml(problem) {
    if (!problem) return "";
    const testoEl = problem.querySelector(":scope > .content .fm-testo > div")
                 || problem.querySelector(":scope > .content .fm-testo");
    if (!testoEl) return "";
    const clone = testoEl.cloneNode(true);
    clone.querySelectorAll(".fm-giustifica, .fm-title-edit").forEach((s) => s.remove());
    return (clone.innerHTML || "").trim();
}
