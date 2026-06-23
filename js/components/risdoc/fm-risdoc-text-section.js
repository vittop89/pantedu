import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocTextSection extends LitElement {
    static properties = { section: { type: Object }, value: { type: String, reflect: true } };
    static styles = css`
        :host { display: block; margin-bottom: 15px; color: var(--fm-risdoc-text, #333); }
        .section-header { background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240)); padding: 6.5px 30px; font-weight: bold;
            font-size: 11pt; text-align: center; border: 1px solid var(--fm-risdoc-section-head-border, #8db070); border-bottom: 0;
            color: var(--fm-risdoc-text, #333); }
        .section-content { border: 1px solid var(--fm-risdoc-section-border, #888); padding: 10px; min-height: 50px; background: var(--fm-risdoc-card-bg, #fff); }
        textarea { width: 100%; min-height: 80px; border: none; padding: 5px; font-family: Arial, sans-serif;
            font-size: 10pt; box-sizing: border-box; background: var(--fm-risdoc-bg-field, rgb(223, 234, 237));
            color: var(--fm-risdoc-text, #333); resize: vertical; }
    `;
    _onInput(e) {
        this.value = e.target.value;
        this.dispatchEvent(new CustomEvent("fm:value-change", { detail: { name: this.section?.name, value: this.value }, bubbles: true, composed: true }));
    }
    render() {
        const s = this.section || {};
        return html`
            ${s.title ? html`<div class="section-header">${s.title}</div>` : ""}
            <div class="section-content">
                <textarea name=${s.name} placeholder=${s.placeholder || ""} .value=${this.value || ""} @input=${this._onInput}></textarea>
            </div>
        `;
    }
}
if (!customElements.get("fm-risdoc-text-section")) customElements.define("fm-risdoc-text-section", FmRisdocTextSection);
