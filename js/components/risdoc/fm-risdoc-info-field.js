import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocInfoField extends LitElement {
    static formAssociated = true; // Partecipa a FormData nativo
    static properties = { section: { type: Object }, value: { type: String, reflect: true } };
    static styles = css`
        :host { display: flex; align-items: center; margin-bottom: 8px; font-size: 11pt; }
        .label { font-weight: normal; margin-right: 5px; color: var(--fm-risdoc-text, #333); }
        .field { flex: 1; padding: 6px 8px; font-size: 10pt; border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            border-radius: 4px; background: var(--fm-risdoc-bg-field, #f9f9f9); color: var(--fm-risdoc-text, #333);
            margin-left: 5px; box-sizing: border-box; }
    `;
    constructor() { super(); try { this._internals = this.attachInternals?.(); } catch {} }
    _onInput(e) {
        this.value = e.target.value;
        this._internals?.setFormValue?.(this.value);
        this.dispatchEvent(new CustomEvent("fm:value-change", { detail: { name: this.section?.name, value: this.value }, bubbles: true, composed: true }));
    }
    render() {
        const s = this.section || {};
        const type = s.input_type || "text";
        const opts = s.options || [];
        return html`
            ${s.label ? html`<span class="label">${s.label}:</span>` : ""}
            ${type === "select" && opts.length ? html`
                <select class="field" name=${s.name} .value=${this.value || ""} @change=${this._onInput}>
                    <option value="">— Seleziona —</option>
                    ${opts.map(o => {
                        const v = typeof o === "object" ? (o.value ?? "") : String(o);
                        const t = typeof o === "object" ? (o.label ?? v) : String(o);
                        return html`<option value=${v} ?selected=${v === this.value}>${t}</option>`;
                    })}
                </select>
            ` : html`
                <input class="field" type=${type} name=${s.name} placeholder=${s.placeholder || ""}
                       .value=${this.value || ""} ?required=${!!s.required} @input=${this._onInput}>
            `}
        `;
    }
}
if (!customElements.get("fm-risdoc-info-field")) customElements.define("fm-risdoc-info-field", FmRisdocInfoField);
