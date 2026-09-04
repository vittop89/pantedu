import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocFormCheckbox extends LitElement {
    static formAssociated = true;
    static properties = { section: { type: Object }, checked: { type: Boolean, reflect: true } };
    static styles = css`:host { display: flex; align-items: flex-start; margin-bottom: 6px; color: var(--fm-risdoc-text, #333); }
        input { margin-right: 8px; } label { flex: 1; }`;
    constructor() { super(); try { this._internals = this.attachInternals?.(); } catch {} }
    _onChange(e) {
        this.checked = e.target.checked;
        this._internals?.setFormValue?.(this.checked ? "1" : "");
        this.dispatchEvent(new CustomEvent("fm:value-change", { detail: { name: this.section?.name, value: this.checked }, bubbles: true, composed: true }));
    }
    render() {
        const s = this.section || {};
        const id = `cb_${s.name}`;
        return html`
            <input type="checkbox" id=${id} name=${s.name} .checked=${!!this.checked} @change=${this._onChange}>
            <label for=${id}>${s.label || ""}</label>
        `;
    }
}
if (!customElements.get("fm-risdoc-form-checkbox")) customElements.define("fm-risdoc-form-checkbox", FmRisdocFormCheckbox);
