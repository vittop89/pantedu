/**
 * G24.refactor5.step6prep — Estratto da `features/checkin-handlers.js`
 * (monolite 8300+ LOC). Snapshot-based undo/redo per contenteditable.
 *
 * Riferimento legacy: functions-mod.js master
 *   `_saveUndoState/_performUndo/_performRedo`.
 *
 * Enabler per step 6 (inline-format) e step 8 (section-builders): inline
 * format toggle e altri DOM mutators chiamano `UndoManager.save(ta)`
 * prima di operazioni programmatiche per consentire all'utente di
 * rollback con Ctrl+Z.
 *
 * API:
 *   UndoManager.attach(field)  registra Ctrl+Z/Y handlers + input debounce
 *   UndoManager.save(field)    snapshot manuale (pre-azione programmatica)
 *   UndoManager.undo(field)    ripristina snapshot precedente
 *   UndoManager.redo(field)    ripristina snapshot successivo
 *
 * Snapshot = {html, caret} dove caret è {startPath, startOffset, endPath,
 * endOffset} (path = sequenza di childNodes indexes da `field`).
 * Stack max 50 stati per field.
 */

export const UndoManager = {
    _undo: new WeakMap(),   // field → []
    _redo: new WeakMap(),   // field → []
    _flag: new WeakMap(),   // field → bool (undo/redo in progress: skip save)
    _timer: new WeakMap(),  // field → debounce timer per input

    attach(field) {
        if (field.dataset.fmUndoAttached === "1") return;
        field.dataset.fmUndoAttached = "1";
        this._undo.set(field, []);
        this._redo.set(field, []);
        // Initial state
        this.save(field);

        field.addEventListener("keydown", (e) => {
            // Ctrl+Z (no shift) → undo
            if ((e.ctrlKey || e.metaKey) && !e.shiftKey && (e.key === "z" || e.key === "Z")) {
                e.preventDefault();
                this.undo(field);
                return;
            }
            // Ctrl+Y o Ctrl+Shift+Z → redo
            if ((e.ctrlKey || e.metaKey) && (e.key === "y" || e.key === "Y"
                || (e.shiftKey && (e.key === "z" || e.key === "Z")))) {
                e.preventDefault();
                this.redo(field);
                return;
            }
            // Save pre-paste / pre-cut
            if ((e.ctrlKey || e.metaKey)
                && (e.key === "v" || e.key === "V" || e.key === "x" || e.key === "X")) {
                this.save(field);
            }
        });

        // Input debounced save (ogni ~600ms di inattività)
        field.addEventListener("input", () => {
            if (this._flag.get(field)) return;
            const t = this._timer.get(field);
            if (t) clearTimeout(t);
            this._timer.set(field, setTimeout(() => this.save(field), 600));
        });
    },

    save(field) {
        if (this._flag.get(field)) return;
        const stack = this._undo.get(field);
        if (!stack) return;
        const html = field.innerHTML;
        const last = stack[stack.length - 1];
        if (last && last.html === html) return; // dedup
        stack.push({ html, caret: this._captureCaret(field) });
        if (stack.length > 50) stack.shift();
        // Pulisci redo dopo nuovo save
        this._redo.set(field, []);
    },

    undo(field) {
        // Algoritmo: vedere functions-mod.js master `_performUndo`.
        // Se top stack === current: pop, push in redo, restore previous.
        // Altrimenti: save current in redo, restore top stack.
        const undoStack = this._undo.get(field);
        const redoStack = this._redo.get(field);
        if (!undoStack || !undoStack.length) return false;

        const currentHtml = field.innerHTML;
        const top = undoStack[undoStack.length - 1];
        let target;

        if (top && top.html === currentHtml) {
            const popped = undoStack.pop();
            if (undoStack.length === 0) {
                undoStack.push(popped); // mantieni almeno 1 stato
                return false;
            }
            redoStack.push(popped);
            target = undoStack[undoStack.length - 1];  // peek (no pop)
        } else {
            redoStack.push({ html: currentHtml, caret: this._captureCaret(field) });
            target = undoStack[undoStack.length - 1];  // peek
        }
        this._restore(field, target);
        return true;
    },

    redo(field) {
        const redoStack = this._redo.get(field);
        const undoStack = this._undo.get(field);
        if (!redoStack || !redoStack.length) return false;
        // Push current state to undo stack, then restore from redo
        const currentHtml = field.innerHTML;
        const top = undoStack[undoStack.length - 1];
        if (!top || top.html !== currentHtml) {
            undoStack.push({ html: currentHtml, caret: this._captureCaret(field) });
        }
        const target = redoStack.pop();
        this._restore(field, target);
        return true;
    },

    _restore(field, snapshot) {
        this._flag.set(field, true);
        try {
            field.innerHTML = snapshot.html;
            this._restoreCaret(field, snapshot.caret);
            field.dispatchEvent(new Event("input", { bubbles: true }));
        } finally {
            // Defer reset flag per non re-saltare il save su input event
            setTimeout(() => this._flag.set(field, false), 0);
        }
    },

    _captureCaret(field) {
        const sel = window.getSelection();
        if (!sel.rangeCount) return null;
        const r = sel.getRangeAt(0);
        if (!field.contains(r.startContainer)) return null;
        return {
            startPath: this._nodePath(field, r.startContainer),
            startOffset: r.startOffset,
            endPath: this._nodePath(field, r.endContainer),
            endOffset: r.endOffset,
        };
    },

    _restoreCaret(field, caret) {
        if (!caret) return;
        const startNode = this._nodeFromPath(field, caret.startPath);
        const endNode = this._nodeFromPath(field, caret.endPath);
        if (!startNode || !endNode) return;
        try {
            const range = document.createRange();
            const sLen = startNode.nodeType === Node.TEXT_NODE
                ? startNode.length : startNode.childNodes.length;
            const eLen = endNode.nodeType === Node.TEXT_NODE
                ? endNode.length : endNode.childNodes.length;
            range.setStart(startNode, Math.min(caret.startOffset, sLen));
            range.setEnd(endNode, Math.min(caret.endOffset, eLen));
            const sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        } catch (_) { /* ignore range errors */ }
    },

    _nodePath(root, node) {
        const path = [];
        while (node && node !== root) {
            const parent = node.parentNode;
            if (!parent) return null;
            path.push(Array.prototype.indexOf.call(parent.childNodes, node));
            node = parent;
        }
        return path.reverse();
    },

    _nodeFromPath(root, path) {
        if (!path) return null;
        let node = root;
        for (const idx of path) {
            if (!node.childNodes || idx >= node.childNodes.length) return null;
            node = node.childNodes[idx];
        }
        return node;
    },
};
