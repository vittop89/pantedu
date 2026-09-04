/**
 * G24.faseC-textarea-enhancements — Factory per i 2 handler "fat"
 * della tastiera/copy-paste su editor field:
 *
 *   createListKeyHandlers(deps).attach(field)
 *     - copy: pulisce span cosmetic-only style, preserva inline tags
 *     - paste: whitelist tag + dedupe + unwrap div/p (HTML semplificato)
 *     - keydown: ArrowL/R boundary exit, Ctrl+S/F/B/I/U, Tab/Shift+Tab,
 *       Enter su LI vuoto
 *
 *   createEnhanceTextarea(deps).attach(ta)
 *     - Solo per <textarea> (skip contenteditable)
 *     - Ctrl+B/I/M/Shift+M, Tab → 2 spazi, { → {}, [ → []
 *
 * Funzioni interne ai 2 attach (closure su field/ta) ridondanti ma chiari.
 *
 * Dipendenze (DI via deps):
 *   - expandRangeToInlineAncestors, handleInlineBoxExit (inline-format)
 *   - findEnclosingLi, indentListItem, outdentListItem (list-edit-utils)
 *   - undoManager (UndoManager singleton)
 *   - saveBackupSnapshot, openFindReplaceDialog, formatLatexInField,
 *     toggleInlineFormat, insertTabAtCaret, formatAndSaveLatex
 *     (residue functions still in monolite or external module)
 */

export function createListKeyHandlers(deps) {
    const {
        expandRangeToInlineAncestors,
        handleInlineBoxExit,
        findEnclosingLi, indentListItem, outdentListItem,
        undoManager,
        saveBackupSnapshot,
        openFindReplaceDialog,
        formatLatexInField,
        toggleInlineFormat,
        insertTabAtCaret,
    } = deps;

    function attach(field) {
        if (!field || field.tagName === "TEXTAREA") return;
        if (field.dataset.fmListKeysAttached === "1") return;
        field.dataset.fmListKeysAttached = "1";

        // === COPY: cleanup cosmetic span, preserve inline format ===
        field.addEventListener("copy", (e) => {
            const sel = window.getSelection();
            if (!sel || !sel.rangeCount) return;
            const range = sel.getRangeAt(0).cloneRange();
            if (!field.contains(range.commonAncestorContainer)) return;
            // Expand boundaries per includere parent inline interamente coperti.
            expandRangeToInlineAncestors(range, field);
            // Bug fix copia elenco nested: se la selezione tocca una struttura
            // lista, cloneContents() NON include il wrapper <ul>/<ol> ESTERNO
            // (è il commonAncestor) → il 1° punto perde il marker (diventa testo
            // inline + lista annidata). Espandiamo al <ul>/<ol> PIÙ ESTERNO così
            // il clone contiene la lista completa col suo primo <li>. Probe: si
            // espande SOLO se la selezione contiene davvero una lista (niente
            // over-copy su selezioni di solo testo).
            if (range.cloneContents().querySelector("li, ul, ol")) {
                let n = range.commonAncestorContainer, outerList = null;
                while (n && n !== field) {
                    if (n.nodeType === 1 && /^(OL|UL)$/.test(n.tagName)) outerList = n;
                    n = n.parentNode;
                }
                if (outerList) {
                    range.setStartBefore(outerList);
                    range.setEndAfter(outerList);
                }
            }
            const frag = range.cloneContents();
            const tmp = document.createElement("div");
            tmp.appendChild(frag);
            // Bug fix copia elenco nested: cloneContents() di una selezione
            // INTERAMENTE dentro una lista non include il wrapper <ol>/<ul>
            // (è l'ancestor comune) → restano <li> sciolti al top-level. Al
            // paste il primo <li> si fonde con la riga del caret ("si perde il
            // primo punto"). Riavvolgiamo i <li> top-level nella lista sorgente.
            {
                const tops = Array.from(tmp.childNodes).filter(
                    (n) => n.nodeType !== 3 || n.textContent.trim() !== ""
                );
                const allLi = tops.length > 0
                    && tops.every((n) => n.nodeType === 1 && n.tagName === "LI");
                if (allLi) {
                    let n = range.startContainer, srcList = null;
                    while (n && n !== field) {
                        if (n.nodeType === 1 && /^(OL|UL)$/.test(n.tagName)) srcList = n;
                        n = n.parentNode;
                    }
                    const wrap = document.createElement((srcList?.tagName || "UL").toLowerCase());
                    wrap.className = srcList?.className || "fm-dsa-li-list";
                    const ls = srcList?.getAttribute("data-fm-list-style");
                    if (ls) wrap.setAttribute("data-fm-list-style", ls);
                    const tp = srcList?.getAttribute("type");
                    if (tp) wrap.setAttribute("type", tp);
                    while (tmp.firstChild) wrap.appendChild(tmp.firstChild);
                    tmp.appendChild(wrap);
                }
            }
            // Unwrap span privi di class significativa con solo style inline
            tmp.querySelectorAll("span[style]").forEach((s) => {
                if (s.className && s.className !== "") return;
                const st = (s.getAttribute("style") || "").toLowerCase();
                const onlyCosmetic = st.split(";").map((d) => d.trim()).filter(Boolean)
                    .every((decl) => /^(color|background(-color)?|font(-family|-size|-weight|-style)?|text-decoration|line-height|letter-spacing|white-space)\s*:/i.test(decl));
                if (!onlyCosmetic) return;
                const parent = s.parentNode;
                while (s.firstChild) parent.insertBefore(s.firstChild, s);
                s.remove();
            });
            // Strip ZWS placeholder
            tmp.querySelectorAll("*").forEach((el) => {
                for (const child of Array.from(el.childNodes)) {
                    if (child.nodeType === 3 && /​/.test(child.textContent)) {
                        child.textContent = child.textContent.replace(/​/g, "");
                        if (!child.textContent) child.remove();
                    }
                }
            });
            // Rimuovi tag inline vuoti
            tmp.querySelectorAll("b, strong, i, em, u, s, sub, sup").forEach((el) => {
                if (!el.textContent && !el.querySelector("*")) el.remove();
            });
            e.clipboardData.setData("text/html", tmp.innerHTML);
            e.clipboardData.setData("text/plain", sel.toString());
            e.preventDefault();
        });

        // === PASTE: whitelist tags, strip dangerous content ===
        field.addEventListener("paste", (e) => {
            const cd = e.clipboardData;
            if (!cd) return;
            const html = cd.getData("text/html");
            if (!html) return; // fallback: browser plain text paste
            e.preventDefault();
            const tmp = document.createElement("div");
            tmp.innerHTML = html;
            tmp.querySelectorAll("script, style, link, meta, head, title").forEach((n) => n.remove());
            const commentNodes = [];
            const cwalk = document.createTreeWalker(tmp, NodeFilter.SHOW_COMMENT);
            let cmt = cwalk.nextNode();
            while (cmt) { commentNodes.push(cmt); cmt = cwalk.nextNode(); }
            commentNodes.forEach((n) => n.parentNode?.removeChild(n));
            const ALLOWED = new Set([
                "b", "strong", "i", "em", "u", "s", "sub", "sup", "a", "span",
                "br", "p", "div", "ol", "ul", "li",
            ]);
            const KEEP_CLASSES = /\b(dots|AddTextDSA|fm-dsa-li-list|fm-text|fm-latex)\b/;
            const removeList = [];
            const walker = document.createTreeWalker(tmp, NodeFilter.SHOW_ELEMENT);
            let cur = walker.nextNode();
            while (cur) {
                const tag = cur.tagName.toLowerCase();
                if (!ALLOWED.has(tag)) {
                    removeList.push({ node: cur, unwrap: true });
                } else {
                    for (const attr of Array.from(cur.attributes)) {
                        const name = attr.name.toLowerCase();
                        if (tag === "a" && name === "href") continue;
                        if (name === "class" && KEEP_CLASSES.test(attr.value)) continue;
                        // Preset marker elenco: va tenuto su OL **e UL** (prima solo
                        // ol → i preset ul tipo arrow-bullet/star-circle si perdevano
                        // al paste e tornavano ai marker default disc/circle/square).
                        // + type/start per fedeltà numerazione.
                        if ((tag === "ol" || tag === "ul")
                            && (name === "data-fm-list-style" || name === "type" || name === "start")) continue;
                        cur.removeAttribute(name);
                    }
                }
                cur = walker.nextNode();
            }
            for (const { node } of removeList) {
                const parent = node.parentNode;
                if (!parent) continue;
                while (node.firstChild) parent.insertBefore(node.firstChild, node);
                parent.removeChild(node);
            }
            tmp.querySelectorAll("*").forEach((el) => {
                for (const child of Array.from(el.childNodes)) {
                    if (child.nodeType === 3 && /​/.test(child.textContent)) {
                        child.textContent = child.textContent.replace(/​/g, "");
                        if (!child.textContent) child.remove();
                    }
                }
            });
            tmp.querySelectorAll("b, strong, i, em, u, s, sub, sup").forEach((el) => {
                if (!el.textContent && !el.querySelector("*")) el.remove();
            });
            // Strip whitespace-only text nodes con \n/\t (paste HTML)
            {
                const tnodes = [];
                const tw = document.createTreeWalker(tmp, NodeFilter.SHOW_TEXT);
                let tn = tw.nextNode();
                while (tn) { tnodes.push(tn); tn = tw.nextNode(); }
                tnodes.forEach((n) => {
                    if (!n.textContent.trim() && /[\n\t]/.test(n.textContent)) {
                        n.parentNode?.removeChild(n);
                    }
                });
            }
            // Unwrap top-level <div>/<p> → inline + <br> separators
            const meaningfulChildren = Array.from(tmp.childNodes).filter(
                (n) => n.nodeType !== 3 || n.textContent.trim() !== ""
            );
            const allBlocks = meaningfulChildren.length > 0
                && meaningfulChildren.every(
                    (n) => n.nodeType === 1 && /^(div|p)$/i.test(n.tagName)
                );
            if (allBlocks) {
                const blocks = Array.from(tmp.children).filter(
                    (el) => /^(div|p)$/i.test(el.tagName)
                );
                const replacement = document.createDocumentFragment();
                blocks.forEach((blk, i) => {
                    while (blk.firstChild) replacement.appendChild(blk.firstChild);
                    if (i < blocks.length - 1) replacement.appendChild(document.createElement("br"));
                });
                while (tmp.firstChild) tmp.removeChild(tmp.firstChild);
                tmp.appendChild(replacement);
            }
            // Bug fix copia/incolla elenco nested da QUALSIASI sorgente (anche
            // editor senza copy-handler o copia nativa del browser): se restano
            // <li> SCIOLTI al top-level (wrapper ol/ul perso) → al caret il primo
            // li si fonde con la riga corrente ("si perde il primo punto").
            // Riavvolgi i li (+ eventuale inline iniziale = primo punto parziale).
            if (tmp.querySelector(":scope > li")) {
                const wrap = document.createElement(tmp.querySelector("ol") ? "ol" : "ul");
                wrap.className = "fm-dsa-li-list";
                let curLi = null;
                for (const node of Array.from(tmp.childNodes)) {
                    if (node.nodeType === 1 && node.tagName === "LI") {
                        wrap.appendChild(node); curLi = null;
                    } else if (node.nodeType === 3 && !node.textContent.trim()) {
                        node.remove();
                    } else {
                        if (!curLi) { curLi = document.createElement("li"); wrap.appendChild(curLi); }
                        curLi.appendChild(node);
                    }
                }
                tmp.appendChild(wrap);
            }
            // Inserisci al caret
            const sel = window.getSelection();
            if (!sel.rangeCount) return;
            const range = sel.getRangeAt(0);
            range.deleteContents();
            const frag = document.createDocumentFragment();
            let lastInserted = null;
            while (tmp.firstChild) {
                lastInserted = tmp.firstChild;
                frag.appendChild(tmp.firstChild);
            }
            range.insertNode(frag);
            if (lastInserted) {
                const r = document.createRange();
                r.setStartAfter(lastInserted);
                r.collapse(true);
                sel.removeAllRanges();
                sel.addRange(r);
            }
            field.dispatchEvent(new Event("input", { bubbles: true }));
        });

        // === KEYDOWN: hotkeys + Tab/Enter su liste ===
        field.addEventListener("keydown", (e) => {
            const isMod = e.ctrlKey || e.metaKey;
            // ArrowL/R: boundary exit dagli span inline editable
            if ((e.key === "ArrowRight" || e.key === "ArrowLeft") && !isMod && !e.shiftKey) {
                if (handleInlineBoxExit(field, e.key)) {
                    e.preventDefault();
                    return;
                }
            }
            // Ctrl+S: salvataggio rapido backup
            if (isMod && !e.shiftKey && (e.key === "s" || e.key === "S")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                saveBackupSnapshot?.(panel);
                return;
            }
            // Ctrl+F: trova e sostituisci
            if (isMod && !e.shiftKey && (e.key === "f" || e.key === "F")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                const selText = window.getSelection()?.toString() || "";
                openFindReplaceDialog?.(panel, { initialQuery: selText });
                return;
            }
            // Ctrl+Shift+F: latexindent format
            if (isMod && e.shiftKey && (e.key === "f" || e.key === "F")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                formatLatexInField?.(panel);
                return;
            }
            // Ctrl+B/I/U: TOGGLE inline format HTML
            if (isMod && !e.shiftKey && (e.key === "b" || e.key === "B")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                toggleInlineFormat(panel, "b");
                return;
            }
            if (isMod && !e.shiftKey && (e.key === "i" || e.key === "I")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                toggleInlineFormat(panel, "i");
                return;
            }
            if (isMod && !e.shiftKey && (e.key === "u" || e.key === "U")) {
                e.preventDefault();
                const panel = field.closest(".fm-editor-panel") || { _focusedTextarea: field };
                toggleInlineFormat(panel, "u");
                return;
            }
            // Tab handling
            if (e.key === "Tab") {
                const sel = window.getSelection();
                if (!sel.rangeCount) return;
                const li = findEnclosingLi(sel.getRangeAt(0).startContainer, field);
                if (li) {
                    e.preventDefault();
                    undoManager.save(field);
                    if (e.shiftKey) {
                        outdentListItem(li, field);
                    } else {
                        indentListItem(li);
                    }
                    field.dispatchEvent(new Event("input", { bubbles: true }));
                    return;
                }
                // Tab fuori da lista: tabulazione smart
                e.preventDefault();
                undoManager.save(field);
                insertTabAtCaret(field, e.shiftKey);
                field.dispatchEvent(new Event("input", { bubbles: true }));
                return;
            }
            // Enter su LI VUOTO: outdent (esce dalla list)
            if (e.key === "Enter" && !e.shiftKey) {
                const sel = window.getSelection();
                if (!sel.rangeCount) return;
                const li = findEnclosingLi(sel.getRangeAt(0).startContainer, field);
                if (li && li.textContent.trim() === "" && !li.querySelector("ol, ul")) {
                    e.preventDefault();
                    undoManager.save(field);
                    outdentListItem(li, field, /*removeIfEmpty=*/true);
                    field.dispatchEvent(new Event("input", { bubbles: true }));
                    return;
                }
            }
            // Backspace a INIZIO voce elenco: outdent di un livello (come ogni
            // gestore di liste: Word/Docs/Notion). Se la voce è annidata risale
            // al livello padre; se è già top-level, rimuove la formattazione lista
            // del solo punto. Senza questo, Backspace a inizio voce non
            // "risolleva" sull'elenco nested. (Richiesta utente 2026-06-04.)
            if (e.key === "Backspace" && !e.shiftKey && !e.ctrlKey && !e.metaKey && !e.altKey) {
                const sel = window.getSelection();
                if (!sel.rangeCount || !sel.isCollapsed) return;
                const range = sel.getRangeAt(0);
                const li = findEnclosingLi(range.startContainer, field);
                if (li) {
                    // Caret all'inizio della voce? Misura il testo che precede il
                    // caret all'interno del contenuto diretto del <li>.
                    const pre = document.createRange();
                    pre.selectNodeContents(li);
                    try { pre.setEnd(range.startContainer, range.startOffset); } catch (_) { return; }
                    if (pre.toString().length === 0) {
                        e.preventDefault();
                        undoManager.save(field);
                        // caretAtStart=true: il caret resta all'INIZIO della voce
                        // outdentata (outdentListItem altrimenti lo mette a fine voce).
                        outdentListItem(li, field, /*removeIfEmpty=*/false, /*caretAtStart=*/true);
                        field.dispatchEvent(new Event("input", { bubbles: true }));
                        return;
                    }
                }
            }
        });
    }

    return { attach };
}

/** Factory per enhanceTextarea (textarea-only shortcuts).
 *  @param {{ formatAndSaveLatex: Function }} deps */
export function createEnhanceTextarea(deps) {
    const { formatAndSaveLatex } = deps;

    function attach(ta) {
        if (!ta || ta.dataset.fmEnhanced === "1") return;
        ta.dataset.fmEnhanced = "1";
        // contenteditable: skip shortcuts (text-slicing-based, incompatibili
        // con innerHTML). Use TeX dropdown buttons per snippet.
        if (ta.tagName !== "TEXTAREA") return;

        const wrap = (before, after) => {
            const s = ta.selectionStart, e = ta.selectionEnd;
            const v = ta.value;
            const sel = v.substring(s, e);
            ta.value = v.substring(0, s) + before + sel + after + v.substring(e);
            const newPos = sel ? s + before.length + sel.length + after.length
                               : s + before.length;
            ta.setSelectionRange(newPos, newPos);
            ta.dispatchEvent(new Event("input", { bubbles: true }));
        };
        const insertAt = (text, caretOffset = text.length) => {
            const s = ta.selectionStart;
            const v = ta.value;
            ta.value = v.substring(0, s) + text + v.substring(ta.selectionEnd);
            const newPos = s + caretOffset;
            ta.setSelectionRange(newPos, newPos);
            ta.dispatchEvent(new Event("input", { bubbles: true }));
        };

        ta.addEventListener("keydown", (e) => {
            const meta = e.ctrlKey || e.metaKey;
            // Ctrl+S: format LaTeX + save immediato
            if (meta && !e.shiftKey && (e.key === "s" || e.key === "S")) {
                e.preventDefault();
                formatAndSaveLatex?.(ta);
                return;
            }
            // Ctrl+B → \textbf{}
            if (meta && !e.shiftKey && (e.key === "b" || e.key === "B")) {
                e.preventDefault(); wrap("\\textbf{", "}"); return;
            }
            // Ctrl+I → \textit{}
            if (meta && !e.shiftKey && (e.key === "i" || e.key === "I")) {
                e.preventDefault(); wrap("\\textit{", "}"); return;
            }
            // Ctrl+M → \( \)
            if (meta && !e.shiftKey && (e.key === "m" || e.key === "M")) {
                e.preventDefault(); wrap("\\(", "\\)"); return;
            }
            // Ctrl+Shift+M → \[ \]
            if (meta && e.shiftKey && (e.key === "m" || e.key === "M")) {
                e.preventDefault(); wrap("\\[", "\\]"); return;
            }
            // Tab → 2 spazi
            if (e.key === "Tab" && !e.shiftKey) {
                e.preventDefault(); insertAt("  "); return;
            }
            // { → {} caret in mezzo (no selezione)
            if (e.key === "{" && !meta) {
                if (ta.selectionStart === ta.selectionEnd) {
                    e.preventDefault(); insertAt("{}", 1); return;
                }
            }
            // [ → [] caret in mezzo
            if (e.key === "[" && !meta) {
                if (ta.selectionStart === ta.selectionEnd) {
                    e.preventDefault(); insertAt("[]", 1); return;
                }
            }
        });
    }

    return { attach };
}
