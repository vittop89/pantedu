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
 *     diventa block <div> sibling della list
 *   - removeIfEmpty=true e li vuoto → rimuove il li (caso Enter su li vuoto:
 *     vogliamo "exit list" senza creare un vuoto in più)
 */
export function outdentListItem(li, field, removeIfEmpty = false, caretAtStart = false) {
    const parentList = li.parentElement;
    if (!parentList || !/^(OL|UL)$/i.test(parentList.tagName)) return;
    const grandparent = parentList.parentNode;
    const grandLi = parentList.parentElement;

    // I li SUCCESSIVI al nostro nella stessa nested list devono restare
    // dentro la nested list (Word/Docs: outdent sposta solo il corrente).
    const followingLis = [];
    let next = li.nextElementSibling;
    while (next) {
        const after = next.nextElementSibling;
        followingLis.push(next);
        next = after;
    }
    // Se ci sono followingLis, dobbiamo "splittare": creo nuova nested list
    // post-split e ci metto i followingLis. Per semplicità in v1, lasciamo
    // i followingLis al loro posto (ovviamente la list parent ora avrà
    // 0+ <li> rimasti).

    // Rimuovi il li dalla parent list
    li.remove();

    if (removeIfEmpty && li.textContent.trim() === "") {
        // G23.fix7 — Enter su LI vuoto.
        //   - NESTED (parent list dentro un LI): outdent UN livello → crea
        //     nuovo LI vuoto come sibling di grandLi. Marker corretto via
        //     CSS list-style-type cascade. Ulteriori Enter outdent ancora.
        //   - TOP-LEVEL (parent list direct child del field): exit list →
        //     crea <div><br></div> sibling di parentList.
        //   Bug pre-fix7: SEMPRE creava <div> dentro OL (HTML invalido),
        //   distruggendo la cascade dei marker (CSS selettore `>li>ol`
        //   non matchava più → markers cambiavano livello).
        if (grandLi && grandLi.tagName === "LI") {
            // Nested case: insert new LI as sibling of grandLi
            const grandList = grandLi.parentElement;
            const newLi = document.createElement("li");
            if (grandList) {
                grandList.insertBefore(newLi, grandLi.nextSibling);
            }
            if (!parentList.children.length) parentList.remove();
            placeCaretAtStart(newLi);
        } else {
            // Top-level case: exit list with <div><br></div>
            const newBlock = document.createElement("div");
            newBlock.appendChild(document.createElement("br"));
            if (parentList.parentNode) {
                parentList.parentNode.insertBefore(newBlock, parentList.nextSibling);
            }
            if (!parentList.children.length) parentList.remove();
            placeCaretAtStart(newBlock);
        }
        return;
    }

    // Se nested DENTRO un <li>: outdent = inserisci li come sibling DOPO il nonno-li
    if (grandLi && grandLi.tagName === "LI") {
        const grandList = grandLi.parentElement;
        // Inserisci li come sibling del grandLi, DOPO di esso
        grandList.insertBefore(li, grandLi.nextSibling);
        // Cleanup: se parentList è ora vuoto, rimuovilo
        if (!parentList.children.length) parentList.remove();
    } else {
        // List top-level: outdent = converti li in <div> e mettilo sibling
        const newDiv = document.createElement("div");
        while (li.firstChild) newDiv.appendChild(li.firstChild);
        if (grandparent) grandparent.insertBefore(newDiv, parentList.nextSibling);
        if (!parentList.children.length) parentList.remove();
        (caretAtStart ? placeCaretAtStart : placeCaretAtEnd)(newDiv);
        return;
    }
    // Backspace a inizio voce → caret resta all'INIZIO (caretAtStart); Tab/Shift+Tab
    // mantiene il caret a fine voce (comportamento storico).
    (caretAtStart ? placeCaretAtStart : placeCaretAtEnd)(li);
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
