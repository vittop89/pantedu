import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocSignatureBlock extends LitElement {
    static properties = { section: { type: Object } };
    static styles = css`
        :host { display: block; margin-top: 40px; display: flex; justify-content: space-between;
            align-items: flex-end; gap: 20px; font-size: 11pt; color: var(--fm-risdoc-text, #333); }
        .field { display: flex; align-items: center; gap: 8px; }
        .line { border-bottom: 1px dotted var(--fm-risdoc-text, #555); display: inline-block; min-width: 200px; }
        .line.short { min-width: 100px; }
        .actions { display: flex; gap: 10px; }
        button { padding: 10px 20px; border-radius: 6px; cursor: pointer; border: 0;
            font-weight: 600; font-size: 1em; color: #fff; }
        .submit { background: linear-gradient(45deg, #667eea, #764ba2); }
        .reset { background: linear-gradient(135deg, #FF9800, #F57C00); }
    `;
    render() {
        const s = this.section || {};
        return html`
            <div class="field">
                <span>${s.label_data || "Data"}:</span><span class="line short"></span>
            </div>
            <div class="field">
                <span>${s.label_firma || "Firma"}:</span><span class="line"></span>
            </div>
            ${(s.show_submit || s.show_reset) ? html`
                <div class="actions">
                    ${s.show_submit ? html`<button type="submit" class="submit">${s.submit_label || "Invia"}</button>` : ""}
                    ${s.show_reset  ? html`<button type="reset" class="reset">${s.reset_label || "Reset"}</button>` : ""}
                </div>
            ` : ""}
        `;
    }
}
if (!customElements.get("fm-risdoc-signature-block")) customElements.define("fm-risdoc-signature-block", FmRisdocSignatureBlock);
