import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

/**
 * <fm-risdoc-giudizio-item .section=${item} .value=${v}>
 *
 * Ascolta `fm:grade-change` del root per auto-fill dal mapping voto →
 * criterio. Emette `fm:value-change` al change dell'utente.
 */
export class FmRisdocGiudizioItem extends LitElement {
    static formAssociated = true;
    static properties = {
        section: { type: Object },
        value:   { type: String, reflect: true },
    };
    constructor() { super(); try { this._internals = this.attachInternals?.(); } catch {} }

    static styles = css`
        :host { display: block; margin-bottom: 20px; }
        .desc {
            font-size: 1.05em;
            color: var(--fm-risdoc-text-strong, #2c3e50);
            margin-bottom: 8px;
            font-weight: 600;
        }
        select {
            width: 100%;
            padding: 12px;
            font-size: 1.15em;
            color: var(--fm-risdoc-text, #2c3e50);
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            border-radius: 4px;
            background: var(--fm-risdoc-bg-field, #f8f9fa);
            margin-bottom: 20px;
        }
        select:focus {
            border-color: var(--fm-risdoc-border-focus, #4A90E2);
            outline: none;
            box-shadow: 0 0 0 .2rem rgba(74, 144, 226, 0.25);
        }
    `;

    connectedCallback() {
        super.connectedCallback();
        // Bubble up ha già passato attraverso: ascolto dal document
        // via capture per reagire anche se root è in un altro scope.
        this._onGradeChange = this._onGradeChange.bind(this);
        document.addEventListener("fm:grade-change", this._onGradeChange);
    }
    disconnectedCallback() {
        document.removeEventListener("fm:grade-change", this._onGradeChange);
        super.disconnectedCallback();
    }

    _onGradeChange(e) {
        const m = e.detail?.mapping;
        if (!m || !this.section?.name) return;
        const target = m[this.section.name];
        if (target == null) {
            this.value = "";
        } else {
            // Trova option con value === target O label che inizia con target.
            const match = (this.section.options || []).find(o => {
                const v = typeof o === "object" ? o.value : String(o);
                const l = typeof o === "object" ? (o.label || o.value) : String(o);
                return v === target || l.startsWith(target);
            });
            this.value = match ? (typeof match === "object" ? match.value : match) : target;
        }
        this._emit();
    }

    _onInput(e) {
        this.value = e.target.value;
        this._emit();
    }

    _emit() {
        this._internals?.setFormValue?.(this.value || "");
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: this.section.name, value: this.value },
            bubbles: true, composed: true,
        }));
    }

    render() {
        const s = this.section || {};
        const options = s.options || [];
        return html`
            <div class="desc">${s.description || ""}</div>
            <select name=${s.name || ""} class="risp_giud"
                    .value=${this.value || ""}
                    @change=${this._onInput}>
                ${options.map(o => {
                    const v = typeof o === "object" ? o.value : String(o);
                    const t = typeof o === "object" ? (o.label || o.value) : String(o);
                    return html`<option value=${v} ?selected=${v === this.value}>${t}</option>`;
                })}
            </select>
        `;
    }
}
if (!customElements.get("fm-risdoc-giudizio-item")) customElements.define("fm-risdoc-giudizio-item", FmRisdocGiudizioItem);
