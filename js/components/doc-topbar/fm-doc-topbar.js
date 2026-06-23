/**
 * <fm-doc-topbar> — topbar DOCUMENTO centralizzata (ADR-024).
 *
 * Sorgente UNICA della topbar usata da:
 *   - <fm-pt-document>       (custom)  — variant="custom"
 *   - TemplateViewController  (risdoc)  — variant="risdoc"
 *
 * CSS: blocco BEM unico .fm-doc-topbar (modulo _doc-topbar.css), adattivo
 * light/dark WCAG. Light DOM (createRenderRoot→this).
 *
 * Config TUTTA via ATTRIBUTI/PROPRIETÀ (niente slot children: gli elementi
 * server-parsed avrebbero i figli non ancora nel DOM all'upgrade). I bottoni
 * sono uno spec dichiarativo; chip e section-navigator (risdoc) sono HTML
 * pre-renderizzato passato via chips-html / trailing-html.
 *
 * Azioni: ogni bottone porta `data-action` (+ data-template-id/argomento/role
 * opz.) e al click dispatcha `doc-topbar:action` (custom). Titolo editabile
 * → `doc-topbar:rename`.
 *
 * Proprietà:
 *   variant "custom" | "risdoc"
 *   doctype, title, subtitle, editable-title, busy
 *   .buttons  Array<{action,label,icon?,logo?,variant?,title?,pressed?,hidden?,
 *                    templateId?,argomento?,role?,keepEnabled?}>
 *   chips-html     HTML chip (ISTANZA/ADMIN) — solo risdoc
 *   trailing-html  HTML coda (section navigator) — solo risdoc
 */

import { LitElement, html, nothing } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { unsafeHTML } from "https://cdn.jsdelivr.net/npm/lit@3/directives/unsafe-html.js/+esm";

export class FmDocTopbar extends LitElement {
    static properties = {
        variant:       { type: String },
        doctype:       { type: String },
        docTitle:      { type: String, attribute: "title" },
        subtitle:      { type: String },
        editableTitle: { type: Boolean, attribute: "editable-title" },
        busy:          { type: Boolean },
        buttons:       { type: Array },
        // Bottoni resi PRIMA della zona meta (es. toggle HTML statico) e gruppo
        // azioni reso a DESTRA prima del navigator (es. Salva + Anteprima).
        leadingButtons: { type: Array },
        actionButtons:  { type: Array },
        chipsHtml:     { type: String, attribute: "chips-html" },
        buttonsHtml:   { type: String, attribute: "buttons-html" },
        trailingHtml:  { type: String, attribute: "trailing-html" },
    };

    createRenderRoot() { return this; }

    constructor() {
        super();
        this.variant = "custom";
        this.doctype = "Documento";
        this.docTitle = "";
        this.subtitle = "";
        this.editableTitle = false;
        this.busy = false;
        this.buttons = [];
        this.leadingButtons = [];
        this.actionButtons = [];
        this.chipsHtml = "";
        this.buttonsHtml = "";
        this.trailingHtml = "";
    }

    _emit(action) {
        this.dispatchEvent(new CustomEvent("doc-topbar:action", {
            detail: { action }, bubbles: true, composed: true,
        }));
    }

    _onTitleKeydown(e) {
        if (e.key === "Enter") { e.preventDefault(); e.target.blur(); }
        if (e.key === "Escape") { e.target.textContent = this.docTitle; e.target.blur(); }
    }
    _onTitleBlur(e) {
        const next = (e.target.textContent || "").trim();
        if (!next || next === this.docTitle) { e.target.textContent = this.docTitle; return; }
        this.dispatchEvent(new CustomEvent("doc-topbar:rename", {
            detail: { title: next }, bubbles: true, composed: true,
        }));
    }

    _renderTitle() {
        const ed = this.editableTitle;
        return html`<span
            class="fm-doc-topbar__title ${ed ? "fm-doc-topbar__title--editable" : ""}"
            contenteditable=${ed ? "true" : "false"} spellcheck="false"
            @keydown=${ed ? this._onTitleKeydown : null}
            @blur=${ed ? this._onTitleBlur : null}
            title=${ed ? "Clicca per rinominare il documento (Invio per salvare)" : ""}
        >${this.docTitle}</span>`;
    }

    _btn(b) {
        if (!b || b.hidden) return "";
        const cls = ["fm-doc-topbar__btn"];
        if (b.variant) cls.push(`fm-doc-topbar__btn--${b.variant}`);
        if (b.logo) cls.push("fm-doc-topbar__btn--logo");
        return html`
            <button type="button" class=${cls.join(" ")}
                    data-action=${b.action}
                    data-template-id=${b.templateId ?? nothing}
                    data-argomento=${b.argomento ?? nothing}
                    data-role=${b.role ?? nothing}
                    ?disabled=${this.busy && !b.keepEnabled}
                    aria-pressed=${b.pressed === undefined ? nothing : (b.pressed ? "true" : "false")}
                    title=${b.title || ""}
                    @click=${() => this._emit(b.action)}>
                ${b.logo
                    ? html`<img class="fm-doc-topbar__logo" src=${b.logo} alt="" aria-hidden="true">`
                    : (b.icon ? html`<span class="fm-doc-topbar__ico" aria-hidden="true">${b.icon}</span>` : "")}
                ${b.label ? html`<span class="fm-doc-topbar__lbl">${b.label}</span>` : ""}
            </button>`;
    }

    render() {
        return this.variant === "risdoc" ? this._renderRisdoc() : this._renderCustom();
    }

    /** Custom — zonato (leading + meta + target + actions) + spec bottoni. */
    _renderCustom() {
        const lead = this.leadingButtons || [];
        const actions = this.actionButtons || [];
        return html`
            <div class="fm-doc-topbar fm-doc-topbar--custom" role="toolbar" aria-label="Strumenti documento">
                ${lead.map((b) => this._btn(b))}
                <div class="fm-doc-topbar__zone fm-doc-topbar__zone--meta">
                    ${this.doctype ? html`<span class="fm-doc-topbar__doctype">${this.doctype}</span>` : ""}
                    ${this.docTitle || this.editableTitle ? this._renderTitle() : ""}
                    ${this.subtitle ? html`<span class="fm-doc-topbar__sub">${this.subtitle}</span>` : ""}
                </div>
                <div class="fm-doc-topbar__zone fm-doc-topbar__zone--target">
                    ${(this.buttons || []).map((b) => this._btn(b))}
                </div>
                ${(actions.length || this.trailingHtml) ? html`
                    <div class="fm-doc-topbar__actions">
                        ${actions.map((b) => this._btn(b))}
                        ${this.trailingHtml ? unsafeHTML(this.trailingHtml) : ""}
                    </div>` : ""}
            </div>
        `;
    }

    /**
     * Risdoc — STESSA struttura a zone del custom (`--custom`) per topbar
     * VISIVAMENTE IDENTICA tra modelli e custom. chipsHtml/buttonsHtml
     * server-side (admin/Modifica struttura) restano supportati.
     * ADR-026 #3 — i hook .fm-risdoc-toolbar / data-fm-risdoc-toolbar
     * sono RESIDUI: i delegation handler (fm-risdoc-export/toolbar-actions)
     * sono stati eliminati. Le classi restano come marker visivo.
     */
    _renderRisdoc() {
        return html`
            <div class="fm-doc-topbar fm-doc-topbar--custom fm-risdoc-toolbar" data-fm-risdoc-toolbar role="toolbar" aria-label="Strumenti documento">
                <div class="fm-doc-topbar__zone fm-doc-topbar__zone--meta">
                    ${this.doctype ? html`<span class="fm-doc-topbar__doctype">${this.doctype}</span>` : ""}
                    ${this.docTitle || this.editableTitle ? this._renderTitle() : ""}
                    ${this.subtitle ? html`<span class="fm-doc-topbar__sub">${this.subtitle}</span>` : ""}
                </div>
                <div class="fm-doc-topbar__zone fm-doc-topbar__zone--target">
                    ${this.chipsHtml ? unsafeHTML(this.chipsHtml) : ""}
                    ${this.buttonsHtml ? unsafeHTML(this.buttonsHtml) : (this.buttons || []).map((b) => this._btn(b))}
                    ${this.trailingHtml ? unsafeHTML(this.trailingHtml) : ""}
                    <div class="fm-doc-topbar__msg" data-fm-risdoc-msg></div>
                </div>
            </div>
        `;
    }
}

if (!customElements.get("fm-doc-topbar")) {
    customElements.define("fm-doc-topbar", FmDocTopbar);
}
