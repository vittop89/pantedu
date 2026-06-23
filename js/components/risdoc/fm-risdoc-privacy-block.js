import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { unsafeHTML } from "https://cdn.jsdelivr.net/npm/lit@3/directives/unsafe-html.js/+esm";

export class FmRisdocPrivacyBlock extends LitElement {
    static properties = { section: { type: Object } };
    static styles = css`
        :host { display: block; margin: 15px 0; color: var(--fm-risdoc-text, #333); }
        .section-header { background: var(--fm-risdoc-privacy-head-bg, rgb(255, 228, 225)); padding: 6.5px 30px; font-weight: bold;
            font-size: 11pt; text-align: center; border: 1px solid var(--fm-risdoc-privacy-head-border, #DC143C); border-bottom: 0;
            color: var(--fm-risdoc-text, #333); }
        .section-content { border: 1px solid var(--fm-risdoc-border, #c2c2c2); padding: 10px 20px;
            background: var(--fm-risdoc-card-bg, #fff); color: var(--fm-risdoc-text, #333); font-size: 10pt; }
    `;
    render() {
        const s = this.section || {};
        return html`
            <div class="section-header">${s.title || "Informativa Privacy"}</div>
            <div class="section-content">${unsafeHTML(s.body || "")}</div>
        `;
    }
}
if (!customElements.get("fm-risdoc-privacy-block")) customElements.define("fm-risdoc-privacy-block", FmRisdocPrivacyBlock);
