import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

export class FmRisdocNotaTextarea extends LitElement {
    static properties = {
        section: { type: Object },
        value:   { type: String, reflect: true },
    };

    static styles = css`
        :host {
            display: block;
            margin-top: 35px;
            padding: 20px;
            background: var(--fm-risdoc-card-bg, #fff);
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            border-radius: 8px;
            box-shadow: 0 3px 6px rgba(0,0,0,0.07);
        }
        label {
            display: block;
            font-size: 1.4em;
            margin-bottom: 8px;
            color: var(--fm-risdoc-accent, #005A8D);
            font-weight: bold;
        }
        textarea {
            width: 100%;
            height: 250px;
            font-size: 11pt;
            font-family: monospace;
            padding: 10px;
            box-sizing: border-box;
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            border-radius: 4px;
            background: var(--fm-risdoc-bg-field, rgb(223, 234, 237));
            color: var(--fm-risdoc-text, #333);
            resize: vertical;
        }
        .status { margin-top: 5px; font-size: 0.9em; color: green; min-height: 1em; }
    `;

    _onInput(e) {
        this.value = e.target.value;
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: this.section?.name || "nota", value: this.value },
            bubbles: true, composed: true,
        }));
    }

    render() {
        const s = this.section || {};
        return html`
            <label for=${s.name || "nota_alunno"}>${s.label || "Note"}:</label>
            <textarea id=${s.name || "nota_alunno"} name=${s.name || "nota_alunno"}
                      .value=${this.value || ""}
                      @input=${this._onInput}></textarea>
            <div class="status"></div>
        `;
    }
}
if (!customElements.get("fm-risdoc-nota-textarea")) customElements.define("fm-risdoc-nota-textarea", FmRisdocNotaTextarea);
