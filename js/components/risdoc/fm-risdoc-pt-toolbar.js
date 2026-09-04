/**
 * <fm-risdoc-pt-toolbar> — Phase 24.10b.
 *
 * Toolbar globale sticky che opera sull'editor PT correntemente focused.
 * Sostituisce le toolbar interne dei singoli <fm-risdoc-pt-editor> quando
 * il template ha molte section pt_unified (evita ripetizione visiva).
 *
 * Registry: `window.FM.pt` (popolato da js/entries/risdoc-pt-editor.js):
 *   - .currentEditor: riferimento all'ultimo pt-editor focused
 *   - .onFocusChange(fn): subscribe ai focus change
 *
 * Flow:
 *   1. User click inside un pt-editor → Tiptap onFocus → FM.pt.setFocused(this)
 *   2. Registry notifica la toolbar globale → this._focused aggiornato
 *   3. User click button toolbar → preventDefault mousedown → click →
 *      chiama public API del focused editor (toggleMark, insertQuick, ecc.)
 *
 * fm-pt-document monta <fm-risdoc-pt-toolbar> una sola volta a top + passa
 * `.compact=true` a ogni fm-risdoc-pt-section per disabilitare le toolbar
 * interne.
 */

import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { unsafeHTML } from "https://cdn.jsdelivr.net/npm/lit@3/directives/unsafe-html.js/+esm";
import { ensurePtEditorLoaded } from "./_pt-loader.js";
import "./fm-risdoc-images-manager.js"; // Phase 24.30 — popup imgs

// Icone allineamento (Google Docs style) — righe orizzontali. currentColor
// eredita il colore del bottone (dark-aware). NB: l'SVG va scritto INLINE in un
// solo template html (non con nested html`<line/>`: i figli verrebbero creati in
// namespace HTML invece di SVG → invisibili). Usiamo unsafeSVG-equivalente:
// stringa SVG completa via unsafeHTML.
const _alignSvg = (lines) => {
    const ls = lines.map(([x1, y, x2]) =>
        `<line x1="${x1}" y1="${y}" x2="${x2}" y2="${y}" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>`).join("");
    return unsafeHTML(`<svg width="15" height="15" viewBox="0 0 16 16" aria-hidden="true" style="display:block">${ls}</svg>`);
};
const ALIGN_ICONS = {
    left:    _alignSvg([[2, 4, 14], [2, 7, 10], [2, 10, 13], [2, 13, 9]]),
    center:  _alignSvg([[2, 4, 14], [4, 7, 12], [3, 10, 13], [5, 13, 11]]),
    right:   _alignSvg([[2, 4, 14], [6, 7, 14], [3, 10, 14], [7, 13, 14]]),
    justify: _alignSvg([[2, 4, 14], [2, 7, 14], [2, 10, 14], [2, 13, 14]]),
};

/** Elemento col focus REALE, attraversando gli shadow root annidati. */
function deepActiveElement() {
    let el = document.activeElement;
    while (el && el.shadowRoot && el.shadowRoot.activeElement) {
        el = el.shadowRoot.activeElement;
    }
    return el;
}

/** Risale dagli shadow host fino al <fm-risdoc-pt-editor> che possiede `el`. */
function ownerPtEditor(el) {
    let node = el;
    for (let i = 0; node && i < 12; i++) {
        const root = node.getRootNode && node.getRootNode();
        const host = root && root.host;
        if (host && host.tagName === "FM-RISDOC-PT-EDITOR") return host;
        node = host || (node.parentNode);
        if (!host && !node) break;
    }
    return null;
}

export class FmRisdocPtToolbar extends LitElement {
    static properties = {
        // ADR-024 — single-section: documento custom a body_pt singolo
        // (no schema multi-sezione). Nasconde i controlli risdoc-specifici
        // "Nuova sezione" / "Collassa tutto" / "Reset" (modello combinazione).
        singleSection: { type: Boolean, attribute: "single-section" },
        _focused:      { state: true },
        _ready:        { state: true },
        _tick:         { state: true }, // per refresh active marks state
        _orientation:  { state: true }, // Phase 24.22 — portrait | landscape
        _stylesOpen:   { state: true }, // Phase 24.30 — popup stili aperto
        _imagesOpen:   { state: true }, // Phase 24.30 — popup immagini aperto
        _styles:       { state: true }, // {sectionboxBg, sectionboxBorder, titleText}
        _allCollapsed: { state: true }, // Phase 25.E13 — tutte le pt-section collapsed?
    };

    static styles = css`
        :host {
            display: block;
            position: sticky;
            top: 0;
            z-index: 50;
            background: var(--fm-risdoc-toolbar-bg, #fafafa);
            border-bottom: 1px solid var(--fm-risdoc-toolbar-border, #e5e5e5);
            backdrop-filter: blur(6px);
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.08);
            margin-bottom: 12px;
        }
        .bar {
            display: flex;
            gap: 4px;
            padding: 6px 10px;
            flex-wrap: wrap;
            align-items: center;
            /* Se la toolbar (multi-riga) supera lo spazio sotto la doc-topbar,
               diventa scrollabile verticalmente → resta SEMPRE tutta raggiungibile
               (prima si vedeva solo un pezzo). --fm-pt-toolbar-top = altezza
               doc-topbar (impostata in JS). */
            max-height: calc(100vh - var(--fm-pt-toolbar-top, 84px) - 8px);
            overflow-y: auto;
        }
        .bar button {
            padding: 4px 10px;
            font-size: 13px;
            background: var(--fm-risdoc-btn-bg, #fff);
            border: 1px solid var(--fm-risdoc-btn-border, #ddd);
            border-radius: 3px;
            cursor: pointer;
            color: var(--fm-risdoc-btn-fg, #333);
        }
        .bar button:hover:not([disabled]) { background: var(--fm-risdoc-btn-hover, #f0f0f0); }
        .bar button[disabled] { opacity: 0.4; cursor: not-allowed; }
        .bar button.is-active {
            background: var(--fm-risdoc-btn-active-bg, #2a5ac7);
            color: var(--fm-risdoc-btn-active-fg, #fff);
            border-color: var(--fm-risdoc-btn-active-bg, #2a5ac7);
        }
        .sep {
            width: 1px;
            background: var(--fm-risdoc-btn-border, #ddd);
            align-self: stretch;
            margin: 0 4px;
        }
        .status {
            /* Phase 25.E14 — rimosso 'margin-left: auto' che spingeva i bottoni
               aux (Stili/Immagini/Vert./Reset) all'estremo destro causando
               wrap di Reset su seconda riga quando lo spazio era stretto.
               Ora status e' inline col flow normale. */
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #666);
            font-style: italic;
            min-width: 0;
            flex-shrink: 1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 220px;
        }
        .mode-toggle {
            padding: 3px 12px;
            background: var(--fm-pt-field-bg, #eef2ff);
            border: 1px solid var(--fm-pt-field-border, #a5b4fc);
            color: var(--fm-pt-field-fg, #3730a3);
            font-weight: 600;
            font-size: 12px;
            border-radius: 12px;
        }
        .mode-toggle.is-source {
            background: var(--fm-risdoc-code-bg, #1e293b);
            color: var(--fm-risdoc-code-fg, #e2e8f0);
            border-color: var(--fm-risdoc-border, #475569);
        }
        /* Phase 24.22 — orientation toggle pill */
        .orient-toggle {
            padding: 3px 12px;
            background: var(--fm-pt-field-bg, #ecfdf5);
            border: 1px solid var(--fm-pt-field-border, #6ee7b7);
            color: var(--fm-pt-field-fg, #065f46);
            font-weight: 600;
            font-size: 12px;
            border-radius: 12px;
            cursor: pointer;
            margin-right: 6px;
        }
        .orient-toggle.is-landscape {
            background: var(--fm-risdoc-warning-bg, #fef3c7);
            color: var(--fm-risdoc-warning-fg, #92400e);
            border-color: var(--fm-risdoc-warning-fg, #d97706);
        }
        .orient-toggle:hover { filter: brightness(1.08); }
        /* Phase 24.28 — add section pill */
        .add-section {
            padding: 3px 12px;
            background: var(--fm-pt-field-bg, #ecfdf5);
            color: var(--fm-pt-field-fg, #065f46);
            border: 1px dashed var(--fm-pt-field-border, #6ee7b7);
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
        }
        .add-section:hover { background: var(--fm-pt-field-border, #d1fae5); }
        /* Phase 25.E13 — collapse-all toggle pill */
        .collapse-all-toggle {
            padding: 3px 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 12px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            margin-right: 6px;
        }
        .collapse-all-toggle:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        /* Phase 24.33 — Reset modello pill */
        .reset-model {
            padding: 3px 12px;
            background: var(--fm-risdoc-error-bg, #fee2e2);
            color: var(--fm-risdoc-error-fg, #991b1b);
            border: 1px solid var(--fm-risdoc-error-fg, #b91c1c);
            border-radius: 12px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            /* Phase 25.E14 — 'margin-left: auto' rimosso (spingeva Reset
               estremo destro causando wrap). Reset ora segue gli aux pill. */
        }
        .reset-model:hover { background: var(--fm-risdoc-error-fg, #fca5a5); color: #fff; }
        /* Phase 24.30 — auxiliary toggle pills + popup */
        .aux-toggle {
            padding: 3px 10px;
            background: var(--fm-risdoc-btn-bg, #fff);
            color: var(--fm-risdoc-btn-fg, #334155);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 12px;
            cursor: pointer;
            font-size: 12px;
            margin-right: 4px;
        }
        .aux-toggle:hover { background: var(--fm-risdoc-btn-hover, #f1f5f9); }
        .aux-popup {
            position: absolute;
            top: 100%;
            right: 12px;
            z-index: 60;
            margin-top: 4px;
            min-width: 280px;
            padding: 12px 14px;
            background: var(--fm-risdoc-modal-bg, #fff);
            color: var(--fm-risdoc-text, #1e293b);
            border: 1px solid var(--fm-risdoc-modal-border, #cbd5e1);
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,.18);
            font-size: 13px;
        }
        .aux-popup.wide { min-width: 480px; }
        .aux-popup-h {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--fm-risdoc-accent, #2a5ac7);
            border-bottom: 1px solid var(--fm-risdoc-border-subtle, #e5e5e5);
            padding-bottom: 4px;
        }
        .aux-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 6px 0;
            font-size: 12px;
        }
        .aux-row input[type="color"] {
            width: 36px; height: 24px;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 4px;
            padding: 0;
            cursor: pointer;
        }
        .aux-row code {
            font-size: 10px;
            color: var(--fm-risdoc-text-muted, #64748b);
            font-family: monospace;
        }
        .aux-help {
            font-size: 11px;
            color: var(--fm-risdoc-text-muted, #64748b);
            font-style: italic;
            margin: 6px 0;
        }
        .aux-close {
            margin-top: 6px;
            padding: 4px 12px;
            background: var(--fm-risdoc-btn-bg, #fff);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
    `;

    constructor() {
        super();
        this._focused = null;
        this._ready = false;
        this._tick = 0;
        this.singleSection = false;
        this._orientation = "portrait";
        this._stylesOpen = false;
        this._imagesOpen = false;
        this._styles = { sectionboxBg: "#dbe4f0", sectionboxBorder: "#787878", titleText: "#000000" };
        this._allCollapsed = false;
        this._unsub = null;
        this._selTick = null;
        this._unsubState = null;
        this._onSectionCollapseChange = null;
    }

    async connectedCallback() {
        super.connectedCallback();
        try {
            await ensurePtEditorLoaded();
            this._ready = true;
        } catch (_) { /* silent, toolbar resta disabled */ }
        this._unsub = window.FM?.pt?.onFocusChange((ed) => {
            this._focused = ed;
        });
        // Phase 24.22 — subscribe state changes per page orientation
        // Phase 24.30 — also load styleOverrides
        this._unsubState = window.FM?.pt?.onStateChange?.((state) => {
            this._orientation = state?.pageOrientation || "portrait";
            const sov = state?.styleOverrides;
            if (sov && typeof sov === "object") {
                this._styles = { ...this._styles, ...sov };
            }
        });
        // Refresh "active marks" state ogni 250ms mentre focus è attivo.
        this._selTick = setInterval(() => {
            if (this._focused) this._tick++;
        }, 250);
        // Phase 25.E13 — listener cambio collapse delle pt-section per
        // aggiornare label "Collassa tutto" / "Espandi tutto".
        this._onSectionCollapseChange = () => this._refreshAllCollapsedState();
        document.addEventListener("fm:section-collapse-change", this._onSectionCollapseChange);
        // Initial state check (after Lit ha montato pt-section)
        setTimeout(() => this._refreshAllCollapsedState(), 300);

        // La toolbar (sticky top:0) era coperta dalla doc-topbar (anch'essa
        // sticky top:0, z più alto) → si vedeva solo l'ultima riga. Ancoriamo lo
        // sticky SOTTO la doc-topbar misurandone l'altezza (dinamica/wrap-aware).
        this._syncStickyTop = () => {
            const tb = document.querySelector(".fm-doc-topbar");
            const h = tb ? Math.round(tb.getBoundingClientRect().height) : 0;
            this.style.setProperty("--fm-pt-toolbar-top", `${h}px`);
            this.style.top = `${h}px`;
        };
        this._onWinResize = () => this._syncStickyTop();
        window.addEventListener("resize", this._onWinResize);
        requestAnimationFrame(() => this._syncStickyTop());
        setTimeout(() => this._syncStickyTop(), 350);
    }

    disconnectedCallback() {
        super.disconnectedCallback();
        this._unsub?.();
        this._unsubState?.();
        if (this._onWinResize) window.removeEventListener("resize", this._onWinResize);
        if (this._selTick) clearInterval(this._selTick);
        if (this._onSectionCollapseChange) {
            document.removeEventListener("fm:section-collapse-change", this._onSectionCollapseChange);
        }
    }

    _allPtSections() {
        // ADR-026 #3 — engine fm-risdoc-template eliminato. Tutte le pt-section
        // ora live sotto fm-pt-document (light DOM, no shadowRoot).
        const tpl = document.querySelector("fm-pt-document");
        if (!tpl) return [];
        return Array.from(tpl.querySelectorAll("fm-risdoc-pt-section"));
    }

    _refreshAllCollapsedState() {
        const sections = this._allPtSections();
        if (sections.length === 0) { this._allCollapsed = false; return; }
        this._allCollapsed = sections.every((s) => s._collapsed === true);
    }

    _toggleCollapseAll() {
        // Se tutte sono collapsed → espandi tutte; altrimenti → collassa tutte.
        const next = !this._allCollapsed;
        document.dispatchEvent(new CustomEvent("fm:collapse-all-sections", {
            detail: { collapsed: next },
        }));
        this._allCollapsed = next;
    }

    _toggleOrientation() {
        const next = this._orientation === "landscape" ? "portrait" : "landscape";
        // Dispatch fm:value-change scope=state così fm-pt-document lo persiste
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: "pageOrientation", value: next, scope: "state" },
            bubbles: true, composed: true,
        }));
        // Aggiorna anche registry per feedback immediato
        const curState = window.FM?.pt?.currentState || {};
        window.FM?.pt?.setState?.({ ...curState, pageOrientation: next });
    }

    _updateStyle(key, value) {
        this._styles = { ...this._styles, [key]: value };
        // Dispatch state update
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: "styleOverrides", value: this._styles, scope: "state" },
            bubbles: true, composed: true,
        }));
        const cur = window.FM?.pt?.currentState || {};
        window.FM?.pt?.setState?.({ ...cur, styleOverrides: this._styles });
    }

    _resetModel() {
        // Bubble al template per gestione (storage + DB cleanup)
        this.dispatchEvent(new CustomEvent("fm:reset-model", {
            bubbles: true, composed: true,
        }));
    }

    _addNewSection() {
        // Host sezionato (sia modelli risdoc che custom): <fm-pt-document>
        // (ADR-026 #3 — fm-risdoc-template eliminato). Per editor singolo
        // non-sezionato: fallback inline che inserisce un ptSectionHeader.
        const ed = this._focused || window.FM?.pt?.lastEditor;
        const sectionHost = document.querySelector("fm-pt-document");
        if (!sectionHost && ed && typeof ed.insertQuick === "function") {
            ed.insertQuick("ptSectionHeader", ["Nuova sezione", 2, []]);
            return;
        }
        // Host sezionato: dispatch event (template/documento custom lo gestisce).
        let afterName = null;
        if (ed) {
            try {
                const host = ed.getRootNode()?.host;
                if (host?.section) afterName = host.section.name || host.section.title || null;
            } catch (_) { /* shadow DOM non sempre raggiungibile */ }
        }
        this.dispatchEvent(new CustomEvent("fm:add-pt-section", {
            bubbles: true, composed: true, cancelable: true,
            detail: {
                section: {
                    type: "text-section",
                    title: "Nuova sezione",
                    pt_unified: true,
                    items: [],
                },
                afterName,
            },
        }));
    }

    /** ADR-026 — aggiunge una SOTTOSEZIONE (card annidata) nella sezione
     *  corrente (derivata dall'editor focalizzato). Bubble all'host (fm-pt-document)
     *  che la inserisce come figlia nell'albero delle sezioni. */
    _addSubsection() {
        const ed = this._focused || window.FM?.pt?.lastEditor;
        let parentName = null;
        if (ed) {
            try {
                const host = ed.getRootNode()?.host;
                if (host?.section) parentName = host.section.name || null;
            } catch (_) { /* shadow non sempre raggiungibile */ }
        }
        this.dispatchEvent(new CustomEvent("fm:add-pt-subsection", {
            bubbles: true, composed: true, cancelable: true,
            detail: { parentName },
        }));
    }

    _preserveSel(e) {
        // Il container .bar ha @mousedown=_preserveSel: il preventDefault preserva
        // la selezione dell'editor quando si clicca un BOTTONE (B/I/U/align).
        // MA blocca anche l'apertura del <select> elenchi nativo (il mousedown
        // risale fino a .bar). → NON fare preventDefault sui controlli nativi.
        const t = e.target;
        if (t && (t.tagName === "SELECT" || t.tagName === "OPTION"
            || t.tagName === "TEXTAREA"
            || (t.tagName === "INPUT" && t.type !== "checkbox" && t.type !== "radio"))) {
            return;
        }
        e.preventDefault();
    }

    _call(method, args) {
        const ed = this._focused;
        if (!ed) return;
        if (typeof ed[method] !== "function") {
            console.warn("[pt-toolbar] missing method", method);
            return;
        }
        ed[method](...(args || []));
    }

    /**
     * G23 Sprint 10b — B/I/U/code sempre attivi: operano su lastEditor (ultimo
     * editor focused, persistente anche quando il focus è su un input NodeView
     * interno → editor in blur ma lastEditor presente). toggleMark è
     * input-aware (wrap tag HTML se cursore in input).
     */
    _callMark(name) {
        // Route al pt-editor che POSSIEDE l'elemento col focus (input/contenteditable
        // della cella tabella, ecc.): senza questo, con più sezioni il mark finiva
        // sull'editor sbagliato e nelle celle "non funzionava".
        const el = deepActiveElement();
        const owner = (el && (el.isContentEditable || el.tagName === "INPUT" || el.tagName === "TEXTAREA"))
            ? ownerPtEditor(el) : null;
        const ed = owner || this._focused || window.FM?.pt?.lastEditor;
        if (ed && typeof ed.toggleMark === "function") ed.toggleMark(name);
    }

    /** Allineamento paragrafo (stile Google Docs) — opera su lastEditor. */
    _callAlign(align) {
        const ed = this._focused || window.FM?.pt?.lastEditor;
        if (ed && typeof ed.setAlign === "function") ed.setAlign(align);
    }
    _isActiveAlign(align) {
        const ed = this._focused || window.FM?.pt?.lastEditor;
        return ed && typeof ed.isActiveAlign === "function" && ed.isActiveAlign(align) ? "is-active" : "";
    }

    /** Inserisce/commuta una lista con variante (dropdown). */
    _callList(e) {
        const kind = e.target.value;
        e.target.selectedIndex = 0; // reset al placeholder
        if (!kind) return;
        const ed = this._focused || window.FM?.pt?.lastEditor;
        if (ed && typeof ed.setList === "function") ed.setList(kind);
    }

    _isActiveMark(name) {
        return !!this._focused?.isActiveMark?.(name);
    }

    // _isSource removed in Phase 24.33: source-mode toggle eliminato dalla
    // toolbar (utility avanzata non necessaria per workflow docente).

    render() {
        const focused = this._focused;
        const disabled = !focused;
        return html`
            <div class="bar" @mousedown=${this._preserveSel}>
                ${html`
                    <!-- G23 Sprint 10b — B/I/U/code SEMPRE attivi (no ?disabled):
                         operano su lastEditor + input-aware (wrap tag HTML se
                         cursore in input NodeView). Funzionano "in ogni zona". -->
                    <button type="button"
                            class=${this._isActiveMark("bold") ? "is-active" : ""}
                            @mousedown=${this._preserveSel}
                            @click=${() => this._callMark("bold")}
                            title="Grassetto (Ctrl+B)"><strong>B</strong></button>
                    <button type="button"
                            class=${this._isActiveMark("italic") ? "is-active" : ""}
                            @mousedown=${this._preserveSel}
                            @click=${() => this._callMark("italic")}
                            title="Corsivo (Ctrl+I)"><em>I</em></button>
                    <button type="button"
                            class=${this._isActiveMark("underline") ? "is-active" : ""}
                            @mousedown=${this._preserveSel}
                            @click=${() => this._callMark("underline")}
                            title="Sottolineato (Ctrl+U)"><u>U</u></button>
                    <button type="button"
                            class=${this._isActiveMark("code") ? "is-active" : ""}
                            @mousedown=${this._preserveSel}
                            @click=${() => this._callMark("code")}
                            title="Codice inline (Ctrl+E)"><code>&lt;&gt;</code></button>
                    <div class="sep"></div>
                    <!-- Allineamento paragrafo (Google Docs style) — left/center/right/justify.
                         Logica LaTeX in PtToTex.php (center/flushright/raggedright). -->
                    <button type="button" class="fm-pt-align ${this._isActiveAlign("left")}"
                            @mousedown=${this._preserveSel} @click=${() => this._callAlign("left")}
                            title="Allinea a sinistra" aria-label="Allinea a sinistra">${ALIGN_ICONS.left}</button>
                    <button type="button" class="fm-pt-align ${this._isActiveAlign("center")}"
                            @mousedown=${this._preserveSel} @click=${() => this._callAlign("center")}
                            title="Centra" aria-label="Centra">${ALIGN_ICONS.center}</button>
                    <button type="button" class="fm-pt-align ${this._isActiveAlign("right")}"
                            @mousedown=${this._preserveSel} @click=${() => this._callAlign("right")}
                            title="Allinea a destra" aria-label="Allinea a destra">${ALIGN_ICONS.right}</button>
                    <button type="button" class="fm-pt-align ${this._isActiveAlign("justify")}"
                            @mousedown=${this._preserveSel} @click=${() => this._callAlign("justify")}
                            title="Giustifica" aria-label="Giustifica">${ALIGN_ICONS.justify}</button>
                    <div class="sep"></div>
                    <!-- Elenchi (puntati/numerati) con varianti — preview HTML + export LaTeX (enumitem). -->
                    <select class="fm-list-snippet-select"
                            @change=${(e) => this._callList(e)}
                            title="Inserisci elenco (puntato/numerato, varianti) — render + export LaTeX">
                        <option value="">☰ Elenco</option>
                        <option value="ul">● ○ ■</option>
                        <option value="ul-arrow">➤ ♦ ●</option>
                        <option value="ul-star">★ ○ ■</option>
                        <option value="ol">1. a. i.</option>
                        <option value="ol-Alpha">A. 1. a.</option>
                        <option value="ol-alpha">a. i. 1.</option>
                        <option value="ol-Roman">I. A. 1.</option>
                        <option value="ol-zero">01. a. i.</option>
                        <option value="ol-paren">1) a) i)</option>
                        <option value="ol-Alpha-paren">A) 1) a)</option>
                        <option value="ol-alpha-paren">a) i) 1)</option>
                        <option value="ol-Roman-paren">I) A) 1)</option>
                        <option value="ol-zero-paren">01) a) i)</option>
                    </select>
                    <!-- Rientri elenco: via TAB / Shift+TAB nell'editor (shortcut
                         nativo Tiptap ListItem), niente pulsanti dedicati. -->
                    <div class="sep"></div>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("openInsertModal", ["fieldRef"])}
                            title="Inserisci riferimento a un campo del docente (es. classe, sezione). In TeX diventa [field-nome]">📝 Campo</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("openInsertModal", ["checkboxGroup"])}
                            title="Inserisci gruppo di checkbox (scelta multipla con opzioni modificabili)">☑ Gruppo</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("openInsertModal", ["rawTex"])}
                            title="Inserisci codice LaTeX grezzo (\\vspace, formule, comandi custom)">\\TeX</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("openInsertModal", ["ptTable"])}
                            title="Inserisci tabella editabile con header, righe e colonne">📋 Tabella</button>
                    <!-- 2026-05-27 — toolbar uniformata: "§ Sezione" mostrato anche
                         nei custom (parità con i modelli). Inserisce un sectionHeader
                         INLINE (sotto-intestazione H1–H4 dentro l'editor). -->
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptSectionHeader", ["Nuova sezione", 2, []]])}
                            title="Inserisci intestazione di sezione (H1–H4)">§ Sezione</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptTextField", ["Etichetta", "", "text"]])}
                            title="Inserisci input di testo/numero/data con etichetta">✎ Testo</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptSelect", ["Etichetta", "", []]])}
                            title="Inserisci menù a tendina. Clicca ⚙ sul select per aggiungere opzioni inline o collegare file JSON">⬇ Select</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptFormCheckbox", ["Affermazione", false]])}
                            title="Inserisci singolo checkbox sì/no">☐ Sì/No</button>
                    <div class="sep"></div>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptGlossaryTable", [["N.", "Lemma", "Definizione", "Fonte"], [{n: 1, lemma: "", definizione: "", fonte: ""}]]])}
                            title="G23 — Inserisci tabella glossario lemmi/definizioni con sort + search runtime">📖 Glossario</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptStaticContent", ["Nuova sezione", "<p>Inserisci contenuto qui...</p>", 2]])}
                            title="G23 — Inserisci sezione testo HTML sanitizzato (heading + paragrafi + liste)">📑 Sezione testo</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptAccordion", [[{title: "Nuova voce", body_pt: [{_type: "block", style: "normal", children: [{_type: "span", text: "", marks: []}]}], default_open: false}], true]])}
                            title="Inserisci un blocco a comparsa: voci che il lettore può aprire/chiudere nell'Anteprima e nel PDF">▸ A comparsa</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptLinkListPdf", ["Gruppo link", [{label: "Link 1", href: "", external: false}]]])}
                            title="G23 — Inserisci lista link normativi gerarchici (PDF/URL)">🔗 Link normativi</button>
                    <button type="button" ?disabled=${disabled}
                            @click=${() => this._call("insertQuick", ["ptCitationNorma", ["DM", "", "", "", "", "", ""]])}
                            title="G23 — Inserisci citazione legge/decreto strutturata">⚖ Citazione legge</button>
                    <div class="sep"></div>
                    <!-- "Nuova sezione" SEMPRE disponibile: anche nei
                         documenti single-section. _addNewSection fa fallback
                         inline (inserisce ptSectionHeader) quando non c'è
                         <fm-pt-document>. -->
                    <button type="button"
                            class="add-section"
                            @click=${() => this._addNewSection()}
                            title="Crea una nuova sezione (intestazione + corpo). In LaTeX diventa una \\section.">➕ Nuova sezione</button>
                    <button type="button"
                            class="add-section"
                            @click=${() => this._addSubsection()}
                            title="Aggiungi una SOTTOSEZIONE (card annidata) dentro la sezione corrente (es. 2.1 dentro 2). In LaTeX diventa una \\subsection.">➕ Sottosezione</button>
                    <!-- 2026-05-27 — toolbar uniformata: "Collassa tutto" mostrato
                         anche nei custom (anch'essi usano card fm-risdoc-pt-section
                         multiple, ADR-024) → l'azione è applicabile. -->
                    <button type="button" class="collapse-all-toggle"
                            @click=${() => this._toggleCollapseAll()}
                            title=${this._allCollapsed
                                ? "Espandi tutte le sezioni"
                                : "Collassa tutte le sezioni (mostra solo i titoli)"}>
                        ${this._allCollapsed ? "▸ Espandi tutto" : "▾ Collassa tutto"}
                    </button>
                `}
                <span class="status">
                    ${focused ? "✎ modifica sezione attiva" : "clicca dentro una sezione per iniziare"}
                </span>
                <!-- Phase 24.30 — Style picker popup -->
                <button type="button" class="aux-toggle"
                        @click=${() => { this._stylesOpen = !this._stylesOpen; this._imagesOpen = false; }}
                        title="Personalizza colori sectionbox del documento TeX">🎨 Stili</button>
                <!-- Phase 24.30 — Images manager popup -->
                <button type="button" class="aux-toggle"
                        @click=${() => { this._imagesOpen = !this._imagesOpen; this._stylesOpen = false; }}
                        title="Gestisci immagini override (loghi, grafica)">🖼 Immagini</button>
                <!-- Phase 24.22 — orientation toggle (portrait/landscape) -->
                <button type="button"
                        class="orient-toggle ${this._orientation === "landscape" ? "is-landscape" : ""}"
                        @click=${() => this._toggleOrientation()}
                        title=${this._orientation === "landscape"
                            ? "Pagina orizzontale (landscape). Clicca per tornare a verticale."
                            : "Pagina verticale (portrait). Clicca per passare a orizzontale."}>
                    ${this._orientation === "landscape" ? "🖼 Orizz." : "📄 Vert."}
                </button>
                <!-- Phase 24.33 — Reset modello (ripristina default per combinazione).
                     ADR-024 — nascosto per documenti single-section (concetto risdoc). -->
                ${this.singleSection ? "" : html`
                <button type="button" class="reset-model"
                        @click=${() => this._resetModel()}
                        title="Ripristina il modello originale per questa combinazione (classe/sezione/indirizzo/disciplina). Le tue modifiche saranno cancellate.">
                    ↺ Reset
                </button>`}
            </div>
            ${this._stylesOpen ? this._renderStylesPopup() : ""}
            ${this._imagesOpen ? this._renderImagesPopup() : ""}
        `;
    }

    _renderStylesPopup() {
        return html`
            <div class="aux-popup">
                <div class="aux-popup-h">🎨 Stili sectionbox</div>
                <label class="aux-row">
                    Sfondo titolo:
                    <input type="color" .value=${this._styles.sectionboxBg}
                        @input=${(e) => this._updateStyle("sectionboxBg", e.target.value)}>
                    <code>${this._styles.sectionboxBg}</code>
                </label>
                <label class="aux-row">
                    Bordo:
                    <input type="color" .value=${this._styles.sectionboxBorder}
                        @input=${(e) => this._updateStyle("sectionboxBorder", e.target.value)}>
                    <code>${this._styles.sectionboxBorder}</code>
                </label>
                <label class="aux-row">
                    Testo titolo:
                    <input type="color" .value=${this._styles.titleText}
                        @input=${(e) => this._updateStyle("titleText", e.target.value)}>
                    <code>${this._styles.titleText}</code>
                </label>
                <div class="aux-help">Override applicati al .sty del prossimo export ZIP.</div>
                <button type="button" class="aux-close" @click=${() => this._stylesOpen = false}>Chiudi</button>
            </div>
        `;
    }

    _renderImagesPopup() {
        return html`
            <div class="aux-popup wide">
                <div class="aux-popup-h">🖼 Immagini override</div>
                <fm-risdoc-images-manager></fm-risdoc-images-manager>
                <button type="button" class="aux-close" @click=${() => this._imagesOpen = false}>Chiudi</button>
            </div>
        `;
    }
}

if (!customElements.get("fm-risdoc-pt-toolbar")) {
    customElements.define("fm-risdoc-pt-toolbar", FmRisdocPtToolbar);
}
