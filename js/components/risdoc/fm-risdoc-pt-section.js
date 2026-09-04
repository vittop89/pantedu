/**
 * <fm-risdoc-pt-section> — Phase 24.6 + 24.9.
 *
 * Renderizza un'intera section del schema risdoc come singolo
 * <fm-risdoc-pt-editor>, con default PT AST generato da section-to-pt.js.
 *
 * Phase 24.9 — options_source fetch:
 *   Se la section contiene items con `options_source` (dynamic fetch da
 *   JSON basato su state.indirizzo/classe/disciplina), questi vengono
 *   pre-fetchati e passati a sectionSchemaToPt come `dynamicOpts` map.
 *   Al cambio di state (dropdown header) → re-fetch + re-render editor.
 *
 * Opt-in: section con `"pt_unified": true` nel schema → usa questo wrapper.
 */

import { LitElement, html, css } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { repeat } from "https://cdn.jsdelivr.net/npm/lit@3/directives/repeat.js/+esm";
import { ensurePtEditorLoaded } from "./_pt-loader.js";
import { sectionSchemaToPt, checkboxGroupToPt, dehydrateDynamicOptions } from "../../modules/risdoc/pt/section-to-pt.js";
import { fetchSchemaOptions, collectOptionsSourceItems } from "./_options-fetcher.js";

export class FmRisdocPtSection extends LitElement {
    static properties = {
        section:        { type: Object },
        fields:         { type: Object },
        state:          { type: Object },
        subsections:    { type: Array },   // ADR-026 — sottosezioni annidate (card-in-card)
        compact:        { type: Boolean }, // Phase 24.10b — forward a pt-editor
        _ready:         { state: true },
        _error:         { state: true },
        _dynamicOpts:   { state: true },
        _loadingOpts:   { state: true },
        _collapsed:     { state: true }, // Phase 25.E13 — collapse/expand state
    };

    static styles = css`
        :host { display: block; margin: 15px 0; color: var(--fm-risdoc-text, #1e293b); }
        .placeholder-loading {
            padding: 1em;
            border: 1px dashed var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 4px;
            text-align: center;
            color: var(--fm-risdoc-text-muted, #94a3b8);
            font-size: 0.9em;
        }
        .hint-select-ctx {
            padding: 8px 12px;
            margin-bottom: 6px;
            background: var(--fm-risdoc-warning-bg, #fef3c7);
            border: 1px solid var(--fm-risdoc-warning-fg, #d97706);
            border-radius: 4px;
            color: var(--fm-risdoc-warning-fg, #92400e);
            font-size: 0.9em;
        }
        .error {
            padding: 0.8em 1em;
            background: var(--fm-risdoc-error-bg, #fee2e2);
            border: 1px solid var(--fm-risdoc-error-border, #fca5a5);
            border-radius: 4px;
            color: var(--fm-risdoc-error-fg, #b91c1c);
            font-size: 0.9em;
        }
        /* Phase 25.E14 — collapse wrapper.
           Quando expanded: il sectionHeader del PT rimane visibile con tutte
           le sue funzionalità (titolo editabile, livello H1-H4, toggle Box).
           Il toggle collapse è un piccolo bottone floating top-right del
           wrapper, non occupa flow e non duplica il titolo.
           Quando collapsed: il wrapper diventa una barra full-width con
           icona + titolo (read-only, derivato da s.title), unico modo per
           riespandere senza usare la toolbar global. */
        .fm-section-wrap {
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            border-radius: 6px;
            overflow: hidden;
            background: var(--fm-risdoc-bg, #fff);
            position: relative;
        }
        .fm-section-wrap.is-collapsed {
            background: var(--fm-risdoc-toolbar-bg, #f8fafc);
        }
        /* ADR-026 — sottosezioni annidate (card-in-card): indentate, leggermente
           più piccole, separate dal contenuto proprio della sezione padre. */
        .fm-subsections {
            margin: 12px 0 4px;
            padding-left: 14px;
            border-left: 2px solid var(--fm-risdoc-btn-border, #cbd5e1);
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .fm-subsection { margin: 0; font-size: 0.97em; }
        /* Variant FULL (collapsed): barra full-width cliccabile con titolo */
        .fm-section-toggle--full {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 8px;
            background: transparent;
            border: 0;
            padding: 10px 14px;
            cursor: pointer;
            text-align: left;
            font: inherit;
            color: var(--fm-risdoc-accent, #1e40af);
            transition: background .15s ease;
        }
        .fm-section-toggle--full:hover { background: rgba(30, 64, 175, .04); }
        .fm-section-toggle-title {
            flex: 1;
            font-weight: 600;
            font-size: 14px;
        }
        /* Variant FLOATING (expanded): bottoncino top-right, no duplica titolo */
        .fm-section-toggle--floating {
            position: absolute;
            top: 6px;
            right: 8px;
            z-index: 5;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            background: var(--fm-risdoc-btn-bg, rgba(255,255,255,.85));
            color: var(--fm-risdoc-text-muted, #475569);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            line-height: 1;
            opacity: 0.55;
            transition: opacity .15s ease, background .15s ease;
        }
        .fm-section-toggle--floating:hover {
            opacity: 1;
            background: var(--fm-risdoc-btn-hover, #f1f5f9);
        }
        /* Cluster azioni sezione (sposta su/giù · duplica · elimina) floating a
           sinistra del toggle collapse. */
        .fm-section-actions--floating {
            position: absolute;
            top: 6px;
            right: 40px;
            z-index: 5;
            display: inline-flex;
            gap: 3px;
        }
        .fm-section-act {
            width: 26px;
            height: 26px;
            border-radius: 50%;
            border: 1px solid var(--fm-risdoc-btn-border, #cbd5e1);
            background: var(--fm-risdoc-btn-bg, rgba(255,255,255,.85));
            color: var(--fm-risdoc-text-muted, #475569);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            line-height: 1;
            opacity: 0.55;
            transition: opacity .15s ease, background .15s ease;
        }
        .fm-section-act:hover {
            opacity: 1;
            background: var(--fm-risdoc-btn-hover, #f1f5f9);
        }
        .fm-section-act--danger {
            border-color: var(--fm-risdoc-error-border, #fecaca);
            color: var(--fm-risdoc-error-fg, #b91c1c);
        }
        .fm-section-act--danger:hover { background: var(--fm-risdoc-error-bg, #fee2e2); }
        .fm-section-toggle-icon {
            font-size: 14px;
            min-width: 14px;
            display: inline-flex;
            justify-content: center;
            color: var(--fm-risdoc-text-muted, #475569);
        }
        .fm-section-body { padding: 0; }
    `;

    constructor() {
        super();
        this.section = {};
        this.fields = {};
        this.state = {};
        this.subsections = [];
        this._ready = false;
        this._error = "";
        this._dynamicOpts = {};
        this._loadingOpts = false;
        this._lastStateKey = "";
        this._dynResetSelections = false;
        this._collapsed = false;
    }

    async connectedCallback() {
        super.connectedCallback();
        try {
            await ensurePtEditorLoaded();
            this._ready = true;
            await this._maybeLoadOptionsSource();
        } catch (e) {
            this._error = e.message || String(e);
        }
        // Phase 25.E13 — listener globale per collapse/expand all triggerato
        // dalla toolbar pt-toolbar (bottone "Collassa tutto" / "Espandi tutto").
        this._onCollapseAll = (e) => {
            if (e?.detail && typeof e.detail.collapsed === "boolean") {
                this._collapsed = e.detail.collapsed;
            }
        };
        document.addEventListener("fm:collapse-all-sections", this._onCollapseAll);
    }

    disconnectedCallback() {
        if (this._onCollapseAll) {
            document.removeEventListener("fm:collapse-all-sections", this._onCollapseAll);
        }
        super.disconnectedCallback();
    }

    _toggleCollapsed() {
        this._collapsed = !this._collapsed;
        // Notifica la toolbar così aggiorna label "Collassa/Espandi tutto"
        this.dispatchEvent(new CustomEvent("fm:section-collapse-change", {
            detail: { collapsed: this._collapsed, name: this._sectionName() },
            bubbles: true, composed: true,
        }));
    }

    /** Richiede l'eliminazione di questa sezione (e sottosezioni). Il document
     *  host (fm-pt-document) gestisce conferma + rimozione dall'albero. */
    _onDelete(e) {
        e.stopPropagation();
        this.dispatchEvent(new CustomEvent("fm:delete-pt-section", {
            detail: { name: this._sectionName(), title: this.section?.title || "" },
            bubbles: true, composed: true,
        }));
    }

    /** Sposta questa (sotto)sezione su/giù tra le sorelle. dir: -1 su, +1 giù. */
    _onMove(dir) {
        this.dispatchEvent(new CustomEvent("fm:move-pt-section", {
            detail: { name: this._sectionName(), dir },
            bubbles: true, composed: true,
        }));
    }

    /** Duplica questa (sotto)sezione (con sottosezioni) come sorella seguente. */
    _onDuplicate(e) {
        e.stopPropagation();
        this.dispatchEvent(new CustomEvent("fm:duplicate-pt-section", {
            detail: { name: this._sectionName() },
            bubbles: true, composed: true,
        }));
    }

    updated(changed) {
        // State change (es. classe/indirizzo/disciplina) → re-fetch options
        if (changed.has("state") || changed.has("section")) {
            this._maybeLoadOptionsSource();
        }
    }

    /**
     * Walk section schema, trova tutti gli items con options_source,
     * fetcha options per ognuno, accumula in this._dynamicOpts (keyed
     * su JSON.stringify(options_source)). Cache su state key per evitare
     * re-fetch ridondanti su updated() triggers spurii.
     */
    /**
     * ADR-026 Step 5 fix — raccoglie options_source PORTATE dai nodi PT
     * (checkboxGroup carry). Nelle sezioni pt_unified (onepath) lo schema items
     * non c'è, ma il body_pt porta options_source → vanno fetchate comunque,
     * altrimenti le card a opzioni dinamiche (abilità/conoscenze/competenze)
     * restano vuote. Ritorna pseudo-item {name, options_source} per il fetch.
     */
    _ptOptionsSourceItems() {
        const out = [];
        const seen = new Set();
        const scan = (blocks) => {
            for (const b of (Array.isArray(blocks) ? blocks : [])) {
                if (b && b._type === "checkboxGroup" && b.options_source) {
                    const k = JSON.stringify(b.options_source);
                    if (!seen.has(k)) { seen.add(k); out.push({ name: b.name || "", options_source: b.options_source }); }
                }
            }
        };
        const s = this.section || {};
        if (Array.isArray(s.default)) scan(s.default);
        for (const v of Object.values(this.fields || {})) if (Array.isArray(v)) scan(v);
        return out;
    }

    async _maybeLoadOptionsSource() {
        const s = this.section || {};
        const items = [...collectOptionsSourceItems(s), ...this._ptOptionsSourceItems()];
        if (items.length === 0) return;

        const stateKey = JSON.stringify({
            i: this.state?.indirizzo ?? "",
            c: this.state?.classe ?? "",
            s: this.state?.sezione ?? "",
            d: this.state?.disciplina ?? "",
        });
        const prevStateKey = this._lastStateKey;
        if (stateKey === prevStateKey) return;
        this._lastStateKey = stateKey;
        // Se lo STATO è cambiato (cambio classe/indirizzo/materia nella sidebar,
        // non il primo load), i gruppi dinamici vanno ri-risolti dai DEFAULT del
        // NUOVO framework: niente carry delle selezioni del vecchio stato, che
        // lascerebbe i nuovi item deselezionati e i vecchi appesi come "custom".
        const stateChanged = prevStateKey !== "";

        this._loadingOpts = true;
        const next = {};
        const results = await Promise.all(items.map(async (it) => {
            const key = JSON.stringify(it.options_source);
            try {
                const opts = await fetchSchemaOptions(it, this.state || {});
                next[key] = opts;
                return { key, name: it.name, count: opts.length, err: null };
            } catch (e) {
                next[key] = [];
                return { key, name: it.name, count: 0, err: e.message };
            }
        }));
        this._dynResetSelections = stateChanged;
        this._dynamicOpts = next;
        this._loadingOpts = false;
        // Log leggibile inline (non collapsed come Object)
        const fmt = results.map(r => {
            const base = `  "${r.name}" key=${r.key} → count=${r.count}`;
            return r.err ? base + ` ERR=${r.err}` : base;
        }).join("\n");
        console.log(
            `[pt-section] "${s.title || s.name || "?"}" state=${JSON.stringify(this.state)}\n${fmt}`,
        );
    }

    /** Idrata i nodi checkboxGroup (body_pt) con options_source: se le opzioni
     *  fetchate (_dynamicOpts) sono più degli items presenti, ricostruisce il
     *  nodo via checkboxGroupToPt preservando gli stati selezionati. Idempotente
     *  (carry name/fieldType/options_source rimesso) + loop-safe (solo se cresce). */
    _hydratePtDynamicOptions(pt) {
        if (!Array.isArray(pt)) return pt;
        const dyn = this._dynamicOpts || {};
        if (!Object.values(dyn).some((v) => Array.isArray(v) && v.length)) return pt;
        // 2026-05-28 — IDEMPOTENZA: de-hydrate prima (collassa cg multipli con
        // stesso options_source + rimuove block-label di gruppo + para vuoti) →
        // poi re-expand. Senza questo, ogni cg-subgruppo veniva rebuilt → tutti
        // i 4 assi venivano emessi per OGNI cg input → growth moltiplicativa
        // ("Asse dei Linguaggi" duplicato 6 volte nel render).
        pt = dehydrateDynamicOptions(pt);
        const out = [];
        for (const b of pt) {
            if (b && b._type === "checkboxGroup" && b.options_source) {
                const opts = dyn[JSON.stringify(b.options_source)];
                const curItems = Array.isArray(b.items) ? b.items : [];
                if (Array.isArray(opts) && opts.length > 0) {
                    // reset = cambio stato (classe/indirizzo): usa i default del
                    // nuovo framework, ignora le selezioni del vecchio stato.
                    const reset = this._dynResetSelections === true;
                    const selected = reset ? [] : curItems
                        .filter((it) => it.state === "x" || it.checked)
                        .map((it) => it.value ?? it.label)
                        .filter(Boolean);
                    const field = {
                        name: b.name || "", type: "checkbox-group",
                        options_source: b.options_source,
                    };
                    const rebuilt = checkboxGroupToPt(field, selected, dyn);
                    if (Array.isArray(rebuilt) && rebuilt.length) {
                        // preserva renderMode + columns (impaginazione) sui nodi ricostruiti
                        if (b.renderMode) for (const n of rebuilt) if (n._type === "checkboxGroup") n.renderMode = b.renderMode;
                        if (b.columns != null) for (const n of rebuilt) if (n._type === "checkboxGroup") n.columns = b.columns;
                        // IBRIDO (ADR-026) — preserva le voci CUSTOM selezionate dal
                        // docente NON presenti nel framework istituzionale fetchato:
                        // checkboxGroupToPt emette solo le opzioni fetchate, quindi
                        // le aggiunte personali andrebbero perse → le ri-appendiamo.
                        const fetchedVals = new Set();
                        for (const n of rebuilt) if (n._type === "checkboxGroup")
                            for (const it of (n.items || [])) { fetchedVals.add(it.value); fetchedVals.add(it.label); }
                        // SOLO le voci esplicitamente custom (aggiunte dal docente,
                        // flag `custom:true`): NON ri-appendere item del framework che
                        // non matchano per differenze label/versione → altrimenti
                        // bloat (es. STATISTICA gonfiata con voci degli altri gruppi).
                        const customSel = reset ? [] : curItems.filter((it) =>
                            it.custom === true
                            && (it.state === "x" || it.checked)
                            && !fetchedVals.has(it.value) && !fetchedVals.has(it.label));
                        if (customSel.length) {
                            const lastCg = [...rebuilt].reverse().find((n) => n._type === "checkboxGroup");
                            if (lastCg) lastCg.items = [...(lastCg.items || []),
                                ...customSel.map((it) => ({ state: "x", label: String(it.label ?? it.value ?? ""), value: String(it.value ?? it.label ?? ""), custom: true }))];
                        }
                        out.push(...rebuilt);
                        continue;
                    }
                }
            }
            out.push(b);
        }
        // Il reset vale solo per la prima idratazione dopo il cambio stato:
        // i render successivi (stesso stato) tornano a preservare le selezioni.
        this._dynResetSelections = false;
        return out;
    }

    _sectionName() {
        const s = this.section || {};
        if (s.name) return String(s.name);
        if (s.title) {
            return "section_" + String(s.title).toLowerCase()
                .replace(/[^a-z0-9]+/g, "_")
                .replace(/^_+|_+$/g, "")
                .slice(0, 60);
        }
        return "section";
    }

    _onPtChange(e) {
        const pt = e.detail?.value;
        if (!Array.isArray(pt)) return;
        this.dispatchEvent(new CustomEvent("fm:value-change", {
            bubbles: true, composed: true,
            detail: { name: this._sectionName(), value: pt },
        }));
        // Se è comparsa una NUOVA options_source (es. gruppo "collegato/Automatico"
        // appena inserito) non ancora risolta, ri-risolvi subito così si popola
        // senza aspettare un cambio di selettori (la cache è per-stato).
        const opts = this._dynamicOpts || {};
        const hasNew = pt.some((b) => b && b._type === "checkboxGroup" && b.options_source
            && !(JSON.stringify(b.options_source) in opts));
        if (hasNew) { this._lastStateKey = ""; this._maybeLoadOptionsSource(); }
    }

    /** Conta fields con options_source (recursively). */
    _countOptionsSourceItems(section) {
        if (!section || typeof section !== "object") return 0;
        let n = section.options_source ? 1 : 0;
        if (Array.isArray(section.items)) {
            for (const it of section.items) n += this._countOptionsSourceItems(it);
        }
        return n;
    }

    /** True se la section (o children) usa options_source e state selettori richiesti mancano. */
    _needsContextHint() {
        const s = this.section || {};
        const items = collectOptionsSourceItems(s);
        if (items.length === 0) return false;
        // Check if any item uses folder mode (che richiede state completo)
        const needsState = items.some((it) => {
            const os = it.options_source;
            return typeof os === "string" || (os && typeof os === "object" && os.folder);
        });
        if (!needsState) return false;
        const st = this.state || {};
        return !(st.indirizzo && st.classe && st.disciplina);
    }

    render() {
        const s = this.section || {};
        if (this._error) {
            return html`<div class="error">⚠ ${this._error}</div>`;
        }
        if (!this._ready) {
            return html`<div class="placeholder-loading">Caricamento editor unificato…</div>`;
        }

        const hint = this._needsContextHint()
            ? html`<div class="hint-select-ctx">
                💡 Alcune opzioni dipendono da <strong>indirizzo</strong>, <strong>classe</strong>,
                <strong>disciplina</strong>. Seleziona i valori nell'intestazione per caricare
                le opzioni disponibili.
              </div>`
            : "";

        const name = this._sectionName();
        const saved = this.fields?.[name];
        const savedIsValidPt = Array.isArray(saved) && saved.length > 0
            && saved.every((b) => b && typeof b === "object" && "_type" in b);
        // Phase 24.18 — saved "stale" detection robusta: quando schema ha
        // options_source fields e dynamicOpts ha più data di quella
        // riflessa nel saved, genera candidate da schema+dynamicOpts e
        // confronta cb group counts. Usa candidate solo se produce
        // strettamente più checkboxGroup del saved (loop-safe: dopo rigen
        // saved avrà stesso count → candidateCbGroups === savedCbGroups
        // → usa saved).
        const optsSourceCount = this._countOptionsSourceItems(s);
        const savedCbGroups = savedIsValidPt
            ? saved.filter((b) => b && b._type === "checkboxGroup").length
            : 0;
        const dynOptsHasData = Object.values(this._dynamicOpts || {})
            .some((v) => Array.isArray(v) && v.length > 0);
        let pt;
        let source;
        if (savedIsValidPt && optsSourceCount > 0 && dynOptsHasData
            && savedCbGroups < optsSourceCount) {
            const candidate = sectionSchemaToPt(s, this.fields || {}, this._dynamicOpts || {});
            const candidateCbGroups = candidate.filter((b) => b && b._type === "checkboxGroup").length;
            pt = candidateCbGroups > savedCbGroups ? candidate : saved;
            source = candidateCbGroups > savedCbGroups ? "candidate" : "saved";
        } else {
            pt = savedIsValidPt
                ? saved
                : sectionSchemaToPt(s, this.fields || {}, this._dynamicOpts || {});
            source = savedIsValidPt ? "saved" : "schema";
        }
        // ADR-026 Step 5 fix — idrata i checkboxGroup del body_pt che PORTANO
        // options_source ma hanno items vuoti/parziali (onepath: sezioni
        // pt_unified senza schema items). Riusa checkboxGroupToPt + _dynamicOpts
        // (fetchate da _maybeLoadOptionsSource via _ptOptionsSourceItems).
        pt = this._hydratePtDynamicOptions(pt);
        // Source di pt (debug): "saved" significa fields[name] sovrascrive
        // schema; "schema" significa sectionSchemaToPt; "candidate" significa
        // expand con dynamic options. Gated dietro window.FM_DEBUG.
        if (typeof window !== "undefined" && window.FM_DEBUG) {
            console.log(`[pt-section] "${s.title || name}" source=${source} name=${name} fields_keys=${Object.keys(this.fields || {}).length}`);
        }

        // Phase 24.15 — log render solo su cambio significativo (dedup spam console)
        if (pt.length > 0) {
            const typeCounts = pt.reduce((acc, b) => {
                acc[b._type] = (acc[b._type] || 0) + 1;
                return acc;
            }, {});
            const cbCount = pt
                .filter((b) => b._type === "checkboxGroup")
                .reduce((acc, b) => acc + (b.items?.length || 0), 0);
            const sig = `${pt.length}|${JSON.stringify(typeCounts)}|${cbCount}`;
            if (this._lastRenderSig !== sig) {
                this._lastRenderSig = sig;
                const dynKeys = Object.keys(this._dynamicOpts || {});
                console.log(
                    `[pt-section] "${s.title || name}" render PT: ${pt.length} blocks `
                    + `${JSON.stringify(typeCounts)} (${cbCount} checkbox items total) `
                    + `[dynamicOpts: ${dynKeys.length} keys]`,
                );
            }
        }

        // fields picker: keys di state + schema selectors
        const suggested = new Set();
        if (this.state) Object.keys(this.state).forEach((k) => suggested.add(k));
        if (Array.isArray(s.selectors)) s.selectors.forEach((k) => suggested.add(k));

        // Il titolo della barra collassata DEVE riflettere il rename fatto
        // nell'editor: viene dal sectionHeader del PT corrente (saved/
        // _sectionValues), non da s.title (nodo albero) che resta stantio.
        const headerBlock = Array.isArray(pt) && pt[0] && pt[0]._type === "sectionHeader" ? pt[0] : null;
        const title = (headerBlock && headerBlock.title) || s.title || s.label || name;
        // Phase 25.E14 — quando expanded, il PT viene passato INTATTO al
        // pt-editor: il sectionHeader interno mantiene titolo editabile,
        // toggle livello (H1-H4) e checkbox "Box". Il collapse-toggle è un
        // bottoncino floating top-right, non duplica il titolo.
        // Quando collapsed, il PT è nascosto e mostriamo solo una barra
        // full-width con titolo derivato da s.title (read-only).
        return html`
            <div class="fm-section-wrap ${this._collapsed ? "is-collapsed" : ""}">
                <div class="fm-section-actions--floating">
                    <button type="button" class="fm-section-act"
                            @click=${() => this._onMove(-1)}
                            title="Sposta su (prima della sorella precedente)"
                            aria-label="Sposta su">↑</button>
                    <button type="button" class="fm-section-act"
                            @click=${() => this._onMove(1)}
                            title="Sposta giù (dopo la sorella successiva)"
                            aria-label="Sposta giù">↓</button>
                    <button type="button" class="fm-section-act"
                            @click=${this._onDuplicate}
                            title="Duplica questa sezione (con le sue sottosezioni)"
                            aria-label="Duplica sezione">⧉</button>
                    <button type="button" class="fm-section-act fm-section-act--danger"
                            @click=${this._onDelete}
                            title="Elimina questa sezione (e le sue sottosezioni)"
                            aria-label="Elimina sezione">🗑</button>
                </div>
                ${this._collapsed ? html`
                    <button type="button" class="fm-section-toggle--full"
                            @click=${this._toggleCollapsed}
                            aria-expanded="false"
                            title="Espandi sezione">
                        <span class="fm-section-toggle-icon">▸</span>
                        <span class="fm-section-toggle-title">${title}</span>
                    </button>
                ` : html`
                    <button type="button" class="fm-section-toggle--floating"
                            @click=${this._toggleCollapsed}
                            aria-expanded="true"
                            title="Comprimi sezione">▾</button>
                    <div class="fm-section-body">
                        ${hint}
                        <fm-risdoc-pt-editor
                            .value=${pt}
                            .fields=${[...suggested]}
                            .compact=${this.compact}
                            ?has-subsections=${Array.isArray(this.subsections) && this.subsections.length > 0}
                            @pt-change=${this._onPtChange}
                        ></fm-risdoc-pt-editor>
                        ${Array.isArray(this.subsections) && this.subsections.length ? html`
                            <div class="fm-subsections">
                                ${repeat(this.subsections, (sub) => sub.name, (sub) => html`
                                    <fm-risdoc-pt-section
                                        class="fm-subsection"
                                        .section=${sub}
                                        .fields=${this.fields}
                                        .state=${this.state}
                                        .subsections=${sub.children || []}
                                        ?compact=${this.compact}></fm-risdoc-pt-section>
                                `)}
                            </div>
                        ` : ""}
                    </div>
                `}
            </div>
        `;
    }
}

if (!customElements.get("fm-risdoc-pt-section")) {
    customElements.define("fm-risdoc-pt-section", FmRisdocPtSection);
}
