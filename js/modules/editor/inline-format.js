/**
 * G24.refactor5.step6 — Estratto da `features/checkin-handlers.js` (monolite
 * 8100+ LOC). Inline format toggle (B/I/U/...) + wrap snippet + insert
 * link/inline-box per editor contenteditable.
 *
 * Funzioni esportate (logica formatting cohesive group):
 *   - toggleInlineFormat(panel, tag): smart toggle (split / unwrap / re-wrap)
 *   - wrapSnippet(panel, prefix, suffix): wrap selezione, distingue HTML vs text
 *   - wrapAsElement(field, prefix, suffix, sel): parse prefix come elemento
 *   - insertEditableInlineBox(panel, cls, placeholder): inserisce span editabile
 *   - insertLinkDialog(panel): dialog URL + testo → <a href> reale
 *   - normalizeInlineBlockNesting(root): inverte <inline><block> in <block><inline>
 *   - captureSelectionAsTextOffsets(root): selection → {start,end} offset
 *   - expandRangeToInlineAncestors(range, fieldBoundary): copy support
 *   - handleInlineBoxExit(field, key): ArrowLeft/Right boundary span exit
 *
 * Dipendenze:
 *   - undo-manager: UndoManager.save() pre-mutazione DOM
 *   - find-replace-dialog: setRangeAtOffsets (riusato per restore selezione)
 *   - caret-utils: caretAtNodeStart/caretAtNodeEnd
 */

import { UndoManager } from "./undo-manager.js";
import {
    caretAtNodeStart, caretAtNodeEnd, setRangeAtOffsets,
} from "./caret-utils.js";

/** Toast fallback locale (FM.ToastManager) per insertLinkDialog. */
function localToast(msg, kind = "warn") {
    const tm = (typeof window !== "undefined") ? window.FM?.ToastManager : null;
    if (tm?.show) {
        const map = { ok: ["success", "OK"], warn: ["warning", "Attenzione"], err: ["error", "Errore"], info: ["info", "Info"] };
        const [type, title] = map[kind] || ["info", "Info"];
        try { tm.show(type, title, String(msg)); } catch { /* ignore */ }
    }
}

/** Espande in-place le boundary di `range` per includere gli ancestor
 *  inline (b/i/u/...) interamente coperti dalla selezione. Usato in copy
 *  così "ccc" selezionato dentro <u>ccc</u> esporta <u>ccc</u>, non solo
 *  "ccc" plain. */
export function expandRangeToInlineAncestors(range, fieldBoundary) {
    const INLINE_TAGS = /^(b|strong|i|em|u|s|sub|sup|a|span)$/i;
    let changed = true;
    while (changed) {
        changed = false;
        // START: se in text node a offset 0 (inizio), e parent è inline tag
        // di cui il text node è il primo child → sposta start prima del parent.
        const sc = range.startContainer;
        const so = range.startOffset;
        if (sc.nodeType === 3 && so === 0) {
            const parent = sc.parentNode;
            if (parent && parent !== fieldBoundary
                && INLINE_TAGS.test(parent.tagName)
                && parent.firstChild === sc) {
                range.setStartBefore(parent);
                changed = true;
            }
        }
        // END: se in text node a offset === length (fine), e parent è inline
        // di cui il text node è l'ultimo child → sposta end dopo il parent.
        const ec = range.endContainer;
        const eo = range.endOffset;
        if (ec.nodeType === 3 && eo === ec.length) {
            const parent = ec.parentNode;
            if (parent && parent !== fieldBoundary
                && INLINE_TAGS.test(parent.tagName)
                && parent.lastChild === ec) {
                range.setEndAfter(parent);
                changed = true;
            }
        }
    }
}

/** ArrowLeft/Right boundary span exit per inline editable box (dots/AddTextDSA).
 *  Ritorna true se ha gestito (caller fa preventDefault). */
export function handleInlineBoxExit(field, key) {
    const sel = window.getSelection();
    if (!sel || !sel.rangeCount) return false;
    const range = sel.getRangeAt(0);
    if (!range.collapsed) return false;
    if (!field.contains(range.startContainer)) return false;

    // Trova span ancestor con class dots o AddTextDSA dentro il field
    let span = range.startContainer;
    if (span.nodeType === Node.TEXT_NODE) span = span.parentNode;
    while (span && span !== field) {
        if (span.nodeType === Node.ELEMENT_NODE
            && span.tagName === "SPAN"
            && (span.classList?.contains("dots") || span.classList?.contains("AddTextDSA"))) {
            break;
        }
        span = span.parentNode;
    }
    if (!span || span === field) return false;

    // Check se caret è al boundary dello span
    const atStart = caretAtNodeStart(range, span);
    const atEnd   = caretAtNodeEnd(range, span);

    if (key === "ArrowRight" && atEnd) {
        const parent = span.parentNode;
        let after = span.nextSibling;
        if (!after || after.nodeType !== Node.TEXT_NODE) {
            // Crea sentinel text node con uno spazio per dare caret position
            after = document.createTextNode(" ");
            parent.insertBefore(after, span.nextSibling);
        }
        const r = document.createRange();
        r.setStart(after, after.nodeType === Node.TEXT_NODE ? Math.min(1, after.length) : 0);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        return true;
    }
    if (key === "ArrowLeft" && atStart) {
        const parent = span.parentNode;
        let before = span.previousSibling;
        if (!before || before.nodeType !== Node.TEXT_NODE) {
            before = document.createTextNode(" ");
            parent.insertBefore(before, span);
        }
        const r = document.createRange();
        const off = before.nodeType === Node.TEXT_NODE ? Math.max(0, before.length - 1) : 0;
        r.setStart(before, off);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        return true;
    }
    return false;
}

/** Inverte pattern HTML invalido `<inline><block>X</block></inline>` (es.
 *  `<i><div>...</div></i>`) in `<block><inline>X</inline></block>`. Block
 *  dentro inline è semanticamente errato e causa split visivi multi-riga
 *  durante toggle. Ritorna true se ha cambiato qualcosa. */
export function normalizeInlineBlockNesting(root) {
    let changed = false;
    let didChange = true;
    while (didChange) {
        didChange = false;
        const inlines = root.querySelectorAll("b, strong, i, em, u, s, sub, sup, a, span");
        for (const inl of inlines) {
            // Solo se inline contiene un singolo block child (div/p) e niente altro
            // di "sostanziale" (text non-whitespace o altri elementi).
            let blockChild = null;
            let hasOther = false;
            for (const c of inl.childNodes) {
                if (c.nodeType === 3) {
                    if (c.textContent.trim()) { hasOther = true; break; }
                    continue;
                }
                if (c.nodeType === 1) {
                    const t = c.tagName.toLowerCase();
                    if (t === "div" || t === "p") {
                        if (blockChild) { hasOther = true; break; }
                        blockChild = c;
                    } else {
                        hasOther = true; break;
                    }
                }
            }
            if (!blockChild || hasOther) continue;
            // Inverti: <inline><block>X</block></inline> → <block><inline>X</inline></block>
            const newInline = document.createElement(inl.tagName);
            // Copia attributi
            for (const attr of Array.from(inl.attributes)) {
                newInline.setAttribute(attr.name, attr.value);
            }
            while (blockChild.firstChild) newInline.appendChild(blockChild.firstChild);
            blockChild.appendChild(newInline);
            inl.parentNode.insertBefore(blockChild, inl);
            inl.parentNode.removeChild(inl);
            changed = true;
            didChange = true;
            break; // re-query
        }
    }
    return changed;
}

/** Cattura selezione corrente come {start, end} offset in textContent del root.
 *  Robusto rispetto a modifiche DOM purché il TESTO resti invariato. */
export function captureSelectionAsTextOffsets(root) {
    const sel = window.getSelection();
    if (!sel.rangeCount) return null;
    const range = sel.getRangeAt(0);
    if (!root.contains(range.startContainer)) return null;
    const computeOffset = (container, offset) => {
        if (container === root) {
            let off = 0;
            for (let i = 0; i < offset && i < root.childNodes.length; i++) {
                off += root.childNodes[i].textContent.length;
            }
            return off;
        }
        // Walk text nodes pre-order, accumulando lunghezze fino al container
        let off = 0;
        const stack = [root];
        while (stack.length) {
            const n = stack.shift();
            if (n === container) {
                if (container.nodeType === 3) return off + offset;
                // Element container con offset = indice child
                for (let i = 0; i < offset && i < n.childNodes.length; i++) {
                    off += n.childNodes[i].textContent.length;
                }
                return off;
            }
            if (n.nodeType === 3) { off += n.textContent.length; continue; }
            // Element: push children in ordine inverso (per shift FIFO)
            for (let i = n.childNodes.length - 1; i >= 0; i--) {
                stack.unshift(n.childNodes[i]);
            }
        }
        return off;
    };
    return {
        start: computeOffset(range.startContainer, range.startOffset),
        end: computeOffset(range.endContainer, range.endOffset),
    };
}

/**
 * Toggle inline format (`<b>` / `<i>` / `<u>` ecc) sulla selezione:
 *  - Se la selezione (o il caret) è dentro un elemento con `tag`, lo
 *    "spegne" rimuovendo il wrapper (split se necessario quando la
 *    selezione è parziale).
 *  - Altrimenti applica il wrap via wrapSnippet (HTML element).
 *
 * Aliases gestiti: b↔strong, i↔em (legacy execCommand output).
 */
export function toggleInlineFormat(panel, tag) {
    const ta = panel?._focusedTextarea
        || panel?.querySelector?.(".fm-editor-field")
        || (typeof window !== "undefined" ? window.__fmFocusedTA : null);
    if (!ta || ta.tagName === "TEXTAREA") {
        wrapSnippet(panel, `<${tag}>`, `</${tag}>`);
        return;
    }
    const sel = window.getSelection();
    if (!sel.rangeCount || !ta.contains(sel.anchorNode)) {
        wrapSnippet(panel, `<${tag}>`, `</${tag}>`);
        return;
    }
    // Normalize struttura HTML invalida `<inline><block>X</block></inline>`:
    // <div> dentro <i> è semanticamente errato e durante split crea 3 righe block.
    // Inverto in `<block><inline>X</inline></block>` PRIMA del toggle.
    // Cattura selezione come text-offset per ripristinarla post-normalize.
    const capturedSel = captureSelectionAsTextOffsets(ta);
    if (normalizeInlineBlockNesting(ta) && capturedSel) {
        const r = document.createRange();
        setRangeAtOffsets(ta, capturedSel.start, capturedSel.end, r);
        sel.removeAllRanges();
        sel.addRange(r);
    }
    const aliases = { b: ["b", "strong"], i: ["i", "em"], u: ["u"] };
    const tagSet = aliases[tag] || [tag];

    // Trova ancestor element con uno dei tag-target. CRITICAL: due scenari:
    //   (a) Ancestor AVVOLGE la selezione (es. <b><i>X</i></b>, selezione "X")
    //       → split classico
    //   (b) Selezione AVVOLGE l'ancestor (es. range = <i><u>X</u></i>, <u> dentro)
    //       → unwrap intero (la selezione contiene tutto l'ancestor)
    // Partiamo da un text node DENTRO la selezione, non da commonAncestorContainer.
    const range = sel.getRangeAt(0);
    let ancestor = null;
    let ancestorMode = "wraps"; // "wraps" = caso (a), "wrapped" = caso (b)
    let n = range.startContainer;
    if (n.nodeType === 1) {
        let scout = n.childNodes[range.startOffset] || n.firstChild;
        while (scout && scout.nodeType !== 3) {
            scout = scout.firstChild;
        }
        if (scout) n = scout;
    }
    if (n.nodeType === 3) n = n.parentNode;
    while (n && n !== ta) {
        if (n.nodeType === 1 && tagSet.includes(n.tagName.toLowerCase())) {
            try {
                const ar = document.createRange();
                ar.selectNodeContents(n);
                const startCmp = ar.compareBoundaryPoints(Range.START_TO_START, range);
                const endCmp = ar.compareBoundaryPoints(Range.END_TO_END, range);
                // (a) n.content >= range.start && n.content <= range.end? NO opposite:
                // n WRAPPA range: ar.start <= range.start && ar.end >= range.end
                const wraps = startCmp <= 0 && endCmp >= 0;
                // range WRAPPA n: ar.start >= range.start && ar.end <= range.end
                const wrapped = startCmp >= 0 && endCmp <= 0;
                if (wraps) { ancestor = n; ancestorMode = "wraps"; break; }
                if (wrapped) { ancestor = n; ancestorMode = "wrapped"; break; }
            } catch (e) {
                ancestor = n;
                ancestorMode = "wraps";
                break;
            }
        }
        n = n.parentNode;
    }
    if (!ancestor) {
        wrapSnippet(panel, `<${tag}>`, `</${tag}>`);
        return;
    }

    UndoManager?.save?.(ta);

    // CASO (b): selezione AVVOLGE l'ancestor → unwrap intero senza split.
    // Inoltre cerco TUTTI gli inline tag dello stesso tipo dentro la selezione
    // (chain post-toggle può lasciare multipli da split precedenti).
    if (ancestorMode === "wrapped") {
        UndoManager?.save?.(ta);
        // Trova tutti i tag candidate dentro la selezione
        const candidates = [];
        const walker = document.createTreeWalker(ta, NodeFilter.SHOW_ELEMENT);
        let cur = walker.nextNode();
        while (cur) {
            if (tagSet.includes(cur.tagName.toLowerCase())) {
                const cr = document.createRange();
                cr.selectNodeContents(cur);
                const sCmp = cr.compareBoundaryPoints(Range.START_TO_START, range);
                const eCmp = cr.compareBoundaryPoints(Range.END_TO_END, range);
                // n è interamente dentro range
                if (sCmp >= 0 && eCmp <= 0) candidates.push(cur);
            }
            cur = walker.nextNode();
        }
        // Tracciamo i primi e ultimi text leaves per restore selezione
        const allTextLeaves = [];
        for (const el of candidates) {
            for (const c of el.childNodes) allTextLeaves.push(c);
        }
        // Unwrap tutti
        for (const el of candidates) {
            const parent = el.parentNode;
            while (el.firstChild) parent.insertBefore(el.firstChild, el);
            parent.removeChild(el);
        }
        // Restore selezione approssimativa: copri i moved children
        if (allTextLeaves.length) {
            try {
                const r = document.createRange();
                r.setStartBefore(allTextLeaves[0]);
                r.setEndAfter(allTextLeaves[allTextLeaves.length - 1]);
                sel.removeAllRanges();
                sel.addRange(r);
            } catch (e) { /* ignore */ }
        }
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }

    if (range.collapsed) {
        // SEMANTICA Word/Docs: caret dentro <b>aaa|bbb</b> + click B
        //   → "aaa" RESTA bold, carattere successivo NON sarà bold.
        // Implementazione: splitta il <tag> al caret, inserisce anchor ZWS
        // FUORI dal tag dove posiziono il caret (così typing va fuori).
        const parent = ancestor.parentNode;
        const startNode = range.startContainer;
        const startOffset = range.startOffset;

        // Estrai tail = contenuto dal caret alla fine dell'ancestor
        const tailRange = document.createRange();
        try {
            tailRange.setStart(startNode, startOffset);
            tailRange.setEnd(ancestor, ancestor.childNodes.length);
        } catch (e) {
            // Caret fuori dall'ancestor: fallback, mette caret dopo
            const anchor = document.createTextNode("​");
            parent.insertBefore(anchor, ancestor.nextSibling);
            const r = document.createRange();
            r.setStart(anchor, 1); r.collapse(true);
            sel.removeAllRanges(); sel.addRange(r);
            ta.dispatchEvent(new Event("input", { bubbles: true }));
            return;
        }
        const tailFrag = tailRange.extractContents();

        // Se ancestor svuotato (caret era all'inizio), rimuovilo
        const insertRef = ancestor.nextSibling;
        let removedAncestor = false;
        if (!ancestor.textContent && !ancestor.querySelector("*")) {
            parent.removeChild(ancestor);
            removedAncestor = true;
        }

        // Anchor ZWS FUORI dal tag, dove finirà il caret (typing andrà qui)
        const anchor = document.createTextNode("​");
        parent.insertBefore(anchor, insertRef);

        // Re-wrap tail in nuovo <tag> dopo l'anchor (preserva "bbb" bold)
        const tailHasContent = tailFrag.textContent || tailFrag.querySelector("*");
        if (tailHasContent) {
            const tailEl = document.createElement(ancestor.tagName);
            tailEl.appendChild(tailFrag);
            parent.insertBefore(tailEl, anchor.nextSibling);
        }

        const r = document.createRange();
        r.setStart(anchor, 1);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }

    // SELEZIONE non-collapsed dentro <tag>: split in [before <tag>] [middle plain] [after <tag>]
    // Preserva selezione sul middle per chain di toggle/format.
    const parent = ancestor.parentNode;
    const beforeRange = document.createRange();
    beforeRange.setStart(ancestor, 0);
    beforeRange.setEnd(range.startContainer, range.startOffset);
    const afterRange = document.createRange();
    afterRange.setStart(range.endContainer, range.endOffset);
    afterRange.setEnd(ancestor, ancestor.childNodes.length);

    const beforeFrag = beforeRange.cloneContents();
    const middleFrag = range.cloneContents();
    const afterFrag = afterRange.cloneContents();

    const makeWrapped = (frag) => {
        if (!frag.textContent && !frag.querySelector?.("*")) return null;
        const w = document.createElement(ancestor.tagName);
        w.appendChild(frag);
        return w;
    };
    const beforeEl = makeWrapped(beforeFrag);
    const afterEl = makeWrapped(afterFrag);

    // CRITICAL: identifica gli inline wrappers ANNIDATI tra il text leaf
    // e l'ancestor target. Senza ri-wrap del middle con essi, perdiamo
    // <i>/<u> per selezione interna ad un singolo text node tutto wrappato.
    // Es: <b><i><u>aaaaaa</u></i></b> + toggle B su "aa" middle:
    //   middleFrag = text "aa" plain → BAD (perde i, u)
    //   Fix: re-wrap con [u, i] preservandoli.
    const INLINE_TAGS_RE = /^(b|strong|i|em|u|s|sub|sup)$/i;
    const innerWrappers = []; // dal più interno (closest text) al più esterno (closest ancestor target, escluso)
    {
        let n2 = range.commonAncestorContainer;
        if (n2.nodeType === 3) n2 = n2.parentNode;
        while (n2 && n2 !== ancestor && n2 !== ta) {
            if (n2.nodeType === 1 && INLINE_TAGS_RE.test(n2.tagName)) {
                innerWrappers.push(n2.tagName.toLowerCase());
            }
            n2 = n2.parentNode;
        }
    }
    // Re-wrap middleFrag con innerWrappers (dal più esterno al più interno costruendo la catena)
    let middleRoot = null; // top-most wrapper o middleFrag stesso
    let middleInnermost = null;
    for (let i = innerWrappers.length - 1; i >= 0; i--) {
        const w = document.createElement(innerWrappers[i]);
        if (!middleRoot) middleRoot = w;
        if (middleInnermost) middleInnermost.appendChild(w);
        middleInnermost = w;
    }
    if (middleInnermost) middleInnermost.appendChild(middleFrag);

    if (beforeEl) parent.insertBefore(beforeEl, ancestor);
    // Track middle nodes per restorare la selezione DOPO l'insert
    const middleNodes = [];
    if (middleRoot) {
        middleNodes.push(middleRoot);
        parent.insertBefore(middleRoot, ancestor);
    } else {
        while (middleFrag.firstChild) {
            const node = middleFrag.firstChild;
            middleNodes.push(node);
            parent.insertBefore(node, ancestor);
        }
    }
    if (afterEl) parent.insertBefore(afterEl, ancestor);
    parent.removeChild(ancestor);

    // Restore selezione esattamente sui middle nodes (NO normalize: distruggerebbe i bounds)
    if (middleNodes.length) {
        try {
            const r = document.createRange();
            r.setStartBefore(middleNodes[0]);
            r.setEndAfter(middleNodes[middleNodes.length - 1]);
            sel.removeAllRanges();
            sel.addRange(r);
        } catch (e) { /* ignore */ }
    }
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

/** Wrappa la selezione corrente con prefix/suffix.
 *  - Textarea: string-slicing
 *  - Contenteditable HTML wrap: parse a element via `wrapAsElement`
 *  - Contenteditable text wrap (LaTeX): insert text node */
export function wrapSnippet(panel, prefix, suffix) {
    const ta = panel._focusedTextarea || panel.querySelector(".fm-editor-field");
    if (!ta) return;
    if (ta.tagName === "TEXTAREA") {
        const start = ta.selectionStart ?? ta.value.length;
        const end   = ta.selectionEnd   ?? ta.value.length;
        const sel   = ta.value.slice(start, end);
        const replacement = prefix + sel + suffix;
        ta.value = ta.value.slice(0, start) + replacement + ta.value.slice(end);
        ta.focus();
        const cursor = sel ? (start + replacement.length) : (start + prefix.length);
        ta.setSelectionRange(cursor, cursor);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }
    // contenteditable: discrimina prefix/suffix tag HTML vs LaTeX text.
    // Se prefix è del tipo `<tag...>` e suffix è `</tag>`, inseriamo un
    // ELEMENT (parsato come HTML) — l'utente vede rendering, non testo.
    // Per LaTeX (`\textbf{`, `\(`, ecc.) inseriamo come text node.
    ta.focus();
    UndoManager?.save?.(ta);
    const sel = window.getSelection();
    const isHtmlWrap = /^<\w[^>]*>$/i.test(prefix) && /^<\/\w+>$/i.test(suffix);

    if (isHtmlWrap) {
        return wrapAsElement(ta, prefix, suffix, sel);
    }

    if (!sel.rangeCount || !ta.contains(sel.anchorNode)) {
        const txtNode = document.createTextNode(prefix + suffix);
        ta.appendChild(txtNode);
        const r = document.createRange();
        r.setStart(txtNode, prefix.length);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }
    const range = sel.getRangeAt(0);
    const selectedText = range.toString();
    range.deleteContents();
    range.insertNode(document.createTextNode(prefix + selectedText + suffix));
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

/**
 * Wrap selezione/caret con elemento HTML reale (es. <span class="dots">).
 * Risultato: il browser RENDE l'elemento (CSS .dots applicato), NON appare
 * come tag-text letterale. Preserva inline format e roundtrip via
 * _buildBlocksFromTextarea (innerHTML).
 */
export function wrapAsElement(field, prefix, suffix, sel) {
    // Parse prefix → element template (tag + attrs)
    const tmp = document.createElement("div");
    tmp.innerHTML = prefix + suffix;
    const elTemplate = tmp.firstElementChild;
    if (!elTemplate) {
        // Fallback text
        field.appendChild(document.createTextNode(prefix + suffix));
        return;
    }
    const newEl = elTemplate.cloneNode(false); // clone tag+attrs, no inner

    if (!sel.rangeCount || !field.contains(sel.anchorNode)) {
        // No selection: append vuoto, caret dentro
        field.appendChild(newEl);
        const r = document.createRange();
        r.setStart(newEl, 0);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
        field.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }
    const range = sel.getRangeAt(0);
    if (range.collapsed) {
        // Inserisci elemento con ZWS placeholder per garantire che il caret
        // resti DENTRO l'inline element. Senza, contenteditable collassa
        // span vuoti e il primo carattere digitato finisce FUORI dal wrap.
        // ZWS verrà strippato a save time (_buildBlocksFromTextarea).
        const zws = document.createTextNode("​");
        newEl.appendChild(zws);
        range.insertNode(newEl);
        const r = document.createRange();
        r.setStart(zws, 1);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
    } else {
        // Wrap selezione: extract content, append a newEl, insertNode.
        // Preserva selezione = contenuto di newEl per consentire chain
        // di toggle/format (es. bold poi italic sulla stessa selezione).
        const fragment = range.extractContents();
        newEl.appendChild(fragment);
        range.insertNode(newEl);
        const r = document.createRange();
        r.selectNodeContents(newEl);
        sel.removeAllRanges();
        sel.addRange(r);
    }
    field.dispatchEvent(new Event("input", { bubbles: true }));
}

/**
 * Inserisce una "casella" editable inline (`<span class="<cls>">`) con
 * placeholder preset (es. ** per DSA). Visivamente evidente via CSS
 * `.fm-editor-field span.dots` / `.AddTextDSA` (bordo + bg color).
 *
 * Caret posizionato DENTRO lo span (utente scrive direttamente). Per
 * uscire: clic fuori, ArrowRight passa il caret oltre il close tag.
 */
export function insertEditableInlineBox(panel, cls, placeholder) {
    const ta = panel?._focusedTextarea
        || panel?.querySelector?.(".fm-editor-field")
        || (typeof window !== "undefined" ? window.__fmFocusedTA : null);
    if (!ta) return;
    // CRITICAL: catturo il range PRIMA che ta.focus() possa modificarlo
    // (su contenteditable, focus può collassare la selezione).
    let savedRange = null;
    if (ta.tagName !== "TEXTAREA") {
        const sel = window.getSelection();
        if (sel.rangeCount && ta.contains(sel.anchorNode)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    }
    ta.focus();
    UndoManager?.save?.(ta);

    if (ta.tagName === "TEXTAREA") {
        const open = `<span class="${cls}">${placeholder}</span>`;
        const start = ta.selectionStart ?? ta.value.length;
        ta.value = ta.value.slice(0, start) + open + ta.value.slice(start);
        const caret = start + open.indexOf("</span>");
        ta.setSelectionRange(caret, caret);
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        return;
    }

    const span = document.createElement("span");
    span.className = cls;
    span.textContent = placeholder;
    const sel = window.getSelection();
    if (savedRange) {
        // Ripristina range valido pre-click
        sel.removeAllRanges();
        sel.addRange(savedRange);
        if (!savedRange.collapsed) {
            const txt = savedRange.toString();
            savedRange.deleteContents();
            span.textContent = txt || placeholder;
        }
        savedRange.insertNode(span);
    } else {
        ta.appendChild(span);
    }
    const r = document.createRange();
    r.selectNodeContents(span);
    r.collapse(false);
    sel.removeAllRanges();
    sel.addRange(r);
    ta.dispatchEvent(new Event("input", { bubbles: true }));
}

/** Dialog inserimento link: chiede URL + testo visibile, inserisce
 *  `\href{URL}{testo}` (textarea) o `<a href>` elemento (contenteditable).
 *  Capture savedRange PRIMA del dialog open per evitare append-at-end fallback. */
export function insertLinkDialog(panel) {
    const ta = panel._focusedTextarea || panel.querySelector(".fm-editor-field");
    if (!ta) { localToast("Click su un editor per inserire un link", "warn"); return; }
    const isTextarea = ta.tagName === "TEXTAREA";
    const start = isTextarea ? (ta.selectionStart ?? ta.value.length) : 0;
    const end   = isTextarea ? (ta.selectionEnd   ?? ta.value.length) : 0;
    // CRITICAL: capture range PRIMA che il dialog rubi il focus.
    // Quando apriamo il dialog, la selection del field si perde → senza
    // range salvato, fallback appendChild metterebbe il link in fondo.
    let savedRange = null;
    if (!isTextarea) {
        const sel = window.getSelection();
        if (sel.rangeCount && ta.contains(sel.anchorNode)) {
            savedRange = sel.getRangeAt(0).cloneRange();
        }
    }
    const initialText = isTextarea
        ? ta.value.slice(start, end)
        : (savedRange?.toString() || "");

    document.getElementById("fm-link-dialog")?.remove();
    const dlg = document.createElement("div");
    dlg.id = "fm-link-dialog";
    dlg.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100050;display:flex;align-items:center;justify-content:center;font:13px/1.4 system-ui";
    dlg.innerHTML = `
        <div style="background:#1e1e1e;color:#ddd;border:1px solid #444;border-radius:8px;width:480px;max-width:92vw;overflow:hidden">
            <div style="padding:12px 16px;background:#2a2a2a;border-bottom:1px solid #444;font-weight:600">🔗 Inserisci link</div>
            <div style="padding:16px;display:flex;flex-direction:column;gap:10px">
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:#aaa">
                    URL
                    <input data-role="url" type="url" placeholder="https://..." style="padding:6px 10px;background:#0f0f0f;border:1px solid #444;border-radius:4px;color:#ddd;font:13px/1.3 monospace">
                </label>
                <label style="display:flex;flex-direction:column;gap:4px;font-size:12px;color:#aaa">
                    Testo visibile
                    <input data-role="text" type="text" placeholder="(opzionale, default = URL)" style="padding:6px 10px;background:#0f0f0f;border:1px solid #444;border-radius:4px;color:#ddd;font:13px/1.3 system-ui">
                </label>
            </div>
            <div style="padding:10px 12px;background:#252525;border-top:1px solid #444;display:flex;gap:8px;justify-content:flex-end">
                <button data-act="cancel" style="padding:6px 14px;background:#3a3a3a;color:#ddd;border:1px solid #555;border-radius:4px;cursor:pointer">Annulla</button>
                <button data-act="ok" style="padding:6px 14px;background:#2a5ac7;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:600">Inserisci</button>
            </div>
        </div>`;
    document.body.appendChild(dlg);
    const urlInp = dlg.querySelector('[data-role="url"]');
    const txtInp = dlg.querySelector('[data-role="text"]');
    txtInp.value = initialText;
    setTimeout(() => urlInp.focus(), 50);

    const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
    const esc = (e) => {
        if (e.key === "Escape") { close(); }
        else if (e.key === "Enter" && (e.target === urlInp || e.target === txtInp)) {
            e.preventDefault(); doInsert();
        }
    };
    document.addEventListener("keydown", esc);

    const doInsert = () => {
        const url = (urlInp.value || "").trim();
        if (!url) { urlInp.focus(); return; }
        const text = (txtInp.value || "").trim() || url;
        ta.focus();
        if (ta.tagName === "TEXTAREA") {
            // Textarea legacy: inserisci \href{URL}{testo} come stringa
            const replacement = `\\href{${url}}{${text}}`;
            ta.value = ta.value.slice(0, start) + replacement + ta.value.slice(end);
            const cursor = start + replacement.length;
            ta.setSelectionRange(cursor, cursor);
        } else {
            // Contenteditable: inserisci <a href> ELEMENTO HTML reale al
            // savedRange (capturato PRIMA dell'apertura dialog, quando ancora
            // il field aveva il focus + range valido).
            UndoManager?.save?.(ta);
            const a = document.createElement("a");
            a.href = url;
            a.target = "_blank";
            a.rel = "noopener";
            a.textContent = text;
            // Ripristina il range salvato per inserzione alla caret position
            const sel = window.getSelection();
            if (savedRange) {
                sel.removeAllRanges();
                sel.addRange(savedRange);
                savedRange.deleteContents();
                savedRange.insertNode(a);
                const r = document.createRange();
                r.setStartAfter(a);
                r.collapse(true);
                sel.removeAllRanges();
                sel.addRange(r);
            } else {
                // Fallback: caret a fine field (no range saved)
                const r = document.createRange();
                r.selectNodeContents(ta);
                r.collapse(false);
                r.insertNode(a);
            }
        }
        ta.dispatchEvent(new Event("input", { bubbles: true }));
        close();
    };

    dlg.addEventListener("click", (e) => {
        const act = e.target?.dataset?.act;
        if (act === "cancel" || e.target === dlg) close();
        if (act === "ok") doInsert();
    });
}
