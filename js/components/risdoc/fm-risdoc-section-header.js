import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

/**
 * Sezione header del documento risdoc — 6 selettori standard:
 *   classe, sezione, indirizzo, disciplina, professore, studente.
 *
 * Fonte opzioni:
 *  - classe/indirizzo/disciplina → /curriculum API (stessa sel-wrapper sidebar)
 *  - sezione                     → A..H hardcoded
 *  - professore                  → input text (valore da state seed: nome+cognome del docente loggato)
 *  - studente                    → input text (nome+cognome studente, opt-in per template
 *                                  individuali tipo PDP, scheda osservativa, certificazione competenze)
 *
 * Sync con sidebar: onConnect legge `#sel-iis/cls/mater`, propaga a state.
 * On user change: propaga ai select sidebar + emette fm:value-change.
 */
export class FmRisdocSectionHeader extends LitElement {
    static properties = {
        section:     { type: Object },
        state:       { type: Object },
        _curriculum: { state: true },
    };

    static styles = css`
        :host { display: block; margin-bottom: 20px; }
        /* ADR-026 — intestazione collassabile (default aperta). <details>/<summary>
           nativo = accessibile (WCAG: focusabile, aria-expanded gestito dal browser). */
        .fm-risdoc-header { border-radius: 8px; }
        .fm-risdoc-header__summary {
            cursor: pointer;
            list-style: none;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            font-size: 14px;
            font-weight: 700;
            color: var(--fm-risdoc-accent, #1e40af);
            background: var(--fm-risdoc-toolbar-bg, #f1f5f9);
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-left: 4px solid var(--fm-risdoc-accent, #1e40af);
            border-radius: 6px;
        }
        .fm-risdoc-header__summary::-webkit-details-marker { display: none; }
        .fm-risdoc-header__summary::before {
            content: "▾"; transition: transform .15s ease; color: var(--fm-risdoc-text-muted, #64748b);
        }
        .fm-risdoc-header:not([open]) .fm-risdoc-header__summary::before { content: "▸"; }
        .fm-risdoc-header__summary:hover { background: var(--fm-risdoc-btn-hover, #e2e8f0); }
        .fm-risdoc-header__summary:focus-visible { outline: 2px solid var(--fm-risdoc-accent, #1e40af); outline-offset: 2px; }
        .fm-risdoc-header__title-preview {
            font-weight: 400; color: var(--fm-risdoc-text-muted, #64748b);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .fm-risdoc-header[open] .fm-risdoc-header__summary { margin-bottom: 6px; }
        .header {
            text-align: center;
            padding: 0 0 11px 0;
            background: var(--fm-risdoc-header-bg, linear-gradient(180deg, powderblue 34%, #1d6293 100%));
            color: var(--fm-risdoc-text, #2c3e50);
            font-weight: 600;
        }
        .header-title-input {
            display: block;
            width: 90%;
            margin: 0 auto 11px;
            font: normal 20pt "Arial Black", sans-serif;
            font-weight: bold;
            text-align: center;
            color: var(--fm-risdoc-accent, #003366);
            background: transparent;
            border: 1px dashed transparent;
            padding: 6px;
            border-radius: 4px;
        }
        .header-title-input:hover { border-color: rgba(0,51,102,.3); background: rgba(255,255,255,.4); }
        .header-title-input:focus { outline: 0; border-color: var(--fm-risdoc-accent, #003366); background: #fff; }
        .selectors-wrap { display: flex; flex-direction: column; align-items: center; gap: 4px; }
        .selectors-edit-row { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; justify-content: center; padding: 0 20px 4px; }
        .selectors-edit-row select, .selectors-edit-row button {
            font-size: 11px; padding: 2px 8px; border-radius: 3px;
            border: 1px solid rgba(255,255,255,.5); background: rgba(255,255,255,.15); color: #fff;
            cursor: pointer;
        }
        .selectors-edit-row button:hover { background: rgba(255,255,255,.3); }
        .selector-rm-btn {
            background: rgba(220,38,38,.6) !important; border-color: rgba(220,38,38,.8) !important;
            margin-left: 2px; padding: 0 6px !important; font-size: 10px;
        }
        .header-title {
            font: normal 20pt "Arial Black", sans-serif;
            font-weight: bold;
            margin: 0 20px 11px 41px;
            color: var(--fm-risdoc-accent, #003366);
        }
        .selectors__hint {
            display: block;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: .3px;
            text-transform: uppercase;
            color: var(--fm-risdoc-text, #2c3e50);
            opacity: .85;
            margin: 2px 0 4px;
        }
        .selectors { display: flex; justify-content: center; gap: 10px; padding: 5px 20px; flex-wrap: wrap; }
        select.field, input.field--text {
            background: var(--fm-risdoc-accent, #004a99);
            color: var(--fm-risdoc-text-inverse, #fff);
            padding: 5px 10px;
            border: inset;
            border-color: floralwhite;
            font-variant-caps: small-caps;
            cursor: pointer;
            min-width: 100px;
        }
        input.field--text::placeholder { color: rgba(255,255,255,.6); }
        input.field--text:focus { outline: 2px solid #fff; }
        .header-opts {
            display: flex; flex-wrap: wrap; justify-content: center; gap: 6px;
            margin-top: 6px;
        }
        .header-opt {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 8px;
            font-size: 11px; font-weight: 600; color: var(--fm-risdoc-text, #2c3e50);
            background: rgba(255,255,255,.45); border-radius: 4px; cursor: pointer;
        }
        .header-opt input { cursor: pointer; }
    `;

    constructor() {
        super();
        this._curriculum = null;
    }

    connectedCallback() {
        super.connectedCallback();
        // 1) Fetch curriculum options (same data della sel-wrapper sidebar)
        fetch("/curriculum", { credentials: "same-origin" })
            .then(r => r.ok ? r.json() : null)
            .then(json => { if (json?.ok) this._curriculum = json.curriculum; })
            .catch(() => {});
        // 2) Seed values da sel-wrapper sidebar se presente (priorità > state)
        queueMicrotask(() => this._syncFromSidebar());
        // 3) Listen sidebar changes
        this._onSidebarChange = () => this._syncFromSidebar();
        document.getElementById("sel-iis")?.addEventListener("change", this._onSidebarChange);
        document.getElementById("sel-cls")?.addEventListener("change", this._onSidebarChange);
        document.getElementById("sel-mater")?.addEventListener("change", this._onSidebarChange);
    }
    disconnectedCallback() {
        document.getElementById("sel-iis")?.removeEventListener("change", this._onSidebarChange);
        document.getElementById("sel-cls")?.removeEventListener("change", this._onSidebarChange);
        document.getElementById("sel-mater")?.removeEventListener("change", this._onSidebarChange);
        super.disconnectedCallback();
    }

    _syncFromSidebar() {
        const iis = document.getElementById("sel-iis")?.value || "";
        const cls = document.getElementById("sel-cls")?.value || "";
        const mat = document.getElementById("sel-mater")?.value || "";
        const next = { ...(this.state || {}) };
        const prev = this.state || {};
        const toEmit = [];
        if (iis && prev.indirizzo !== iis) { next.indirizzo = iis; toEmit.push(["indirizzo", iis]); }
        if (cls && prev.classe !== cls)    { next.classe    = cls; toEmit.push(["classe", cls]); }
        if (mat && prev.disciplina !== mat){ next.disciplina = mat; toEmit.push(["disciplina", mat]); }
        // Default sezione: se non c'è nello state, metti "A" come fallback
        // (l'utente può cambiarla dal dropdown A-H)
        if (!prev.sezione) { next.sezione = "A"; toEmit.push(["sezione", "A"]); }
        this.state = next;
        // Propaga al parent template (value-change scope=state) cosicché
        // fm-risdoc-checkbox-group riceva lo state aggiornato via .state=
        for (const [k, v] of toEmit) {
            this.dispatchEvent(new CustomEvent("fm:value-change", {
                detail: { name: k, value: v, scope: "state" },
                bubbles: true, composed: true,
            }));
        }
    }

    _emit(key, value) {
        // sync ai sidebar select
        const map = { indirizzo: "sel-iis", classe: "sel-cls", disciplina: "sel-mater" };
        const sb = map[key] && document.getElementById(map[key]);
        if (sb && sb.value !== value) {
            sb.value = value;
            sb.dispatchEvent(new Event("change", { bubbles: true }));
        }
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: key, value, scope: "state" },
            bubbles: true, composed: true,
        }));
    }

    _onChange(e) {
        const key = e.target.dataset.key;
        const value = e.target.value;
        this.state = { ...(this.state || {}), [key]: value };
        this._emit(key, value);
    }

    _optionsFor(key) {
        const cur = this._curriculum;
        if (!cur) return null;
        switch (key) {
            case "indirizzo": return cur.indirizzi || [];
            case "classe":    return cur.classi || [];
            case "disciplina":return cur.materie || [];
            case "sezione":   return ["A","B","C","D","E","F","G","H"].map(l => ({ code: l, label: l }));
            default: return null;
        }
    }

    render() {
        const s = this.section || {};
        const state = this.state || {};
        // Phase 24.33 — title + selectors override-aware (per-combination state)
        const title = (typeof state.headerTitle === "string" && state.headerTitle.length)
            ? state.headerTitle : (s.title || "");
        const selectors = Array.isArray(state.headerSelectors)
            ? state.headerSelectors : (s.selectors || []);
        const allKeys = ["classe", "sezione", "indirizzo", "disciplina", "professore", "studente"];
        const availableToAdd = allKeys.filter(k => !selectors.includes(k));
        return html`
            <details class="fm-risdoc-header" open>
                <summary class="fm-risdoc-header__summary">
                    <span class="fm-risdoc-header__label">📋 Intestazione e selettori</span>
                    ${title ? html`<span class="fm-risdoc-header__title-preview">${title}</span>` : ""}
                </summary>
            <div class="header">
                <input class="header-title-input"
                       type="text"
                       .value=${title}
                       placeholder="Titolo del documento…"
                       @blur=${(e) => this._emitHeader("headerTitle", e.target.value)}>
                <div class="selectors-wrap">
                    ${selectors.length > 0 ? html`
                        <span class="selectors__hint">Seleziona classe, indirizzo e disciplina</span>
                        <div class="selectors">
                            ${selectors.map(key => html`
                                <span style="display:inline-flex;align-items:center;gap:2px">
                                    ${this._renderSelector(key, state[key])}
                                    <button type="button" class="selector-rm-btn"
                                            title="Rimuovi selettore ${key}"
                                            @click=${() => this._removeSelector(selectors, key)}>×</button>
                                </span>
                            `)}
                        </div>
                    ` : ""}
                    ${availableToAdd.length > 0 ? html`
                        <div class="selectors-edit-row">
                            <select id="header-add-sel" title="Aggiungi un selettore al header">
                                <option value="">+ aggiungi…</option>
                                ${availableToAdd.map(k => html`<option value=${k}>${k}</option>`)}
                            </select>
                            <button type="button"
                                    @click=${() => this._addSelector(selectors)}>Aggiungi</button>
                        </div>
                    ` : ""}
                    <div class="header-opts">
                        <label class="header-opt">
                            <input type="checkbox"
                                   .checked=${state.includeHeader !== false}
                                   @change=${(e) => this._emitHeader("includeHeader", e.target.checked)}>
                            Includi intestazione istituto (loghi) nel PDF
                        </label>
                        <label class="header-opt">
                            <input type="checkbox"
                                   .checked=${state.includeHeaderHtml !== false}
                                   @change=${(e) => this._emitHeader("includeHeaderHtml", e.target.checked)}>
                            Includi intestazione e selettori nell'HTML statico pubblicato
                        </label>
                    </div>
                </div>
            </div>
            </details>
        `;
    }

    _emitHeader(key, value) {
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: key, value, scope: "state" },
            bubbles: true, composed: true,
        }));
    }

    _addSelector(current) {
        const sel = this.renderRoot.querySelector("#header-add-sel");
        const v = sel?.value;
        if (!v) return;
        const next = Array.from(new Set([...current, v]));
        this._emitHeader("headerSelectors", next);
    }

    _removeSelector(current, key) {
        const next = current.filter((k) => k !== key);
        this._emitHeader("headerSelectors", next);
    }

    _renderSelector(key, current) {
        const opts = this._optionsFor(key);
        // Campi free-text: professore, studente, materia custom, etc.
        if (!opts) {
            return html`
                <input class="field field--text" data-key=${key} name=${key} type="text"
                       placeholder="${key}" .value=${current || ""}
                       @input=${this._onChange}>
            `;
        }
        return html`
            <select class="field" data-key=${key} name=${key}
                    .value=${current || ""}
                    @change=${this._onChange}>
                <option value="">— ${key} —</option>
                ${opts.map(o => html`
                    <option value=${o.code} ?selected=${o.code === current}>${o.label || o.code}</option>
                `)}
            </select>
        `;
    }
}
if (!customElements.get("fm-risdoc-section-header")) customElements.define("fm-risdoc-section-header", FmRisdocSectionHeader);
