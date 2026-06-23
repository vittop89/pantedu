import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocGradeSelector extends LitElement {
    static properties = {
        section:  { type: Object },
        value:    { type: String },
        mappings: { type: Object },
    };

    static styles = css`
        :host {
            display: block;
            text-align: center;
            margin-bottom: 30px;
            padding: 15px;
            background: var(--fm-risdoc-panel-bg, #E0F2F7);
            border-radius: 6px;
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
        }
        label { font-size: 1.3em; font-weight: bold; color: var(--fm-risdoc-accent, #005A8D); margin-right: 10px; }
        select {
            padding: 10px 15px;
            font-size: 1.2em;
            border-radius: 4px;
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            background: var(--fm-risdoc-bg-field, #fff);
            color: var(--fm-risdoc-text, #333);
        }
    `;

    _onChange(e) {
        const newValue = e.target.value;
        this.value = newValue;
        // Emit own change
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: this.section.name, value: newValue },
            bubbles: true, composed: true,
        }));
        // Emit grade-change for giudizio-items to auto-fill
        this.dispatchEvent(new CustomEvent("fm:grade-change", {
            detail: { grade: newValue, mapping: this.mappings?.[newValue] || null },
            bubbles: true, composed: true,
        }));
    }

    render() {
        const s = this.section || {};
        const options = s.options || [];
        return html`
            <label for=${s.name || "gradeSelector"}>${s.label || "Voto"}:</label>
            <select id=${s.name || "gradeSelector"} name=${s.name || "gradeSelector"}
                    .value=${this.value || ""}
                    @change=${this._onChange}>
                ${options.map(o => {
                    const v = typeof o === "object" ? o.value : String(o);
                    const t = typeof o === "object" ? (o.label || o.value) : String(o);
                    return html`<option value=${v} ?selected=${v === this.value}>${t}</option>`;
                })}
            </select>
        `;
    }
}
if (!customElements.get("fm-risdoc-grade-selector")) customElements.define("fm-risdoc-grade-selector", FmRisdocGradeSelector);
