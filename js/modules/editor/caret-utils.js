/**
 * G24.refactor5.step3 — Estratto da `features/checkin-handlers.js` (monolite
 * 9000+ LOC). Selection / caret utilities per contenteditable.
 *
 * Tutte funzioni pure: usano solo DOM APIs standard (Range, Selection),
 * nessuna dipendenza file-state. Riusabili da qualunque editor inline.
 *
 * Convenzioni:
 *   - "offset" = posizione carattere in plain-text (NON DOM offset).
 *     Calcolato camminando i text node con `range.toString().length`.
 *   - `start`/`end` su un selection sono offset in plain-text dell'intero
 *     contenuto del root.
 */

/** Restituisce l'offset plain-text del caret rispetto a `root`.
 *  @param {Element} root — boundary del campo contenteditable
 *  @param {"start"|"end"} which — quale endpoint della selection riportare
 *  @returns {number} offset (0 se selection non in root) */
export function ceCaretOffset(root, which) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) return 0;
    const range = sel.getRangeAt(0);
    if (!root.contains(range.startContainer)) return 0;
    const r = document.createRange();
    r.selectNodeContents(root);
    if (which === "end") r.setEnd(range.endContainer, range.endOffset);
    else r.setEnd(range.startContainer, range.startOffset);
    return r.toString().length;
}

/** Imposta la selection nel `root` da `start` a `end` (offset plain-text).
 *  Walk dei text node + range start/end al primo nodo che contiene l'offset.
 *  Fallback: end del root se offset > length totale. */
export function ceSetCaret(root, start, end) {
    const range = document.createRange();
    let pos = 0, startSet = false, endSet = false;
    function walk(node) {
        if (startSet && endSet) return;
        if (node.nodeType === Node.TEXT_NODE) {
            const len = node.textContent.length;
            if (!startSet && pos + len >= start) {
                range.setStart(node, Math.max(0, start - pos));
                startSet = true;
            }
            if (!endSet && pos + len >= end) {
                range.setEnd(node, Math.max(0, end - pos));
                endSet = true;
            }
            pos += len;
        } else {
            for (const c of node.childNodes) walk(c);
        }
    }
    walk(root);
    if (!startSet) range.setStart(root, root.childNodes.length);
    if (!endSet) range.setEnd(root, root.childNodes.length);
    const sel = window.getSelection();
    if (sel) {
        sel.removeAllRanges();
        sel.addRange(range);
    }
}

/** Variante di ceSetCaret con scroll-into-view best-effort (find/replace).
 *  Differenza: solo applica range se BOTH endpoints sono stati trovati
 *  (no fallback al root.end), e scroll-into-view sul parentElement. */
export function ceSelectRange(field, start, end) {
    const range = document.createRange();
    let pos = 0, startSet = false, endSet = false;
    function walk(node) {
        if (startSet && endSet) return;
        if (node.nodeType === Node.TEXT_NODE) {
            const len = node.textContent.length;
            if (!startSet && pos + len >= start) {
                range.setStart(node, Math.max(0, start - pos));
                startSet = true;
            }
            if (!endSet && pos + len >= end) {
                range.setEnd(node, Math.max(0, end - pos));
                endSet = true;
            }
            pos += len;
        } else {
            for (const c of node.childNodes) walk(c);
        }
    }
    walk(field);
    if (startSet && endSet) {
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
        // Scroll into view best-effort
        if (range.startContainer.parentElement?.scrollIntoView) {
            range.startContainer.parentElement.scrollIntoView({ block: "nearest" });
        }
    }
}

/** Predicate: caret è all'inizio di `node` (no text precedente nel range)? */
export function caretAtNodeStart(range, node) {
    const ref = document.createRange();
    ref.setStart(node, 0);
    ref.setEnd(range.startContainer, range.startOffset);
    return ref.toString().length === 0;
}

/** Predicate: caret è alla fine di `node` (no text successivo nel range)? */
export function caretAtNodeEnd(range, node) {
    const ref = document.createRange();
    ref.setStart(range.startContainer, range.startOffset);
    ref.setEndAfter(node);
    return ref.toString().length === 0;
}

/** Posiziona caret collapsed all'inizio di `el`. */
export function placeCaretAtStart(el) {
    const range = document.createRange();
    range.setStart(el, 0);
    range.collapse(true);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

/** Posiziona caret collapsed alla fine di `el` (selectNodeContents + collapse). */
export function placeCaretAtEnd(el) {
    const range = document.createRange();
    range.selectNodeContents(el);
    range.collapse(false);
    const sel = window.getSelection();
    sel.removeAllRanges();
    sel.addRange(range);
}

/** Posiziona caret alla fine del primo `<li>` della lista. No-op se vuota. */
export function placeCaretInFirstLi(list) {
    const li = list.querySelector("li");
    if (!li) return;
    placeCaretAtEnd(li);
}

/** Walk text nodes (attraversa anche `<mark>` esistenti) e set range.start/end
 *  ai textContent offset specificati. Throws "range_not_found" se gli offset
 *  superano la length totale. Originariamente in find-replace-dialog,
 *  spostato qui (caret-utils) come pure DOM range util — usato anche da
 *  inline-format. Estratto per consentire dynamic import del dialog. */
export function setRangeAtOffsets(root, start, end, range) {
    let pos = 0, startSet = false, endSet = false;
    function walk(node) {
        if (startSet && endSet) return;
        if (node.nodeType === Node.TEXT_NODE) {
            const len = node.textContent.length;
            if (!startSet && pos + len >= start) {
                range.setStart(node, Math.max(0, start - pos));
                startSet = true;
            }
            if (!endSet && pos + len >= end) {
                range.setEnd(node, Math.max(0, end - pos));
                endSet = true;
            }
            pos += len;
        } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName !== "MARK") {
            for (const c of node.childNodes) walk(c);
        } else if (node.nodeType === Node.ELEMENT_NODE && node.tagName === "MARK") {
            // attraversa anche le mark esistenti
            for (const c of node.childNodes) walk(c);
        }
    }
    walk(root);
    if (!startSet || !endSet) throw new Error("range_not_found");
}
