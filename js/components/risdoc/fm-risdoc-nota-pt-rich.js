/**
 * <fm-risdoc-nota-pt-rich> — Phase 22.4c.
 *
 * Variant PT-aware di `<fm-risdoc-nota-textarea>`: usato dalle card
 * risdoc-pt-section quando un field `type: nota-textarea` dichiara un
 * `default` in formato Portable Text AST (array of blocks). Sostituisce
 * la textarea plain con `<fm-risdoc-pt-editor>` (Tiptap + custom nodes).
 *
 * Lazy-load del bundle `risdoc-pt-editor` via manifest Vite: caricato
 * SOLO quando almeno un campo del template richiede PT editing.
 *
 * Props:
 *   section  {Object}  spec del campo dal schema (incluso `label`, `description`, `default`)
 *   value    {Array}   PT AST corrente (da compilation o fallback default)
 *
 * Emits:
 *   fm:value-change { name, value }  per coerenza con il delegation
 *                                    pattern di fm-pt-document.
 */
import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { ensurePtEditorLoaded } from "./_pt-loader.js";

export class FmRisdocNotaPtRich extends LitElement {
    static properties = {
        section: { type: Object },
        value:   { type: Array },
        _ready:  { state: true },
        _error:  { state: true },
    };

    static styles = css`
        /* Phase 23 — tutti i colors via CSS custom properties (risdoc-tokens.css). */
        :host { display: block; margin: 10px 0; }
        label.field-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: var(--fm-risdoc-text-strong, #334155);
            font-size: 0.95em;
        }
        .field-desc {
            color: var(--fm-risdoc-text-muted, #64748b);
            font-size: 0.9em;
            margin: 0 0 6px;
            font-style: italic;
        }
        .placeholder-loading {
            padding: 1em;
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 4px;
            text-align: center;
            color: var(--fm-risdoc-text-muted, #94a3b8);
            background: var(--fm-risdoc-elevated-bg, transparent);
            font-size: 0.9em;
        }
        .error {
            padding: 0.8em 1em;
            background: var(--fm-risdoc-error-bg, #fef2f2);
            border: 1px solid var(--fm-risdoc-error-border, #fca5a5);
            border-radius: 4px;
            color: var(--fm-risdoc-error-fg, #b91c1c);
            font-size: 0.9em;
        }
    `;

    constructor() {
        super();
        this.section = {};
        this.value = [];
        this._ready = false;
        this._error = "";
    }

    async connectedCallback() {
        super.connectedCallback();
        try {
            await ensurePtEditorLoaded();
            this._ready = true;
        } catch (e) {
            this._error = e.message || String(e);
            console.warn("[fm-risdoc-nota-pt-rich] load fail:", this._error);
        }
    }

    _onPtChange(e) {
        const newValue = e.detail?.value;
        if (!this.section?.name) return;
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            bubbles: true, composed: true,
            detail: { name: this.section.name, value: newValue },
        }));
    }

    /** Estrae lista di fieldRef name dai blocchi PT → popola field picker. */
    _extractFieldNames(pt) {
        const names = new Set();
        if (!Array.isArray(pt)) return [];
        for (const block of pt) {
            if (block?._type === "block" && Array.isArray(block.children)) {
                for (const c of block.children) {
                    if (c?._type === "fieldRef" && typeof c.name === "string" && c.name) {
                        names.add(c.name);
                    }
                }
            }
        }
        return [...names];
    }

    /**
     * Phase 23.3 — normalize value a PT AST. Casi:
     *   - array con PT blocks validi → as-is
     *   - array vuoto: fallback a schema.default se presente, altrimenti []
     *   - string non-vuota (legacy compilation plain): converti in single-block
     *     PT (preserva content su migration graduale)
     *   - undefined/null: schema.default o []
     */
    _normalizePt(v) {
        const sDefault = Array.isArray(this.section?.default) ? this.section.default : null;
        if (Array.isArray(v) && v.length > 0 && v.every((b) => b && typeof b === "object" && "_type" in b)) {
            return v;
        }
        if (typeof v === "string" && v.trim() !== "") {
            // Split per paragrafi (doppio newline) per preservare struttura minima
            const paragraphs = v.split(/\n\s*\n/).filter((p) => p.trim() !== "");
            return paragraphs.map((text) => ({
                _type: "block",
                style: "normal",
                children: [{ _type: "span", text: text.replace(/\n/g, " ").trim(), marks: [] }],
            }));
        }
        return sDefault && sDefault.length > 0 ? sDefault : [];
    }

    render() {
        const s = this.section || {};
        const header = html`
            ${s.label ? html`<label class="field-label">${s.label}</label>` : ""}
            ${s.description ? html`<p class="field-desc">${s.description}</p>` : ""}
        `;
        if (this._error) {
            return html`${header}<div class="error">⚠ ${this._error}</div>`;
        }
        if (!this._ready) {
            return html`${header}<div class="placeholder-loading">Caricamento editor…</div>`;
        }
        const val = this._normalizePt(this.value);
        const fields = this._extractFieldNames(val);
        return html`
            ${header}
            <fm-risdoc-pt-editor
                .value=${val}
                .fields=${fields}
                @pt-change=${this._onPtChange}
            ></fm-risdoc-pt-editor>
        `;
    }
}

if (!customElements.get("fm-risdoc-nota-pt-rich")) {
    customElements.define("fm-risdoc-nota-pt-rich", FmRisdocNotaPtRich);
}
