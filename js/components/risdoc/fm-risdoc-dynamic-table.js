import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";

/**
 * ADR-025 (B) — opzioni via risolutore dinamico /api/risdoc/curriculum-options
 * (override istituto → globale → file), codici curriculum canonici diretti.
 */
export class FmRisdocDynamicTable extends LitElement {
    static properties = {
        section:  { type: Object },
        rows:     { type: Array },
        state:    { type: Object },
        _udaData: { state: true },
    };
    static styles = css`
        :host { display: block; margin: 15px 0; color: var(--fm-risdoc-text, #333); }
        .section-header { background: var(--fm-risdoc-section-head-bg, rgb(219, 228, 240)); padding: 6.5px 30px; font-weight: bold;
            font-size: 11pt; text-align: center; border: 1px solid var(--fm-risdoc-section-head-border, #8db070); border-bottom: 0;
            color: var(--fm-risdoc-text, #333); }
        table { width: 100%; border-collapse: collapse; background: var(--fm-risdoc-card-bg, #fff); }
        th, td { border: 1px solid var(--fm-risdoc-border, #c2c2c2); padding: 6px; font-size: 10pt;
            vertical-align: top; color: var(--fm-risdoc-text, #333); }
        th { background: var(--fm-risdoc-th-bg, #e0e0e0); }
        textarea { width: 100%; min-height: 40px; border: none; resize: vertical; font-size: 10pt;
            background: var(--fm-risdoc-bg-field, transparent); color: var(--fm-risdoc-text, #333); }
        input { width: 100%; padding: 4px 6px; font-size: 10pt; border: 1px solid var(--fm-risdoc-border, #ccc);
            border-radius: 3px; background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, #333);
            box-sizing: border-box; }
        td.label-cell { font-weight: 500; background: var(--fm-risdoc-panel-bg, #f5f7fa); }
        .uda-moduli { display: flex; flex-direction: column; gap: 4px; max-height: 200px; overflow-y: auto; }
        .uda-mod-item { display: flex; align-items: flex-start; gap: 6px; font-size: 9.5pt; line-height: 1.25; cursor: pointer; }
        .uda-mod-item input[type="checkbox"] { width: auto; flex-shrink: 0; margin-top: 3px; }
        .uda-mod-item span { flex: 1; }
        .uda-mod-rm { width: 20px; height: 20px; padding: 0; font-size: 11px;
            background: var(--fm-risdoc-action-danger, #e57373);
            color: #fff; border: 0; border-radius: 3px; cursor: pointer; flex-shrink: 0; }
        .uda-mod-rm:hover { background: var(--fm-risdoc-action-danger-hover, #f44336); }
        .uda-add-row { display: flex; flex-direction: column; gap: 4px; margin-top: 8px;
            padding-top: 6px; border-top: 1px dashed var(--fm-risdoc-border, #ccc); }
        .uda-add-select, .uda-custom-input { font-size: 9pt; padding: 3px 5px;
            border: 1px solid var(--fm-risdoc-border, #A2D2FF); border-radius: 3px;
            background: var(--fm-risdoc-bg-field, #fff); color: var(--fm-risdoc-text, #333); }
        .uda-rm-row { display: block; margin-top: 4px; width: 28px; height: 24px; padding: 0;
            background: var(--fm-risdoc-action-danger, #e57373); color: #fff;
            border: 0; border-radius: 3px; cursor: pointer; font-size: 11px; }
        .uda-rm-row:hover { background: var(--fm-risdoc-action-danger-hover, #f44336); }
        .uda-add-uda { padding: 6px 14px;
            background: linear-gradient(135deg,
                var(--fm-risdoc-action-success, #4caf50),
                var(--fm-risdoc-action-success-hover, #81c784));
            color: #fff; border: 0; border-radius: 4px; cursor: pointer; font-size: 10pt;
            width: auto; height: auto; font-weight: 500; }
        .uda-add-uda:hover { filter: brightness(1.1); }
        .uda-add-row-tr td { background: var(--fm-risdoc-panel-bg, #f5f7fa); padding: 10px; }
        .uda-input { min-height: 60px; }
        tfoot td { padding: 8px; background: var(--fm-risdoc-panel-bg, #e9f5f9); }
        .actions { text-align: center; white-space: nowrap; }
        button {
            width: 28px; height: 28px; border: none; border-radius: 50%;
            font-size: 16px; cursor: pointer; margin: 0 2px; color: #fff;
        }
        button.add { background: linear-gradient(135deg,
            var(--fm-risdoc-action-success, #4caf50),
            var(--fm-risdoc-action-success-hover, #81c784)); }
        button.rm  { background: linear-gradient(135deg,
            var(--fm-risdoc-action-danger-hover, #f44336),
            var(--fm-risdoc-action-danger, #e57373)); }
    `;
    constructor() {
        super();
        this.rows = [];
        this.state = {};
        this._udaData = null;
        this._udaLastKey = "";
        this._sourceTitles = [];
    }
    connectedCallback() {
        super.connectedCallback();
        if (!this.rows.length) this._ensureMinRows();
        this._maybeLoadUda();
    }
    /**
     * Se il parent resetta .rows=[] a seguito di un re-render (es. sidebar
     * state change), ri-popola con default/labeled rows. Altrimenti perderemmo
     * gli input statici (studenti_table labels) ad ogni cambio di state.
     */
    updated(changed) {
        if (changed.has("rows") && (!this.rows || !this.rows.length)) {
            this._ensureMinRows();
        }
        if (changed.has("state") || changed.has("section")) {
            this._maybeLoadUda();
        }
    }

    /**
     * UDA-build mode: se schema ha `uda_source: { folder: "..." }`, fetcha
     * il JSON conoscenze e raggruppa per N_uda. Produce una riga per ogni
     * gruppo con modulo-checkboxes, discipline, periodo, ore totali.
     */
    _maybeLoadUda() {
        const src = this.section?.uda_source;
        if (!src) return;
        const st = this.state || {};
        const folder = typeof src === "string" ? src : src.folder;
        const ind = st.indirizzo, cls = st.classe, mat = st.disciplina;
        if (!folder || !ind || !cls || !mat) return;
        const url = `/api/risdoc/curriculum-options?dataset=${encodeURIComponent(folder)}`
            + `&indirizzo=${encodeURIComponent(ind)}&classe=${encodeURIComponent(cls)}`
            + `&materia=${encodeURIComponent(mat)}`;
        if (url === this._udaLastKey) return;
        this._udaLastKey = url;
        fetch(url, { credentials: "same-origin" })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .then(data => {
                this._udaData = this._buildUdaRows(data);
                // Cache argomenti da JSON (anche se l'utente rimuove righe restano disponibili)
                this._sourceTitles = Array.isArray(data)
                    ? Array.from(new Set(data.map(i => i?.titolo).filter(Boolean))).sort()
                    : [];
            })
            .catch(() => { this._udaData = null; this._sourceTitles = []; });
    }

    /**
     * Raggruppa l'array JSON conoscenze per `N_uda` → produce una riga per UDA:
     *   { N_uda, moduli: [{titolo, checked}], altre_disc, periodo, num_ore }
     */
    _buildUdaRows(data) {
        if (!Array.isArray(data)) return [];
        const groups = {};
        for (const item of data) {
            const n = item.N_uda;
            if (!n) continue;
            if (!groups[n]) groups[n] = { N_uda: n, num_ore: 0, periodi: new Set(), altre_disc: new Set(), moduli: [] };
            if (item.check_uda === true) {
                const h = parseInt(item.num_ore, 10);
                if (!isNaN(h) && h > 0) groups[n].num_ore += h;
            }
            if (item.periodo) groups[n].periodi.add(item.periodo);
            if (item.altre_disc) item.altre_disc.split(",").forEach(d => { const dd = d.trim(); if (dd) groups[n].altre_disc.add(dd); });
            if (item.titolo) groups[n].moduli.push({ titolo: item.titolo, checked: !!item.check_uda });
        }
        return Object.keys(groups).sort((a, b) => parseInt(a, 10) - parseInt(b, 10)).map(k => ({
            N_uda: groups[k].N_uda,
            moduli: groups[k].moduli,
            altre_disc: Array.from(groups[k].altre_disc).join(", "),
            periodo: Array.from(groups[k].periodi).join(" / "),
            num_ore: groups[k].num_ore,
        }));
    }
    _ensureMinRows() {
        // Label-rows mode: schema ha `rows` con `{label, field, input}` → statico
        const lr = this.section?.rows;
        if (Array.isArray(lr) && lr.length && lr[0]?.label !== undefined) {
            this.rows = lr.map(r => ({ __label: r.label, __field: r.field || "", __input: r.input || "text", value: "" }));
            return;
        }
        const dr = this.section?.default_rows;
        if (Array.isArray(dr)) {
            if (this.rows.length < dr.length) this.rows = dr.map(r => ({ ...r }));
        } else {
            const min = dr || 1;
            if (this.rows.length < min) this.rows = [...this.rows, ...Array(min - this.rows.length).fill(null).map(() => ({}))];
        }
    }

    _isLabeledMode() {
        const lr = this.section?.rows;
        return Array.isArray(lr) && lr.length && lr[0]?.label !== undefined;
    }
    _updateCell(rIdx, key, value) {
        const rows = this.rows.slice();
        rows[rIdx] = { ...(rows[rIdx] || {}), [key]: value };
        this.rows = rows;
        this.dispatchEvent(new CustomEvent("fm:value-change", { detail: { name: this.section?.name, value: rows }, bubbles: true, composed: true }));
    }
    _addRow() { this.rows = [...this.rows, {}]; }
    _rmRow(i) { this.rows = this.rows.filter((_, x) => x !== i); this._ensureMinRows(); }
    render() {
        const s = this.section || {};
        const rawCols = s.columns || [{ key: "col1", label: "Colonna 1" }];
        const cols = rawCols.map((c, i) => typeof c === 'string' ? { key: c, label: c } : c);
        const labeled = this._isLabeledMode();
        const udaMode = !!this._udaData;
        return html`
            ${s.title ? html`<div class="section-header">${s.title}</div>` : ""}
            <table>
                <thead><tr>
                    ${cols.map(c => html`<th>${c.label || c.key}</th>`)}
                    ${labeled || udaMode ? "" : html`<th></th>`}
                </tr></thead>
                <tbody>
                    ${udaMode ? this._renderUdaRows() :
                      labeled ? this.rows.map((row, i) => html`
                        <tr>
                            <td class="label-cell">${row.__label}</td>
                            <td><input type=${row.__input || "text"} class=${row.__field || ""}
                                .value=${row.value || ""}
                                @input=${e => this._updateCell(i, "value", e.target.value)}></td>
                        </tr>
                    `) : this.rows.map((row, i) => html`
                        <tr>
                            ${cols.map(c => html`<td><textarea .value=${row?.[c.key] || ""}
                                @input=${e => this._updateCell(i, c.key, e.target.value)}></textarea></td>`)}
                            <td class="actions">
                                <button class="rm" @click=${() => this._rmRow(i)} title="Rimuovi">-</button>
                                <button class="add" @click=${() => this._addRow()} title="Aggiungi">+</button>
                            </td>
                        </tr>
                    `)}
                </tbody>
                ${udaMode ? html`<tfoot><tr>
                    <td colspan="4" style="text-align:right;font-weight:bold;">Totale ore:</td>
                    <td style="font-weight:bold;"><strong>${this._udaTotalHours()}</strong></td>
                </tr></tfoot>` : ""}
            </table>
        `;
    }

    _udaTotalHours() {
        return (this._udaData || []).reduce((a, r) => a + (r.num_ore || 0), 0);
    }

    _renderUdaRows() {
        const allModuli = this._allUniqueModuli();
        return [
            ...(this._udaData || []).map((row, i) => html`
                <tr>
                    <td>
                        <textarea class="uda-input" .value=${String(row.N_uda || "")}
                            @input=${e => this._updateUda(i, "N_uda", e.target.value)}></textarea>
                        <button class="uda-rm-row" title="Rimuovi UDA"
                            @click=${() => this._removeUdaRow(i)}>✕</button>
                    </td>
                    <td>
                        <div class="uda-moduli">
                            ${row.moduli.map((m, mi) => html`
                                <label class="uda-mod-item">
                                    <input type="checkbox" .checked=${m.checked}
                                        @change=${e => this._updateUdaModulo(i, mi, e.target.checked)}>
                                    <span>${m.titolo}</span>
                                    ${m.__custom ? html`<button class="uda-mod-rm" title="Elimina argomento"
                                        @click=${() => this._removeUdaModulo(i, mi)}>✕</button>` : ""}
                                </label>
                            `)}
                        </div>
                        <div class="uda-add-row">
                            <select class="uda-add-select"
                                @change=${e => { if (e.target.value) { this._addUdaModulo(i, e.target.value); e.target.value = ""; } }}>
                                <option value="">+ Aggiungi argomento noto…</option>
                                ${allModuli
                                    .filter(t => !row.moduli.some(m => m.titolo === t))
                                    .map(t => html`<option value=${t}>${t}</option>`)}
                            </select>
                            <input class="uda-custom-input" type="text" placeholder="+ nuovo argomento…"
                                @keydown=${e => { if (e.key === "Enter" && e.target.value.trim()) {
                                    this._addUdaModulo(i, e.target.value.trim(), true); e.target.value = "";
                                } }}>
                        </div>
                    </td>
                    <td><textarea class="uda-input" .value=${row.altre_disc || ""}
                        @input=${e => this._updateUda(i, "altre_disc", e.target.value)}></textarea></td>
                    <td><textarea class="uda-input" .value=${row.periodo || ""}
                        @input=${e => this._updateUda(i, "periodo", e.target.value)}></textarea></td>
                    <td><textarea class="uda-input" .value=${String(row.num_ore || 0)}
                        @input=${e => this._updateUda(i, "num_ore", parseInt(e.target.value, 10) || 0)}></textarea></td>
                </tr>
            `),
            html`
                <tr class="uda-add-row-tr">
                    <td colspan="5" style="text-align:center;">
                        <button class="uda-add-uda" @click=${() => this._addUdaRow()}>+ Aggiungi UDA</button>
                    </td>
                </tr>
            `,
        ];
    }

    /**
     * Lista "argomenti noti" per il dropdown: unione di
     *  1) tutti i titoli nel JSON conoscenze originale (preservati anche
     *     se l'utente rimuove righe);
     *  2) custom titoli aggiunti runtime e ancora presenti nelle righe.
     */
    _allUniqueModuli() {
        const set = new Set(this._sourceTitles || []);
        for (const row of (this._udaData || [])) {
            for (const m of (row.moduli || [])) {
                if (m.titolo) set.add(m.titolo);
            }
        }
        return Array.from(set).sort();
    }

    _updateUda(idx, key, val) {
        const copy = (this._udaData || []).slice();
        copy[idx] = { ...copy[idx], [key]: val };
        this._udaData = copy;
        this._emitUda();
    }
    _updateUdaModulo(rowIdx, modIdx, checked) {
        const copy = (this._udaData || []).slice();
        const mods = copy[rowIdx].moduli.slice();
        mods[modIdx] = { ...mods[modIdx], checked };
        copy[rowIdx] = { ...copy[rowIdx], moduli: mods };
        this._udaData = copy;
        this._emitUda();
    }
    _addUdaModulo(rowIdx, titolo, isCustom = false) {
        const copy = (this._udaData || []).slice();
        const mods = (copy[rowIdx].moduli || []).slice();
        if (mods.some(m => m.titolo === titolo)) return;
        mods.push({ titolo, checked: true, ...(isCustom ? { __custom: true } : {}) });
        copy[rowIdx] = { ...copy[rowIdx], moduli: mods };
        this._udaData = copy;
        this._emitUda();
    }
    _removeUdaModulo(rowIdx, modIdx) {
        const copy = (this._udaData || []).slice();
        const mods = copy[rowIdx].moduli.slice();
        mods.splice(modIdx, 1);
        copy[rowIdx] = { ...copy[rowIdx], moduli: mods };
        this._udaData = copy;
        this._emitUda();
    }
    _addUdaRow() {
        const copy = (this._udaData || []).slice();
        // N_uda successivo
        const maxN = copy.reduce((a, r) => Math.max(a, parseInt(r.N_uda, 10) || 0), 0);
        copy.push({ N_uda: String(maxN + 1), moduli: [], altre_disc: "", periodo: "", num_ore: 0 });
        this._udaData = copy;
        this._emitUda();
    }
    async _removeUdaRow(idx) {
        if (!await window.FM.Dialog.confirm(`Rimuovere l'UDA ${this._udaData[idx]?.N_uda}?`)) return;
        const copy = (this._udaData || []).slice();
        copy.splice(idx, 1);
        this._udaData = copy;
        this._emitUda();
    }
    _emitUda() {
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            detail: { name: this.section?.name, value: this._udaData },
            bubbles: true, composed: true,
        }));
    }
}
if (!customElements.get("fm-risdoc-dynamic-table")) customElements.define("fm-risdoc-dynamic-table", FmRisdocDynamicTable);
