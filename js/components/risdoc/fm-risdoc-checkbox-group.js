/**
 * <fm-risdoc-checkbox-group> — Phase 23.4 refactor.
 *
 * Wrapper Lit che monta `<fm-risdoc-pt-editor>` con default PT composto da
 * un `checkboxGroup` (o più, se options sono raggruppate per asse). Docente
 * ottiene la stessa UX composable del nota-textarea rich: toggle/edit/add/
 * remove items nativamente via NodeView interattivi.
 *
 * Compatibility:
 *   - section.options        static from schema (light cases)
 *   - section.options_source dynamic fetch (folder templated, file assoluto)
 *   - section.options[i].group  raggruppamento con heading (block strong)
 *
 * Value formats supportati (backward compat):
 *   - PT AST array (nuovo formato post-Phase 23.4)
 *   - Array of strings (legacy): convertito in items PT al load
 *
 * Events: fm:value-change con `value = PT AST`. TexBuilder già gestisce PT
 * via valueToTex, export invariato (\\xcheckbox/\\checkbox per ogni item).
 */

import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { ensurePtEditorLoaded } from "./_pt-loader.js";

export class FmRisdocCheckboxGroup extends LitElement {
    static properties = {
        section:          { type: Object },
        values:           { type: Array, reflect: false },
        state:            { type: Object },
        _loading:         { state: true },
        _error:           { state: true },
        _dynamicOptions:  { state: true },
        _ready:           { state: true },
    };

    static styles = css`
        :host { display: block; margin-bottom: 15px; color: var(--fm-risdoc-text, #333); }
        .section-header {
            background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240));
            padding: 6.5px 30px;
            font-weight: bold;
            font-size: 11pt;
            text-align: center;
            border: 1px solid var(--fm-risdoc-section-head-border, #8db070);
            border-bottom: 0;
            color: var(--fm-risdoc-text, #333);
        }
        .section-desc {
            padding: 8px 12px;
            background: var(--fm-risdoc-panel-bg, #f5f7fa);
            border: 1px solid var(--fm-risdoc-border-subtle, #e5e5e5);
            border-bottom: 0;
            font-size: 0.9em;
            color: var(--fm-risdoc-text-muted, #666);
            font-style: italic;
        }
        .editor-wrap { position: relative; }
        .loading, .empty, .error {
            padding: 10px 12px;
            font-size: 0.9em;
            border: 1px solid var(--fm-risdoc-border, #A2D2FF);
            background: var(--fm-risdoc-card-bg, #fff);
        }
        .loading { color: var(--fm-risdoc-accent, #005A8D); font-style: italic; }
        .empty   { color: var(--fm-risdoc-text-muted, #666); font-style: italic; }
        .error {
            color: var(--fm-risdoc-error-fg, #b91c1c);
            background: var(--fm-risdoc-error-bg, #fee2e2);
            border-color: var(--fm-risdoc-error-border, #fca5a5);
        }
    `;

    constructor() {
        super();
        this.values = [];
        this.state = {};
        this._dynamicOptions = null;
        this._loading = false;
        this._error = "";
        this._ready = false;
        this._lastFetchKey = "";
    }

    async connectedCallback() {
        super.connectedCallback();
        try {
            await ensurePtEditorLoaded();
            this._ready = true;
        } catch (e) {
            this._error = e.message || String(e);
            console.warn("[fm-risdoc-checkbox-group]", this._error);
        }
    }

    updated(changed) {
        if (changed.has("state") || changed.has("section")) {
            this._maybeLoadOptions();
        }
    }

    _maybeLoadOptions() {
        const src = this.section?.options_source;
        if (!src) return;
        let url = null;
        if (typeof src === "object" && src.file) {
            url = `/risdoc/${src.file}`;
        } else {
            // ADR-025 (B) — risolutore dinamico (override istituto → globale → file).
            const st = this.state || {};
            const folder = typeof src === "string" ? src : src.folder;
            const ind = st.indirizzo, cls = st.classe, mat = st.disciplina;
            if (!folder || !ind || !cls || !mat) return;
            url = `/api/risdoc/curriculum-options?dataset=${encodeURIComponent(folder)}`
                + `&indirizzo=${encodeURIComponent(ind)}&classe=${encodeURIComponent(cls)}`
                + `&materia=${encodeURIComponent(mat)}`;
        }
        if (url === this._lastFetchKey) return;
        this._lastFetchKey = url;
        this._loading = true;
        fetch(url, { credentials: "same-origin" })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .then(data => {
                this._dynamicOptions = this._parseJsonData(data);
                this._error = "";
            })
            .catch(e => {
                this._error = e.message;
                this._dynamicOptions = [];
            })
            .finally(() => { this._loading = false; });
    }

    _parseJsonData(data) {
        if (!Array.isArray(data)) return [];
        const out = [];
        for (const node of data) {
            if (node?.contenuti && Array.isArray(node.contenuti)) {
                for (const item of node.contenuti) {
                    out.push({ value: item.label, label: item.label, default: !!item.checked, group: node.titolo });
                }
            } else if (node?.label) {
                out.push({ value: node.label, label: node.label, default: !!node.checked });
            }
        }
        return out;
    }

    /**
     * Computa il PT AST iniziale da options + values correnti.
     * - values PT AST (array con _type): pass-through (già migrato)
     * - values array strings (legacy): match con options.value/label → state
     * - values empty: usa option.default se presente
     *
     * Raggruppamento options.group: emette heading block strong + checkboxGroup per ogni gruppo.
     */
    _computePt(opts) {
        // Se values è già PT AST valido, pass-through.
        if (Array.isArray(this.values) && this.values.length > 0
            && this.values.every((b) => b && typeof b === "object" && "_type" in b)) {
            return this.values;
        }
        const legacyValues = Array.isArray(this.values)
            ? this.values.filter((v) => typeof v === "string")
            : [];

        // Raggruppa options per `group` preservando ordine di prima apparizione.
        const groups = new Map(); // group-name → items[]
        for (const o of opts) {
            const obj = typeof o === "object" ? o : { value: String(o), label: String(o) };
            const groupName = obj.group || "";
            if (!groups.has(groupName)) groups.set(groupName, []);
            const label = obj.label ?? obj.value ?? "";
            const key   = obj.value ?? obj.label ?? "";
            let state = "_";
            if (legacyValues.length > 0) {
                state = legacyValues.includes(key) || legacyValues.includes(label) ? "x" : "_";
            } else if (obj.default) {
                state = "x";
            }
            groups.get(groupName).push({ state, label });
        }

        const blocks = [];
        for (const [groupName, items] of groups.entries()) {
            if (groupName) {
                blocks.push({
                    _type: "block",
                    style: "normal",
                    children: [{ _type: "span", text: groupName, marks: ["strong"] }],
                });
            }
            if (items.length > 0) {
                blocks.push({ _type: "checkboxGroup", items });
            }
        }
        return blocks;
    }

    _onPtChange(e) {
        const newValue = e.detail?.value;
        if (!this.section?.name) return;
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            bubbles: true, composed: true,
            detail: { name: this.section.name, value: newValue },
        }));
    }

    render() {
        const s = this.section || {};
        const staticOpts = s.options || [];
        const opts = this._dynamicOptions !== null ? this._dynamicOptions : staticOpts;

        const header = html`
            ${s.title ? html`<div class="section-header">${s.title}</div>` : ""}
            ${s.description ? html`<div class="section-desc">${s.description}</div>` : ""}
        `;

        if (this._error) {
            return html`${header}<div class="error">⚠ ${this._error}</div>`;
        }
        if (this._loading) {
            return html`${header}<div class="loading">Caricamento opzioni…</div>`;
        }
        if (!this._ready) {
            return html`${header}<div class="loading">Caricamento editor…</div>`;
        }
        if (!opts.length && s.options_source) {
            return html`${header}<div class="empty">Nessun dato per questa combinazione (indirizzo/classe/disciplina).</div>`;
        }

        const ptValue = this._computePt(opts);
        return html`
            ${header}
            <div class="editor-wrap">
                <fm-risdoc-pt-editor
                    .value=${ptValue}
                    .fields=${[]}
                    @pt-change=${this._onPtChange}
                ></fm-risdoc-pt-editor>
            </div>
        `;
    }
}
if (!customElements.get("fm-risdoc-checkbox-group")) customElements.define("fm-risdoc-checkbox-group", FmRisdocCheckboxGroup);
