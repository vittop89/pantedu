import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { unsafeHTML } from "https://cdn.jsdelivr.net/npm/lit@3/directives/unsafe-html.js/+esm";

export class FmRisdocStaticContent extends LitElement {
    static properties = { section: { type: Object } };
    static styles = css`
        :host { display: block; margin-bottom: 15px; color: var(--fm-risdoc-text, #333); }
        .section-header { background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240)); padding: 6.5px 30px; font-weight: bold;
            font-size: 11pt; text-align: center; border: 1px solid var(--fm-risdoc-section-head-border, #8db070); border-bottom: 0;
            color: var(--fm-risdoc-text, #333); }
        .section-content { border: 1px solid var(--fm-risdoc-section-border, #888); padding: 10px 20px; background: var(--fm-risdoc-card-bg, #fff);
            color: var(--fm-risdoc-text, #333); min-height: 50px; }
        .section-content p { margin: 0.5em 0; }
        .section-content h3, .section-content h4 { color: var(--fm-risdoc-accent, #2c3e50); }
    `;
    render() {
        const s = this.section || {};
        return html`
            ${s.title ? html`<div class="section-header">${s.title}</div>` : ""}
            ${s.body ? html`<div class="section-content">${unsafeHTML(s.body)}</div>` : ""}
            ${(s.items || []).map(i => html`<div><em>nested section not rendered in WC (type=${i.type})</em></div>`)}
        `;
    }
}
if (!customElements.get("fm-risdoc-static-content")) customElements.define("fm-risdoc-static-content", FmRisdocStaticContent);
