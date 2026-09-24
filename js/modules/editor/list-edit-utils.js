/**
 * G24.refactor5.step4 — Estratto da `features/checkin-handlers.js` (monolite
 * 8900+ LOC). Utilities di editing liste OL/UL: indent, outdent, ricerca
 * <li> contenitore, split fragment in righe, insert HTML at caret, create
 * empty list.
 *
 * Dipendenze: caret-utils per spostare caret post-edit.
 * Tutte funzioni pure di DOM mutation (no file-state). Riusabili da
 * qualsiasi editor inline che gestisce liste annidate.
 */

import {
    placeCaretAtEnd,
    placeCaretAtStart,
} from "./caret-utils.js";

/** Cerca il `<li>` più vicino risalendo dall'`node` fino a `field` (escluso).
 *  Ritorna null se non c'è LI ancestor (caret fuori da lista). */
export function findEnclosingLi(node, field) {
    let cur = node;
    while (cur && cur !== field) {
        if (cur.nodeType === Node.ELEMENT_NODE && cur.tagName === "LI") {
            return cur;
        }
        cur = cur.parentNode;
    }
    return null;
}

/**
 * Indent (sub-list): wrappa il <li> in una nuova <ol>/<ul> e la attacca
 * come ultimo figlio del <li> PRECEDENTE (pattern Google Docs/Word).
 *
 * Esempio:
 *   <ol><li>A</li><li>B|</li></ol>          (caret su B)
 * Tab →
 *   <ol><li>A<ol><li>B|</li></ol></li></ol>
 *
 * Se non c'è previous sibling, no-op (browser convention: il primo li non
 * può essere indentato senza crearne uno parent vuoto).
 */
export function indentListItem(li) {
    const parentList = li.parentElement;
    if (!parentList || !/^(OL|UL)$/i.test(parentList.tagName)) return;
    const prevLi = li.previousElementSibling;
    if (!prevLi || prevLi.tagName !== "LI") return; // primo elemento, no-op

    // Cerca un <ol>/<ul> esistente come ultimo figlio di prevLi → riusa
    const lastChild = prevLi.lastElementChild;
    let nestedList;
    if (lastChild && /^(OL|UL)$/i.test(lastChild.tagName)) {
        nestedList = lastChild;
    } else {
        nestedList = document.createElement(parentList.tagName);
        // Eredita class fm-dsa-li-list per coerenza marker styling.
        nestedList.className = "fm-dsa-li-list";
        const sec = parentList.getAttribute("data-dsa-section");
        if (sec) nestedList.setAttribute("data-dsa-section", sec);
        prevLi.appendChild(nestedList);
    }
    // Sposta il li nella nested list
    nestedList.appendChild(li);
    placeCaretAtEnd(li);
}

/**
 * Outdent: sposta il <li> fuori dalla parent ol/ul.
 *
 *   - Se la parent ol/ul è nested DENTRO un <li> "nonno", il <li> diventa
 *     sibling DOPO il nonno
 *   - Se la parent ol/ul è top-level (figlio diretto del field), il <li>
 *     diventa block <div> al suo posto
 *   - removeIfEmpty=true e li vuoto → rimuove il li (caso Enter su li vuoto:
 *     vogliamo "exit list" senza creare un vuoto in più)
 *
 * Nessuna voce cambia posto (24/9/2026). Prima le voci che seguivano quella
 * spostata restavano dov'erano: Backspace a inizio di «b» in a, b, c dava
 * a, c, b; Invio su una voce vuota a metà lista metteva il paragrafo in fondo
 * a tutta la lista; Maiusc+Tab su una voce annidata la spostava dopo le
 * sorelle. Adesso, come in Word e Docs: al primo livello la lista si spezza
 * attorno al blocco e la seconda metà continua la numerazione; da annidata,
 * le sorelle che seguivano diventano figlie della voce che sale.
 */
export function outdentListItem(li, field, removeIfEmpty = false, caretAtStart = false) {
    const parentList = li.parentElement;
    if (!parentList || !/^(OL|UL)$/i.test(parentList.tagName)) return;
    const grandLi = parentList.parentElement;
    const isEmpty = removeIfEmpty && li.textContent.trim() === "";

    if (grandLi && grandLi.tagName === "LI") {
        // G23.fix7 — Enter su LI vuoto annidato: un LI nuovo e pulito al
        // livello sopra (non un <div> dentro la OL, HTML invalido che rompeva
        // la cascata dei marker). Ulteriori Enter risalgono ancora.
        const moving = isEmpty ? document.createElement("li") : li;
        const following = siblingsAfter(li);
        if (following.length) {
            let sub = moving.lastElementChild;
            if (!sub || !/^(OL|UL)$/.test(sub.tagName)) {
                sub = parentList.cloneNode(false);
                sub.removeAttribute("start");
                moving.appendChild(sub);
            }
            for (const n of following) sub.appendChild(n);
        }
        if (isEmpty) li.remove();
        grandLi.after(moving);
        if (!parentList.children.length) parentList.remove();
        // Backspace a inizio voce → caret resta all'INIZIO (caretAtStart);
        // Tab/Shift+Tab a fine del testo della voce, prima delle sue figlie.
        if (isEmpty || caretAtStart) placeCaretAtStart(moving);
        else placeCaretBeforeSublist(moving);
        return;
    }

    // Primo livello: la voce diventa un <div> (vuoto: <div><br></div>, per
    // il caret dopo l'uscita dalla lista).
    const block = document.createElement("div");
    if (isEmpty) block.appendChild(document.createElement("br"));
    else while (li.firstChild) block.appendChild(li.firstChild);
    replaceItemWithBlock(li, block);
    (isEmpty || caretAtStart ? placeCaretAtStart : placeCaretAtEnd)(block);
}

/** Le voci che seguono `li` nella sua lista, in ordine. */
function siblingsAfter(li) {
    const out = [];
    for (let n = li.nextElementSibling; n; n = n.nextElementSibling) out.push(n);
    return out;
}

/** Caret a fine del testo proprio della voce: prima della sua lista annidata,
 *  se ne ha una in coda, altrimenti a fine voce. */
function placeCaretBeforeSublist(li) {
    const last = li.lastElementChild;
    if (!last || !/^(OL|UL)$/.test(last.tagName)) {
        placeCaretAtEnd(li);
        return;
    }
    const range = document.createRange();
    range.setStartBefore(last);
    range.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

/**
 * Toglie `li` da una lista di primo livello e mette `block` al suo posto.
 * Se prima e dopo restano voci, la lista si spezza in due: la seconda metà
 * prende gli stessi attributi (stile, sezione) e, se numerata, continua la
 * numerazione della prima, come l'elenco di un elaboratore di testi
 * interrotto da un paragrafo.
 */
function replaceItemWithBlock(li, block) {
    const list = li.parentElement;
    const before = Array.from(list.children).indexOf(li);
    const following = siblingsAfter(li);
    li.remove();
    if (before === 0) {
        list.before(block);
        if (!list.children.length) list.remove();
        return;
    }
    list.after(block);
    if (!following.length) return;
    const rest = list.cloneNode(false);
    for (const n of following) rest.appendChild(n);
    if (rest.tagName === "OL") setListStart(rest, listStart(list) + before);
    block.after(rest);
}

/** Primo numero di una lista ordinata: l'attributo `start`, 1 se manca. */
export function listStart(list) {
    const n = parseInt(list?.getAttribute?.("start") ?? "", 10);
    return Number.isFinite(n) ? n : 1;
}

/** Imposta il primo numero di una lista ordinata. 1 è il valore predefinito
 *  di HTML, e al posto di `start="1"` si toglie l'attributo. */
export function setListStart(list, n) {
    if (n === 1) list.removeAttribute("start");
    else list.setAttribute("start", String(n));
    redrawListMarkers(list);
}

/**
 * Fa impaginare di nuovo una lista già in pagina, perché i segni si
 * ricalcolino. Nell'editor Chrome non aggiorna i segni fatti con
 * `::marker { content: counter(list-item) … }` — gli stili con la parentesi —
 * quando cambiano `start` o lo stile di una lista già mostrata: misurato il
 * 24/9/2026, dopo start=7 restava «1)» finché la lista non veniva ridisegnata
 * (tests/e2e/editor/moduli/liste-numerazione.spec.js). Nasconderla e
 * rimostrarla nello stesso istante la ricostruisce senza toccare contenuto,
 * caret o attributi.
 */
export function redrawListMarkers(list) {
    if (!list?.isConnected) return;
    const style = list.getAttribute("style");
    list.style.display = "none";
    void list.offsetHeight;
    if (style === null) list.removeAttribute("style");
    else list.setAttribute("style", style);
}

/** Quanti punti ha una lista: le sue voci, non quelle annidate. */
export function itemCount(list) {
    return Array.from(list.children).filter((n) => n.tagName === "LI").length;
}

/** Il numero che segue l'ultimo punto di una lista ordinata. */
export function numberAfter(list) {
    return listStart(list) + itemCount(list);
}

/** Lista (ol/ul) più interna che contiene `node`, restando dentro `field`. */
export function innermostList(field, node) {
    for (let n = node; n && n !== field; n = n.parentNode) {
        if (n.nodeType === Node.ELEMENT_NODE && /^(OL|UL)$/.test(n.tagName)) return n;
    }
    return null;
}

/** Livello di annidamento di una lista nel campo: 0 al primo livello. */
export function listDepth(list, field) {
    let depth = 0;
    for (let n = list.parentNode; n && n !== field; n = n.parentNode) {
        if (n.nodeType === Node.ELEMENT_NODE && /^(OL|UL)$/.test(n.tagName)) depth++;
    }
    return depth;
}

/**
 * L'ultima lista numerata che nel campo viene prima di `ref` (una lista o il
 * punto del caret), allo stesso livello `depth` e senza contenerlo: quella
 * da cui «Continua la numerazione» riprende.
 */
export function previousOrderedList(field, ref, depth) {
    let found = null;
    for (const ol of field.querySelectorAll("ol")) {
        if (ol === ref || ol.contains(ref)) continue;
        if (!(ol.compareDocumentPosition(ref) & Node.DOCUMENT_POSITION_FOLLOWING)) continue;
        if (listDepth(ol, field) === depth) found = ol;
    }
    return found;
}

const ROMAN = /^m{0,3}(cm|cd|d?c{0,3})(xc|xl|l?x{0,3})(ix|iv|v?i{0,3})$/;
const ROMAN_VALUES = { i: 1, v: 5, x: 10, l: 50, c: 100, d: 500, m: 1000 };

function romanValue(t) {
    let n = 0;
    for (let i = 0; i < t.length; i++) {
        const v = ROMAN_VALUES[t[i]];
        const next = ROMAN_VALUES[t[i + 1]] ?? 0;
        n += v < next ? -v : v;
    }
    return n;
}

/**
 * Il primo numero di una lista, dal segno che scrive il docente. `code` è il
 * segno del livello come nelle tabelle `_OL_LEVELS` di checkin-handlers:
 * "D" e "0D" numeri, "la"/"UA" lettere, "lr"/"UR" numeri romani, con o senza
 * il suffisso. Un numero vale per ogni stile (3 = c = iii); punto o parentesi
 * finali si ignorano. Restituisce null se il segno non vale per quello stile.
 *
 * Le lettere si fermano alla z: nel PDF `\alph` oltre la 26ª ferma la
 * compilazione con «Counter too large» (misurato con enumitem il 24/9/2026).
 */
export function parseListStart(text, code) {
    const t = String(text ?? "").trim().replace(/[.)]$/, "").trim().toLowerCase();
    const core = String(code || "D").replace(/[.)]$/, "");
    const letters = core === "la" || core === "UA";
    const roman = core === "lr" || core === "UR";
    let n = null;
    if (/^\d+$/.test(t)) n = parseInt(t, 10);
    else if (letters && /^[a-z]$/.test(t)) n = t.charCodeAt(0) - 96;
    else if (roman && t !== "" && ROMAN.test(t)) n = romanValue(t);
    const max = letters ? 26 : 999;
    return n !== null && n >= 1 && n <= max ? n : null;
}

/**
 * Vero se una lista col segno `code`, che parte da `start` e ha `items`
 * punti, si scrive tutta: con le lettere l'ultimo punto non va oltre la z
 * (lo stesso limite del PDF di parseListStart). Numeri e romani non hanno
 * un limite che conti qui.
 */
export function listFits(code, start, items) {
    const core = String(code || "D").replace(/[.)]$/, "");
    return !(core === "la" || core === "UA") || start + Math.max(items, 1) - 1 <= 26;
}

/** Crea una `<ol>`/`<ul>` vuota con N `<li>` empty pre-popolati, classe
 *  `fm-dsa-li-list` + data-dsa-section + opzionale type/list-style. */
export function makeEmptyList(tag, typeAttr, section, emptyLiCount, listStyle) {
    const list = document.createElement(tag);
    list.className = "fm-dsa-li-list";
    list.setAttribute("data-dsa-section", section);
    if (typeAttr) list.setAttribute("type", typeAttr);
    if (listStyle) list.setAttribute("data-fm-list-style", listStyle);
    for (let i = 0; i < emptyLiCount; i++) list.appendChild(document.createElement("li"));
    return list;
}

/**
 * Trova l'ancestor "block" del nodo (figlio diretto del field).
 * Block = <div>/<p>/<li> top-level. Se il nodo è direttamente nel field
 * senza wrapper, ritorna null (no block container).
 */
export function getEnclosingBlock(node, field) {
    let cur = node;
    while (cur && cur !== field) {
        if (cur.parentNode === field && cur.nodeType === Node.ELEMENT_NODE) {
            const tag = cur.tagName;
            if (tag === "DIV" || tag === "P" || tag === "LI") return cur;
        }
        cur = cur.parentNode;
    }
    return null;
}

/**
 * Splitta un DocumentFragment in array di linee HTML usando come separatore:
 * <br>, </div>, </p>. Ogni linea viene trimmata di spazi/&nbsp; finali.
 */
export function fragmentToLines(fragment) {
    const tmp = document.createElement("div");
    tmp.appendChild(fragment);
    // Normalizza: <div>X</div> → X<br>
    tmp.querySelectorAll("div, p").forEach((b) => {
        const br = document.createElement("br");
        b.before(...b.childNodes);
        b.replaceWith(br);
    });
    const html = tmp.innerHTML;
    return html.split(/<br\s*\/?>/i)
        .map((s) => s.replace(/^\s+|\s+$|^&nbsp;|&nbsp;$/g, ""))
        .filter((s) => s !== "");
}

/**
 * Inserisce HTML alla caret position di un editor field. Supporta:
 *   - <textarea>: string-slicing tradizionale
 *   - <div contenteditable>: Range API + sposta caret dentro il primo
 *     <li> vuoto se presente (UX: l'utente inizia a scrivere subito)
 */
export function insertHtmlAtCaret(field, html) {
    if (field.tagName === "TEXTAREA") {
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? start;
        field.value = field.value.slice(0, start) + html + field.value.slice(end);
        const liIdx = html.indexOf("<li></li>");
        const caret = liIdx >= 0 ? start + liIdx + 4 : start + html.length;
        field.setSelectionRange(caret, caret);
        return;
    }
    // contenteditable
    field.focus();
    const sel = window.getSelection();
    if (!sel.rangeCount) {
        // No range: append at end
        const tmp = document.createElement("div");
        tmp.innerHTML = html;
        while (tmp.firstChild) field.appendChild(tmp.firstChild);
        return;
    }
    const range = sel.getRangeAt(0);
    if (!field.contains(range.startContainer)) {
        // Range fuori dal field: append at end
        const tmp = document.createElement("div");
        tmp.innerHTML = html;
        while (tmp.firstChild) field.appendChild(tmp.firstChild);
        return;
    }
    range.deleteContents();
    const frag = document.createRange().createContextualFragment(html);
    // Capture il primo <li> vuoto per posizionare caret dopo insert
    const firstEmptyLi = frag.querySelector("li:empty");
    range.insertNode(frag);
    if (firstEmptyLi) {
        const r2 = document.createRange();
        r2.setStart(firstEmptyLi, 0);
        r2.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r2);
    }
}
