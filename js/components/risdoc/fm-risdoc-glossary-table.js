import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocGlossaryTable extends LitElement {
    static properties = { section: { type: Object } };
    static styles = css`
        :host { display: block; margin: 15px 0; color: var(--fm-risdoc-text, #333); }
        .section-header { background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240)); padding: 6.5px 30px; font-weight: bold;
            font-size: 11pt; text-align: center; border: 1px solid var(--fm-risdoc-section-head-border, #8db070); border-bottom: 0;
            color: var(--fm-risdoc-text, #333); }
        table { width: 100%; border-collapse: collapse; background: var(--fm-risdoc-card-bg, #fff); }
        th, td { border: 1px solid var(--fm-risdoc-border, #c2c2c2); padding: 8px; font-size: 10pt;
            vertical-align: top; text-align: left; color: var(--fm-risdoc-text, #333); }
        th { background: var(--fm-risdoc-th-bg, #e0e0e0); }
    `;
    render() {
        const s = this.section || {};
        const rows = s.rows || [];
        const cols = s.columns || [
            { key: "term", label: "Termine" },
            { key: "definition", label: "Definizione" },
            { key: "source", label: "Fonte" },
        ];
        return html`
            ${s.title ? html`<div class="section-header">${s.title}</div>` : ""}
            <table>
                <thead><tr>${cols.map(c => html`<th>${c.label || c.key}</th>`)}</tr></thead>
                <tbody>
                    ${rows.map(row => html`<tr>${cols.map(c => html`<td>${row?.[c.key] || ""}</td>`)}</tr>`)}
                </tbody>
            </table>
        `;
    }
}
if (!customElements.get("fm-risdoc-glossary-table")) customElements.define("fm-risdoc-glossary-table", FmRisdocGlossaryTable);
