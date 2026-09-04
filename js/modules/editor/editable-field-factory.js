/**
 * G24.refactor5.step8a — Estratto da `features/checkin-handlers.js` (monolite
 * 7400+ LOC). Factory low-level del field contenteditable con shim
 * textarea-like (`.value` getter/setter, selectionStart/End, setSelectionRange).
 *
 * Usato come building block da `createEditableField` (factory high-level
 * con rich-editing, undo, popup-preview, etc) che resta nel monolite per via
 * delle sue molte dipendenze cross-cutting (enhanceTextarea, UndoManager,
 * attachListKeyHandlers, showCellPopupPreview).
 *
 * Dipendenze: caret-utils (ceCaretOffset, ceSetCaret) per gli accessor.
 */

import { ceCaretOffset, ceSetCaret } from "./caret-utils.js";
import { UndoManager } from "./undo-manager.js";

/** Crea un `<div contentEditable>` con shim API textarea-like.
 *  - `.value` getter/setter mappato su `innerHTML`.
 *  - `.selectionStart/End` riportano offset plain-text (textContent).
 *  - `.setSelectionRange(s, e)` imposta la selection via Range API. */
export function makeEditableField() {
    const el = document.createElement("div");
    el.contentEditable = "true";
    el.spellcheck = false;
    el.tabIndex = 0;
    Object.defineProperty(el, "value", {
        get() { return el.innerHTML; },
        set(v) { el.innerHTML = (v == null ? "" : String(v)); },
        configurable: true,
    });
    // selectionStart/End: offset in textContent (best-effort).
    Object.defineProperty(el, "selectionStart", {
        get() { return ceCaretOffset(el, "start"); },
        set(n) { ceSetCaret(el, n, n); },
        configurable: true,
    });
    Object.defineProperty(el, "selectionEnd", {
        get() { return ceCaretOffset(el, "end"); },
        set(n) { ceSetCaret(el, n, n); },
        configurable: true,
    });
    el.setSelectionRange = (s, e) => ceSetCaret(el, s, e ?? s);
    return el;
}

/**
 * G24.faseA.2 — Composer/builder pattern per `EditorField` con mixin
 * dichiarativo. Encapsula gli step di setup tipicamente sparsi
 * in `createEditableField` del monolite:
 *
 *   new EditorFieldBuilder()
 *     .setAttrs({ field: "quesito", placeholder: "...", style: "..." })
 *     .setInitialValue(rawHtml)
 *     .withRichEditing({ enhanceTextarea, attachListKeyHandlers })
 *     .withDebouncedInput(onChange, 400)
 *     .withPopupPreview({ show, hide, popupId: "fm-cell-popup-preview" })
 *     .build();
 *
 * Ogni mixin è opt-in. Dipendenze injectate (no global lookup) per
 * mantenere il modulo standalone. UndoManager è importato direttamente
 * (singleton del sotto-sistema editor).
 */
export class EditorFieldBuilder {
    constructor() {
        this.el = makeEditableField();
        this.el.className = "fm-editor-field";
    }

    /** Set className/data-field/dataset/placeholder/style cssText. */
    setAttrs({ field, placeholder, dataset, style } = {}) {
        if (field) this.el.dataset.field = field;
        if (placeholder) this.el.setAttribute("data-placeholder", placeholder);
        if (dataset) {
            for (const [k, v] of Object.entries(dataset)) this.el.dataset[k] = String(v);
        }
        if (style) this.el.style.cssText = style;
        return this;
    }

    /** Set initial innerHTML (via `.value` shim). */
    setInitialValue(v) {
        this.el.value = v || "";
        return this;
    }

    /**
     * Rich editing mixin: attach textarea enhancements + UndoManager +
     * list key handlers. Tutti opt-in via deps; se non passati = no-op.
     * UndoManager.attach è sempre on (singleton del modulo).
     */
    withRichEditing({ enhanceTextarea, attachListKeyHandlers } = {}) {
        if (typeof enhanceTextarea === "function") {
            try { enhanceTextarea(this.el); } catch (_) { /* ignore */ }
        }
        try { UndoManager.attach(this.el); } catch (_) { /* ignore */ }
        if (typeof attachListKeyHandlers === "function") {
            try { attachListKeyHandlers(this.el); } catch (_) { /* ignore */ }
        }
        return this;
    }

    /** Listener "input" con debounce (default 400ms). cb riceve `el`. */
    withDebouncedInput(cb, ms = 400) {
        if (typeof cb !== "function") return this;
        const el = this.el;
        el.addEventListener("input", () => {
            clearTimeout(el._dbounce);
            el._dbounce = setTimeout(() => cb(el), ms);
        });
        return this;
    }

    /**
     * Popup preview on focus/blur. `show(el)` mostra, `hide()` nasconde.
     * Guard contro blur transient a popup/peer fm-editor-field per evitare
     * flicker quando l'utente cambia cella o scrolla il popup.
     */
    withPopupPreview({ show, hide, popupId = "fm-cell-popup-preview" } = {}) {
        if (typeof show !== "function" || typeof hide !== "function") return this;
        const el = this.el;
        el.addEventListener("focus", () => show(el));
        el.addEventListener("blur", (e) => {
            const next = e.relatedTarget;
            const popup = document.getElementById(popupId);
            if (popup && next && popup.contains(next)) return;
            if (next && next.classList?.contains("fm-editor-field")) return;
            setTimeout(() => {
                const active = document.activeElement;
                if (active && active.classList?.contains("fm-editor-field")) return;
                const p = document.getElementById(popupId);
                if (p && p.matches(":hover")) return;
                hide();
            }, 150);
        });
        return this;
    }

    build() { return this.el; }
}

