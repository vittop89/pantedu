import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocGiudizioGroup extends LitElement {
    static properties = {
        section: { type: Object },
        values:  { type: Object },
    };

    static styles = css`
        :host {
            display: block;
            margin-bottom: 30px;
            padding: 20px;
            background: var(--fm-risdoc-card-bg, #fff);
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            border-radius: 8px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.07);
            color: var(--fm-risdoc-text, #333);
        }
        label.group-label {
            display: block;
            font-size: 1.5em;
            margin-bottom: 18px;
            color: var(--fm-risdoc-accent, #005A8D);
            font-weight: bold;
            border-bottom: 1px solid var(--fm-risdoc-panel-bg, #E0F2F7);
            padding-bottom: 8px;
        }
    `;

    render() {
        const s = this.section || {};
        const items = s.items || [];
        const values = this.values || {};
        return html`
            <label class="group-label">${s.title || ""}</label>
            ${items.map(item => {
                const v = values[item.name];
                const fallback = (v === undefined || v === null || v === "") ? (item.default ?? "") : v;
                return html`
                    <fm-risdoc-giudizio-item
                        .section=${item}
                        .value=${fallback}
                    ></fm-risdoc-giudizio-item>`;
            })}
        `;
    }
}
if (!customElements.get("fm-risdoc-giudizio-group")) customElements.define("fm-risdoc-giudizio-group", FmRisdocGiudizioGroup);
