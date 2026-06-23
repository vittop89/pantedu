/**
 * <fm-pt-document> — WebComponent unificato documento PT (ADR-022 + ADR-024).
 *
 * Consolida in UN componente coeso il path "pagina personalizzata" (custom
 * teacher_content): JSON (export/import) + TeX/PDF (ZIP) + HTML sanitizzato.
 *
 * ADR-024 — la topbar è ora resa dal componente stesso con le classi globali
 * `.fm-topbar` / `.fm-topbar__btn` (look identico alla topbar dei modelli
 * istituzionali risdoc). La topbar studio (verifiche) viene tenuta nascosta
 * sulle pagine `.fm-pt-custom-page` (vedi topbar-modern.js). Niente doppia barra.
 *
 * Modi documento:
 *   _mode (view|edit)       — vista HTML ↔ editor PT (toolbar block types).
 *   renderMode (interactive|html) — presentazione PERSISTITA (metadata.render_mode):
 *       interactive → componente pieno (default, editing abilitato);
 *       html        → vista "articolo" sanitizzato (pubblicata). Editing
 *                     disabilitato; il docente può ri-switchare a interactive.
 *
 * SSR-first: il server pre-renderizza il body HTML (PtToHtml) DENTRO il tag
 * (data-ptdoc-ssr) → no-flash + graceful degradation. body_pt caricato lazy
 * solo per edit/export via adapter.
 *
 * Light DOM (createRenderRoot→this): la view eredita CSS globale
 * _pt-page-doc.css (block render) + _pt-document.css (chrome BEM).
 *
 * Attributi:
 *   doc-id       teacher_content id
 *   source       adapter discriminator (default "teacher-content")
 *   can-edit     "1" → abilita edit/save/import + toggle render-mode
 *   title        titolo documento
 *   render-mode  "interactive" (default) | "html"
 */

import { LitElement, html } from "https://cdn.jsdelivr.net/npm/lit@3/+esm";
import { repeat } from "https://cdn.jsdelivr.net/npm/lit@3/directives/repeat.js/+esm";
import { ensurePtEditorLoaded } from "../risdoc/_pt-loader.js";
import { createAdapter } from "./adapters/teacher-content-adapter.js";
import { fetchCsrf } from "../../modules/core/dom-utils.js";
import { studioUrlCombo } from "../risdoc/_options-fetcher.js"; // ADR-030 — parsing URL studio condiviso
import "../risdoc/fm-risdoc-pt-section.js";
import "../risdoc/fm-risdoc-section-header.js";
import "../risdoc/fm-risdoc-section-navigator.js"; // Navigator sezioni (anche custom)
import "../doc-topbar/fm-doc-topbar.js";

const escapeHtml = (s) => String(s ?? "")
    .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;").replace(/'/g, "&#39;");

// Markup navigator sezioni — STESSO dei modelli (server $navigatorSection),
// così custom e modelli condividono il widget. fm-risdoc-section-navigator.js
// lo lega via #section-navigator e scansiona le card fm-risdoc-pt-section.
const NAVIGATOR_HTML = `
<div id="header-controls-container">
  <div id="section-navigator" title="Naviga tra le sezioni" tabindex="0" aria-label="Apri navigatore sezioni">
    <span class="fm-section-nav-icon">📑</span>
    <span class="fm-section-nav-label" data-default-label="Sezioni">Sezioni</span>
    <div id="section-dropdown" role="menu"></div>
  </div>
</div>`;

export class FmPtDocument extends LitElement {
    static properties = {
        docId:      { type: String, attribute: "doc-id" },
        source:     { type: String },
        canEdit:    { type: Boolean, attribute: "can-edit", converter: (v) => v === "1" || v === "true" },
        docTitle:   { type: String, attribute: "title" },
        renderMode: { type: String, attribute: "render-mode" },
        _mode:      { state: true },
        _status:    { state: true },
        _busy:      { state: true },
        _sections:  { state: true }, // rework: card sezioni in edit (come risdoc)
        _includeHeader: { state: true }, // checkbox "Includi intestazione istituto" (custom)
        _includeHeaderHtml: { state: true }, // checkbox "Includi intestazione+selettori nell'HTML statico pubblicato"
        _ternaScoped: { state: true }, // ADR-030 — "un documento, valori per classe" attivo
        // ADR-026 #3 — source="risdoc-template": fm-pt-document fa da shell
        // unica + monta direttamente le card fm-risdoc-pt-section (onepath,
        // motore unico custom↔risdoc).
        templateId:   { type: String, attribute: "template-id" },
        schemaUrl:    { type: String, attribute: "schema-url" },
        initialState: { type: String, attribute: "initial-state" },
        topbarHtml:   { type: String, attribute: "topbar-html" },
        // Estensione topbar SOLO-modelli (Modifica struttura, istanze, admin,
        // navigator): widget istituzionali server-side resi come trailingHtml.
        topbarExtraHtml: { type: String, attribute: "topbar-extra-html" },
        argomento:    { type: String, attribute: "argomento" },
        docRole:      { type: String, attribute: "doc-role" },
        instanceKey:  { type: String, attribute: "instance-key" },
        adminEdit:    { type: Boolean, attribute: "admin-edit", converter: (v) => v === "1" || v === "true" },
        // ADR-026 #3 — _risdocPreview state rimosso (era toggle HTML statico
        // del vecchio motore, ora onepath usa _mode='view'/'edit' + renderMode).
        // Model-sync (fork ↔ master) — popolato dopo enterEdit; null = nessun
        // collegamento a un modello istituzionale (custom puro o risdoc non-fork).
        _modelSync: { state: true },
    };

    createRenderRoot() { return this; }

    constructor() {
        super();
        this.source = "teacher-content";
        this.canEdit = false;
        this.docTitle = "";
        this.renderMode = "interactive";
        this._mode = "view";
        this._status = "";
        this._busy = false;
        this._adapter = null;
        this._serverHtml = "";    // SSR body catturato
        this._clientHtml = null;  // re-render post-save (ptToHtml)
        this._bodyPt = null;      // lazy (edit/export)
        // Rework editor (ADR-024) — l'edit non è più un editor unico ma N
        // <fm-risdoc-pt-section> (STESSA UI/chrome/dark/collapse di risdoc).
        // _sections: array di oggetti `section` STABILI (pt_unified+default)
        // passati alle card; _sectionValues: name→PT corrente (non reactive →
        // nessun rimount durante la digitazione). Il body_pt si ricostruisce
        // concatenando le sezioni in ordine (merge).
        this._sections = [];
        this._sectionValues = {};
        this._sid = 0;            // contatore nomi sezione univoci/stabili
        this._emptyFields = {};   // ref stabili: pt-section non vede cambi fields/state
        this._emptyState = {};
        this.templateId = "";
        this.schemaUrl = "";
        this.initialState = "";
        this.topbarHtml = "";
        this.docRole = "";
        this.instanceKey = "";
        this.adminEdit = false;
        this._includeHeader = true;   // default: intestazione istituto inclusa
        this._includeHeaderHtml = true; // default: intestazione+selettori inclusi nell'HTML statico pubblicato
        this.topbarExtraHtml = "";
        this.argomento = "";
        // ADR-026 #3 — _risdocLoaded/_risdocPreview/_risdocEngine rimossi (dead).
    }

    firstUpdated() {
        // Il markup del navigator è reso da Lit DOPO il run dell'IIFE del
        // navigator → ribindalo (idempotente) appena la topbar esiste.
        this._rebindNavigator();
    }

    /**
     * 2026-05-27 — apertura EDIT-FIRST come i modelli (scelta utente). In
     * `updated` (non `connectedCallback`/`firstUpdated`) perché `can-edit` può
     * riflettersi a proprietà DOPO il primo render: qui catturiamo il momento
     * in cui canEdit diventa true. One-shot via `_editFirstTried`. Vale per il
     * path custom (teacher-content) e il modello onepath; studenti/sola-lettura
     * (canEdit=false) o HTML statico restano in vista.
     */
    updated() {
        if (this._editFirstTried) return;
        // Legge l'ATTRIBUTO can-edit (presente nel markup da subito) invece
        // della proprietà reattiva, che poteva non essere ancora riflessa al
        // primo `updated` → l'edit-first non scattava mai.
        const canEdit = this.canEdit || this.getAttribute("can-edit") === "1";
        if (!canEdit || this.renderMode === "html") return;
        this._editFirstTried = true;
        if (this._mode !== "edit") this._enterEdit();
    }

    /** (Ri)lega il navigator sezioni dopo che il markup è nel DOM. */
    _rebindNavigator() {
        requestAnimationFrame(() => {
            const nav = this.querySelector("#section-navigator");
            if (nav) nav.dataset.fmBound = "";
            window.dispatchEvent(new Event("fm:navigated"));
        });
    }

    /** ADR-026 — true quando questo documento è un MODELLO risdoc. */
    get _isRisdoc() { return this.source === "risdoc-template"; }

    /** ADR-026 #3 — "percorso unico": modelli risdoc e custom passano per la
     *  STESSA pipeline a card (fm-risdoc-pt-section), load+save body_pt.
     *  Anche admin schema-edit (?admin_edit=1) usa questa shell (save →
     *  /body-pt via adapter.adminEdit branch). */
    get _onePath() { return true; }

    connectedCallback() {
        // PERCORSO UNICO (onepath) per i modelli: stessa setup del custom, ma
        // adapter = RisdocTemplateAdapter (schema→PT) + entra in edit per mostrare
        // le card. canEdit forzato (anteprima editabile del percorso unico).
        if (this._isRisdoc && this._onePath) {
            this.replaceChildren();
            super.connectedCallback();
            this.canEdit = true;
            let state = {};
            try { state = JSON.parse(this.initialState || "{}") || {}; } catch (_) {}
            // l'initial-state può essere {state:{…}} o direttamente lo state.
            if (state && state.state && typeof state.state === "object") state = state.state;
            // ADR-026 Step 5 fix — conserva lo state reale (indirizzo/classe/
            // disciplina): le card pt-section ne hanno bisogno per risolvere le
            // options_source folder-mode (abilità/conoscenze/competenze).
            this._risdocState = state;
            this._adapter = createAdapter(this.source, this.templateId || this.docId,
                { state, instanceKey: this.instanceKey, adminEdit: !!this.adminEdit, schemaUrl: this.schemaUrl || "" });
            // ADR-026 Step 5 fix — sincronizza il contesto dai selettori SIDEBAR
            // (#sel-iis/cls/mater → indirizzo/classe/disciplina), come fa
            // fm-risdoc-section-header, così le card curriculari si popolano e si
            // aggiornano quando il docente cambia classe/disciplina (modello nuovo).
            this._syncRisdocStateFromSidebar();
            this._onSidebarChange = () => this._syncRisdocStateFromSidebar();
            for (const id of ["sel-iis", "sel-cls", "sel-mater"]) {
                document.getElementById(id)?.addEventListener("change", this._onSidebarChange);
            }
            queueMicrotask(() => this._enterEdit());
            return;
        }
        // Cattura SSR body PRIMA del primo render Lit, poi RIMUOVI i children
        // pre-esistenti: con render root in light DOM (createRenderRoot→this)
        // Lit NON elimina il nodo SSR [data-ptdoc-ssr] e lo lascerebbe sopra
        // la topbar → contenuto duplicato. replaceChildren() pulisce il
        // container così Lit renderizza da zero (graceful degradation
        // garantita: il body è ri-mostrato in .ptdoc__body via _serverHtml).
        if (!this._serverHtml) {
            const ssr = this.querySelector("[data-ptdoc-ssr]");
            this._serverHtml = ssr ? ssr.innerHTML : this.innerHTML.trim();
            this.replaceChildren();
        }
        if (this.renderMode !== "html") this.renderMode = "interactive";
        super.connectedCallback();
        this._adapter = createAdapter(this.source, this.docId);
        // 2026-06-12 — Sidebar → state ANCHE per i documenti docente (fork
        // teacher_content) e custom PT, non solo per i modelli onepath. Le card
        // pt-section risolvono le options_source folder-mode (competenze/
        // obiettivi/abilità…) dai codici indirizzo/classe/disciplina. Prima questo
        // wiring esisteva solo nel ramo `_isRisdoc && _onePath`: il fork docente
        // passava `_emptyState` alle card (vedi render → .state) → i gruppi
        // dinamici restavano vuoti e NON reagivano al cambio dei selettori sidebar.
        // (Diagnosi doc 989: window.FM.pt.currentState={} sulla pagina studio.)
        this._syncRisdocStateFromSidebar();
        if (!this._onSidebarChange) {
            this._onSidebarChange = () => this._syncRisdocStateFromSidebar();
            for (const id of ["sel-iis", "sel-cls", "sel-mater"]) {
                document.getElementById(id)?.addEventListener("change", this._onSidebarChange);
            }
        }
        // Stato checkbox "Includi intestazione istituto" (header collassabile).
        if (typeof this._adapter.loadIncludeHeader === "function") {
            this._adapter.loadIncludeHeader()
                .then((v) => { this._includeHeader = v; })
                .catch(() => {});
        }
        if (typeof this._adapter.loadIncludeHeaderHtml === "function") {
            this._adapter.loadIncludeHeaderHtml()
                .then((v) => { this._includeHeaderHtml = v; })
                .catch(() => {});
        }
        // 2026-05-27 — apertura EDIT-FIRST come i modelli (scelta utente): il
        // docente (canEdit) atterra direttamente in modifica, niente passaggio
        // "view → Modifica". Studenti/sola-lettura (canEdit=false) o render HTML
        // statico restano in vista. Zero impatto sui dati (solo UX di apertura).
    }

    /** ADR-030 — chiave terna "lente" (indirizzo/classe/materia) per cui
     *  risolvere i valori dei campi 🔗. URL del doc autorevole, poi lo state. */
    _lensTernaKey() {
        const url = this._urlStateCombo ? this._urlStateCombo() : null;
        const s = this._risdocState || {};
        const ind = url?.indirizzo  || s.indirizzo;
        const cls = url?.classe     || s.classe;
        const mat = url?.disciplina || s.disciplina;
        if (!ind || !cls || !mat) return null;
        return `${ind}/${cls}/${mat}`;
    }

    async _ensureBodyPt() {
        if (Array.isArray(this._bodyPt)) return this._bodyPt;
        let loaded = await this._adapter.load();
        // ADR-030 — modello "un doc, valori per terna" (gated da metadata.terna_scoped):
        // estrai lo store dei valori per-terna dal body_pt e applica quelli della
        // terna lente corrente. Per i doc NON terna_scoped: no-op totale (legacy).
        if (this._ternaScoped == null && typeof this._adapter?.loadTernaScoped === "function") {
            try { this._ternaScoped = await this._adapter.loadTernaScoped(); }
            catch (_) { this._ternaScoped = false; }
        }
        if (this._ternaScoped) {
            try {
                const tb = await import("../../modules/risdoc/pt/terna-binding.js");
                const split = tb.splitTernaStore(loaded);
                this._ternaStore = split.store || {};
                loaded = split.blocks;
                const key = this._lensTernaKey();
                if (key) tb.applyTernaValues(loaded, this._ternaStore, key);
                this._ternaLensKey = key;
            } catch (_) { /* best-effort: render struttura senza valori per-terna */ }
        }
        this._bodyPt = loaded;
        // ADR-026 Step 5 fix — dopo il load, adotta lo state della compilation
        // (l'adapter l'ha arricchito con indirizzo/classe/disciplina salvati):
        // serve alle card per risolvere le options_source folder-mode.
        if (this._isRisdoc && this._adapter?._state && typeof this._adapter._state === "object") {
            this._risdocState = { ...(this._risdocState || {}), ...this._adapter._state };
            // L'URL del doc resta autorevole su indirizzo/classe/disciplina: lo
            // ri-applico SOPRA lo state dell'adapter (che può essere parziale o
            // riferito al default d'istituto), così i fetch curriculari folder-mode
            // partono con la combinazione giusta del documento aperto.
            const url = this._urlStateCombo();
            if (url) this._risdocState = { ...this._risdocState, ...url };
            this._pushGlobalState();
        }
        return this._bodyPt;
    }

    _currentBodyPt() {
        return this._mode === "edit" ? this._mergeSections() : this._bodyPt;
    }

    /** ADR-026 Step 5 fix — legge il contesto dai selettori sidebar
     *  (#sel-iis/cls/mater → indirizzo/classe/disciplina) e lo propaga alle card
     *  onepath (nuovo ref → re-fetch options_source). Sidebar = priorità sul
     *  contesto corrente (modello nuovo: il docente sceglie classe/disciplina). */
    /** Combinazione AUTOREVOLE del documento dall'URL della pagina studio:
     *  `/studio/risdoc/{indirizzo}/{classe}/{materia}/{topic}?ids=…`. Per un
     *  documento SALVATO (fork docente) la combinazione è fissata dall'URL, NON
     *  dai selettori sidebar (che mostrano il default d'istituto, es. TES, anche
     *  quando il doc è SCI). Senza questo i fetch options_source folder-mode
     *  partivano con indirizzo/classe/materia=undefined → endpoint [] → elenchi
     *  vuoti (conoscenze/competenze/abilità). Onepath/modelli nuovi non hanno
     *  questo URL → ritorna null → fallback ai selettori sidebar (flusso scelta
     *  classe/disciplina in-place preservato). */
    _urlStateCombo() {
        // ADR-030 — delega all'UNICA implementazione condivisa del parsing URL
        // studio (studioUrlCombo in _options-fetcher.js): niente regex duplicato.
        const c = studioUrlCombo();
        return (c.indirizzo && c.classe && c.disciplina) ? c : null;
    }

    _syncRisdocStateFromSidebar() {
        // URL del documento = priorità sui selettori sidebar (doc salvato a
        // combinazione fissa); sidebar solo come fallback (modello/onepath nuovo).
        const url = this._urlStateCombo();
        const iis = url?.indirizzo  || document.getElementById("sel-iis")?.value || "";
        const cls = url?.classe     || document.getElementById("sel-cls")?.value || "";
        const mat = url?.disciplina || document.getElementById("sel-mater")?.value || "";
        const next = { ...(this._risdocState || {}) };
        let changed = false;
        if (iis && next.indirizzo !== iis)  { next.indirizzo = iis; changed = true; }
        if (cls && next.classe !== cls)     { next.classe = cls; changed = true; }
        if (mat && next.disciplina !== mat) { next.disciplina = mat; changed = true; }
        if (!next.sezione) { next.sezione = "A"; changed = true; }
        if (!changed) { this._pushGlobalState(); return; }
        this._risdocState = next;
        if (this._adapter) this._adapter._state = { ...(this._adapter._state || {}), ...next };
        this._pushGlobalState();
        this.requestUpdate();
    }

    /** Pubblica indirizzo/classe/disciplina nel registro globale
     *  window.FM.pt.currentState così che i picker "Da catalogo" (modal Gruppo +
     *  src-bar componente) possano risolvere le sorgenti "Automatico" (cartella)
     *  per lo stato corrente del documento. Preserva le altre chiavi (orientation…). */
    _pushGlobalState() {
        const st = this._risdocState;
        if (!st || !window.FM?.pt?.setState) return;
        window.FM.pt.setState({ ...(window.FM.pt.currentState || {}), ...st });
    }

    disconnectedCallback() {
        if (this._onSidebarChange) {
            for (const id of ["sel-iis", "sel-cls", "sel-mater"]) {
                document.getElementById(id)?.removeEventListener("change", this._onSidebarChange);
            }
        }
        super.disconnectedCallback();
    }

    // ─── Split/merge body_pt ↔ sezioni (boundary = sectionHeader) ───
    /**
     * Divide un body_pt piatto in sezioni: ogni `sectionHeader` apre una
     * nuova sezione (header incluso come primo block, come in risdoc). I
     * block che precedono il primo header formano una sezione "preambolo".
     * Ritorna oggetti `section` pronti per <fm-risdoc-pt-section> (pt_unified
     * + default = lo slice PT). I `name` sono univoci/stabili.
     */
    _buildSections(bodyPt) {
        let blocks = Array.isArray(bodyPt) ? bodyPt : [];
        // ADR-026 Step 5 fix — per i MODELLI risdoc, il primo blocco è l'header
        // (sectionHeader level 1 con `selectors`, da headerToPt). Va reso come
        // <fm-risdoc-section-header> (intestazione: titolo + selettori + checkbox
        // "Includi intestazione istituto nel PDF"), NON come card → lo estraggo.
        this._risdocHeaderInfo = null;
        this._risdocHeaderNode = null;
        // ADR-026 #2 storage — content-driven: estrai l'header se il body_pt
        // contiene un sectionHeader-con-selectors (marker modello), a prescindere
        // dal source. Custom puri non hanno questo nodo → no-op.
        if (blocks.length
            && blocks[0]?._type === "sectionHeader" && Array.isArray(blocks[0].selectors)) {
            this._risdocHeaderNode = blocks[0]; // preservato per re-prepend al save
            this._risdocHeaderInfo = {
                title: blocks[0].title || "",
                selectors: blocks[0].selectors,
            };
            blocks = blocks.slice(1);
        }
        // ADR-026 Step 5 — ALBERO per LIVELLO header: H2 = sezione, H3 = sottosezione
        // annidata (card-in-card), ecc. Un header di livello L apre una sezione;
        // gli header successivi di livello > L sono SUE figlie; livello <= L
        // chiudono e aprono una sorella. I blocchi non-header vanno al nodo corrente.
        const mk = (header) => ({
            name: `sec_${this._sid++}`,
            title: header?.title || "Sezione",
            level: header?.level || 2,
            pt_unified: true,
            default: header ? [header] : [],
            children: [],
        });
        const roots = [];
        const stack = []; // [{ node, level }]
        let preamble = null;
        for (const b of blocks) {
            if (b && b._type === "sectionHeader") {
                const node = mk(b);
                while (stack.length && stack[stack.length - 1].level >= node.level) stack.pop();
                if (stack.length) stack[stack.length - 1].node.children.push(node);
                else roots.push(node);
                stack.push({ node, level: node.level });
            } else {
                if (stack.length) { stack[stack.length - 1].node.default.push(b); }
                else {
                    if (!preamble) { preamble = mk(null); preamble.title = "Introduzione"; preamble.level = 1; roots.push(preamble); stack.push({ node: preamble, level: 0 }); }
                    preamble.default.push(b);
                }
            }
        }
        if (roots.length === 0) {
            roots.push(mk({ _type: "sectionHeader", title: "Nuova sezione", level: 2 }));
        }
        return roots;
    }

    /** Ricostruisce il body_pt piatto concatenando le sezioni in ordine.
     *  Rimuove le paragrafo-vuote di coda che Tiptap aggiunge per sezione
     *  (altrimenti si accumulano gap tra una sezione e l'altra). */
    _mergeSections() {
        const isEmptyBlock = (b) =>
            b && b._type === "block"
            && (!Array.isArray(b.children) || b.children.length === 0
                || b.children.every((c) => c && c._type === "span" && !String(c.text || "").length));
        const out = [];
        // ADR-026 Step 5 fix — re-prepend l'header risdoc estratto (reso come
        // intestazione, non come card) così il body_pt salvato resta completo.
        if (this._risdocHeaderNode) out.push(this._risdocHeaderNode);
        // Walk DFS dell'albero: contenuto proprio del nodo, poi le sue
        // sottosezioni (preserva l'ordine + i livelli header nel body_pt).
        const walk = (nodes) => {
            for (const s of (nodes || [])) {
                let pt = this._sectionValues[s.name] ?? s.default ?? [];
                if (Array.isArray(pt)) {
                    pt = [...pt];
                    while (pt.length && isEmptyBlock(pt[pt.length - 1])) pt.pop();
                    out.push(...pt);
                }
                if (Array.isArray(s.children) && s.children.length) walk(s.children);
            }
        };
        walk(this._sections || []);
        return out;
    }

    /** PT da renderizzare nell'ANTEPRIMA/vista (HTML statico pubblicato):
     *  rimuove il primo sectionHeader-con-selettori (l'intestazione) quando
     *  includeHeaderHtml è off — coerente con lo strip server-side. Il body_pt
     *  SALVATO resta completo (l'header si modifica/riattiva sempre in edit). */
    _publishedBodyPt(pt) {
        if (this._includeHeaderHtml || !Array.isArray(pt) || !pt.length) return pt;
        const f = pt[0];
        if (f && f._type === "sectionHeader" && Array.isArray(f.selectors)) return pt.slice(1);
        return pt;
    }

    _onSectionChange(e) {
        const { name, value } = e.detail || {};
        if (name && Array.isArray(value)) this._sectionValues[name] = value;
    }

    /** "+ Nuova sezione" (toolbar) → aggiunge una card dopo quella focalizzata
     *  (afterName) o in coda, e porta il focus nel suo input titolo. */
    _onAddSection(e) {
        e.preventDefault?.();
        const afterName = e.detail?.afterName || null;
        const sec = {
            name: `sec_${this._sid++}`,
            title: "Nuova sezione",
            pt_unified: true,
            default: [{ _type: "sectionHeader", title: "Nuova sezione", level: 2 }],
        };
        const arr = [...this._sections];
        const idx = afterName ? arr.findIndex((s) => s.name === afterName) : -1;
        if (idx >= 0) arr.splice(idx + 1, 0, sec); else arr.push(sec);
        this._sections = arr;
        this.updateComplete.then(() => this._focusSectionTitle(sec.name));
    }

    /** Trova un nodo-sezione per name nell'albero (roots + children ricorsivi). */
    _findNode(name, nodes = this._sections) {
        for (const n of (nodes || [])) {
            if (n.name === name) return n;
            const f = this._findNode(name, n.children || []);
            if (f) return f;
        }
        return null;
    }

    /** ADR-026 — aggiunge una SOTTOSEZIONE (card annidata) come figlia della
     *  sezione corrente. Livello header = parent+1 (max 4). */
    _onAddSubsection(e) {
        e.preventDefault?.();
        const parentName = e.detail?.parentName || null;
        let parent = parentName ? this._findNode(parentName) : null;
        // fallback: ultima sezione top-level; se nessuna → aggiungi top-level.
        if (!parent) parent = (this._sections || [])[this._sections.length - 1];
        if (!parent) { this._onAddSection(e); return; }
        const lvl = Math.min((parent.level || 2) + 1, 4);
        const sub = {
            name: `sec_${this._sid++}`,
            title: "Nuova sottosezione",
            level: lvl,
            pt_unified: true,
            default: [{ _type: "sectionHeader", title: "Nuova sottosezione", level: lvl }],
            children: [],
        };
        // BUGFIX annidamento profondo: NON basta mutare parent.children — i
        // componenti annidati (Lit) si aggiornano solo se cambia il riferimento
        // del loro `.subsections`. Quindi rigeneriamo gli array `children` lungo
        // tutto il path root→parent (i NODI restano gli stessi → niente re-mount
        // degli editor / perdita modifiche). Senza questo, inserire una
        // sottosezione DENTRO una sottosezione non compariva.
        const rebuildPath = (nodes) => {
            for (const n of nodes) {
                if (n === parent) {
                    n.children = [...(n.children || []), sub];
                    return true;
                }
                if (Array.isArray(n.children) && n.children.length && rebuildPath(n.children)) {
                    n.children = [...n.children]; // nuovo ref sull'antenato → propaga
                    return true;
                }
            }
            return false;
        };
        rebuildPath(this._sections);
        this._sections = [...this._sections]; // nuovo top array → re-render
        this.requestUpdate();
        this.updateComplete.then(() => this._focusSectionTitle(sub.name));
    }

    /** Elimina una sezione (e l'intero sottoalbero) dall'albero, previa conferma.
     *  L'eliminazione diventa definitiva al Salva (aggiorna body_pt). */
    async _onDeleteSection(e) {
        e.preventDefault?.();
        e.stopPropagation?.();
        const name = e.detail?.name;
        if (!name) return;
        const node = this._findNode(name);
        if (!node) return;
        const childCount = Array.isArray(node.children) ? node.children.length : 0;
        const label = e.detail?.title || node.title || "questa sezione";
        const msg = childCount > 0
            ? `Eliminare «${label}» e le sue ${childCount} sottosezioni? Diventa definitiva al Salva.`
            : `Eliminare la sezione «${label}»? Diventa definitiva al Salva.`;
        const ok = window.FM?.Dialog?.confirm
            ? await window.FM.Dialog.confirm(msg, { title: "Elimina sezione", kind: "danger", okLabel: "Elimina" })
            : true;
        if (!ok) return;
        // Nomi del sottoalbero (per pulire i valori accumulati).
        const names = [];
        const collect = (n) => { names.push(n.name); (n.children || []).forEach(collect); };
        collect(node);
        // Pota preservando l'identità dei nodi non toccati (no rimount globale).
        const prune = (nodes) => {
            const out = [];
            for (const n of nodes) {
                if (n.name === name) continue;
                if (Array.isArray(n.children) && n.children.length) n.children = prune(n.children);
                out.push(n);
            }
            return out;
        };
        this._sections = prune([...this._sections]);
        names.forEach((nm) => { delete this._sectionValues[nm]; });
        this.requestUpdate();
        this.updateComplete.then(() => this._rebindNavigator?.());
        this._toast?.("success", "Sezione eliminata", "Ricordati di salvare.");
    }

    /** Trova la lista di fratelli che contiene `name` + l'indice + il nodo
     *  genitore (null se top-level). */
    _findSiblings(name, nodes = this._sections, parent = null) {
        const idx = (nodes || []).findIndex((n) => n.name === name);
        if (idx >= 0) return { arr: nodes, idx, parent };
        for (const n of (nodes || [])) {
            if (Array.isArray(n.children) && n.children.length) {
                const r = this._findSiblings(name, n.children, n);
                if (r) return r;
            }
        }
        return null;
    }

    /** Sostituisce l'array figli di `parent` (o le root) con `newChildren`,
     *  rigenerando i riferimenti `children` lungo il path → Lit propaga
     *  l'update ai componenti annidati senza re-mount degli editor. */
    _setChildrenRebuild(parent, newChildren) {
        if (!parent) { this._sections = newChildren; return; }
        const rebuild = (nodes) => {
            for (const n of nodes) {
                if (n === parent) { n.children = newChildren; return true; }
                if (Array.isArray(n.children) && n.children.length && rebuild(n.children)) {
                    n.children = [...n.children];
                    return true;
                }
            }
            return false;
        };
        rebuild(this._sections);
        this._sections = [...this._sections];
    }

    /** Sposta una (sotto)sezione su/giù tra le sue SORELLE (stesso genitore).
     *  detail: { name, dir: -1 (su) | +1 (giù) }. */
    _onMoveSection(e) {
        e.preventDefault?.();
        e.stopPropagation?.();
        const name = e.detail?.name;
        const dir = e.detail?.dir < 0 ? -1 : 1;
        if (!name) return;
        const loc = this._findSiblings(name);
        if (!loc) return;
        const { arr, idx, parent } = loc;
        const j = idx + dir;
        if (j < 0 || j >= arr.length) {
            this._toast?.("info", "Già all'estremo", dir < 0 ? "È la prima sorella." : "È l'ultima sorella.", 2000);
            return;
        }
        const newArr = [...arr];
        [newArr[idx], newArr[j]] = [newArr[j], newArr[idx]];
        this._setChildrenRebuild(parent, newArr);
        this.requestUpdate();
        this.updateComplete.then(() => { this._rebindNavigator?.(); this._scrollToSection(name); });
        this._toast?.("success", "Sezione spostata", "Ricordati di salvare.", 2000);
    }

    /** Porta in vista (+ flash) la card di una (sotto)sezione dopo lo
     *  spostamento. Senza questo la card può finire fuori dal viewport e
     *  sembra che il pulsante ↑/↓ "non faccia nulla". Cerca la card sia tra le
     *  top-level (light DOM) sia tra le sottosezioni (deep walk negli shadow). */
    _scrollToSection(name) {
        let tries = 0;
        const iv = setInterval(() => {
            let card = [...this.querySelectorAll("fm-risdoc-pt-section")]
                .find((c) => c.section?.name === name);
            if (!card) {
                const walk = (root) => {
                    for (const el of root.querySelectorAll("fm-risdoc-pt-section")) {
                        if (el.section?.name === name) return el;
                        if (el.shadowRoot) { const f = walk(el.shadowRoot); if (f) return f; }
                    }
                    return null;
                };
                card = walk(this);
            }
            if (card) {
                card.scrollIntoView({ block: "center", behavior: "smooth" });
                try {
                    const wrap = card.shadowRoot?.querySelector(".fm-section-wrap") || card;
                    wrap.animate(
                        [{ outline: "2px solid #2a5ac7", outlineOffset: "2px" }, { outline: "2px solid transparent", outlineOffset: "2px" }],
                        { duration: 900, easing: "ease-out" },
                    );
                } catch (_) {}
                clearInterval(iv);
            } else if (++tries > 25) clearInterval(iv);
        }, 40);
    }

    /** Duplica una (sotto)sezione (con tutto il sottoalbero) come SORELLA
     *  subito dopo l'originale. Nuovi `name` univoci + copia dei valori live
     *  (_sectionValues). detail: { name }. */
    _onDuplicateSection(e) {
        e.preventDefault?.();
        e.stopPropagation?.();
        const name = e.detail?.name;
        if (!name) return;
        const loc = this._findSiblings(name);
        if (!loc) return;
        const { arr, idx, parent } = loc;
        const orig = arr[idx];

        const deepCopy = (v) => (v == null ? v : JSON.parse(JSON.stringify(v)));
        const cloneSubtree = (node, isTop) => {
            const newName = `sec_${this._sid++}`;
            const clone = { ...node, name: newName };
            if (Array.isArray(node.default)) clone.default = deepCopy(node.default);
            // copia il contenuto LIVE (se l'utente ha modificato la card)
            const liveVal = this._sectionValues[node.name];
            if (Array.isArray(liveVal)) this._sectionValues[newName] = deepCopy(liveVal);
            if (isTop) {
                // marca il titolo del duplicato (top-level del subtree clonato)
                const newTitle = `${node.title || node.label || "Sezione"} (copia)`;
                clone.title = newTitle;
                const stampHeader = (pt) => {
                    if (Array.isArray(pt) && pt[0] && pt[0]._type === "sectionHeader") pt[0].title = newTitle;
                };
                stampHeader(clone.default);
                stampHeader(this._sectionValues[newName]);
            }
            clone.children = Array.isArray(node.children) ? node.children.map((c) => cloneSubtree(c, false)) : [];
            return clone;
        };

        const dup = cloneSubtree(orig, true);
        const newArr = [...arr];
        newArr.splice(idx + 1, 0, dup);
        this._setChildrenRebuild(parent, newArr);
        this.requestUpdate();
        this.updateComplete.then(() => {
            this._rebindNavigator?.();
            this._focusSectionTitle?.(dup.name);
        });
        this._toast?.("success", "Sezione duplicata", "Ricordati di salvare.", 2500);
    }

    /** Focus nell'<input> titolo della card `name` (editor in shadow DOM,
     *  monta async → ritenta brevemente). */
    _focusSectionTitle(name) {
        let tries = 0;
        const iv = setInterval(() => {
            const card = [...this.querySelectorAll("fm-risdoc-pt-section")]
                .find((c) => c.section?.name === name);
            const ed = card?.shadowRoot?.querySelector("fm-risdoc-pt-editor");
            const input = ed?.shadowRoot?.querySelector(".pt-section-title-input");
            if (input) { input.focus(); input.select?.(); clearInterval(iv); }
            else if (++tries > 20) clearInterval(iv);
        }, 50);
    }

    // ─── Render-mode toggle (interactive ↔ html), persistito ───
    async _toggleRenderMode() {
        if (!this.canEdit || this._busy) return;
        const next = this.renderMode === "html" ? "interactive" : "html";
        // Uscendo verso html mentre si edita: torna in view (l'editing è
        // un'azione interactive-only).
        if (next === "html" && this._mode === "edit") this._exitEdit();
        this._busy = true;
        const prev = this.renderMode;
        this.renderMode = next;
        try {
            await this._adapter.saveRenderMode(next);
            this._toast("success", "Vista aggiornata",
                next === "html"
                    ? "Il documento si aprirà come HTML statico"
                    : "Il documento si aprirà come pagina interattiva");
        } catch (e) {
            this.renderMode = prev; // rollback ottimistico
            this._toast("error", "Salvataggio vista fallito", String(e.message || e), 5000);
        } finally {
            this._busy = false;
        }
        // 2026-05-30 — BUG "il toggle 🧩 non riporta in edit": tornando da HTML
        // statico a interattivo, updated() prova ad entrare in edit (edit-first)
        // ma _enterEdit veniva scartato perché _busy era ANCORA true durante il
        // saveRenderMode → restava in vista. Ora che _busy è false, rientra
        // esplicitamente in modifica come all'apertura del documento.
        if (this.renderMode === "interactive" && this.canEdit && this._mode !== "edit") {
            this._enterEdit();
        }
    }

    // ─── Mode toggle view ↔ edit ───
    async _enterEdit() {
        if (!this.canEdit || this._busy || this.renderMode === "html") return;
        this._busy = true;
        this._status = "Caricamento editor…";
        try {
            await ensurePtEditorLoaded();
            await this._ensureBodyPt();
            // Rework — l'edit monta N <fm-risdoc-pt-section> (stessa UI risdoc):
            // dividiamo il body_pt in sezioni e segnaliamo alla toolbar che
            // questo host gestisce sezioni multiple (data-sectioned) così
            // "+ Nuova sezione" crea una nuova card invece di inserire inline.
            this._sectionValues = {};
            this._sections = this._buildSections(this._bodyPt);
            this.setAttribute("data-sectioned", "");
            this._mode = "edit";
            this._status = "";
            this._rebindNavigator(); // le card sono comparse → ripopola il navigator
            // ADR-026 model-sync — check fork vs master institutional. Best-effort,
            // non blocca edit se l'adapter non lo supporta o la fetch fallisce.
            if (typeof this._adapter?.getModelSyncStatus === "function") {
                this._adapter.getModelSyncStatus()
                    .then((s) => { this._modelSync = s; this.requestUpdate(); })
                    .catch(() => { /* silenzioso */ });
            }
        } catch (e) {
            this._status = `Editor non disponibile: ${e.message || e}`;
            this._mode = "view";
        } finally {
            this._busy = false;
        }
    }

    _exitEdit() {
        // 2026-05-27 — BUG "le impostazioni degli elementi si perdono in
        // anteprima/HTML statico": _renderView mostrava _clientHtml (valorizzato
        // SOLO al Salva) o _serverHtml stantio → l'Anteprima non rifletteva le
        // modifiche correnti (options select, label/name campi, righe tabella…).
        // Fix: ri-renderizza l'anteprima dal PT MERGE CORRENTE (no persistenza,
        // come da semantica Anteprima). _mergeSections legge _sectionValues
        // popolato dai value-change delle card → contiene tutti gli attributi.
        try {
            const pt = this._mergeSections();
            if (Array.isArray(pt)) {
                this._bodyPt = pt;
                const fn = window.FM?.Pt?.ptToHtml;
                if (typeof fn === "function") this._clientHtml = fn(this._publishedBodyPt(pt));
            }
        } catch { /* best-effort: mantiene l'HTML precedente */ }
        this._mode = "view";
        this._sections = [];
        this._sectionValues = {};
        this.removeAttribute("data-sectioned");
        this._status = "";
    }

    async _save({ exitEdit = true } = {}) {
        if (this._busy) return;
        this._busy = true;
        // Niente status "Salvataggio…" qui: il feedback è dato dal toast "Salvato"
        // (e dalla barra fm-drive-sync-head). Prima restava appeso perché sul
        // successo _status non veniva mai pulito. Azzero eventuali messaggi vecchi.
        this._status = "";
        try {
            // Force-commit dei valori pending negli input cell delle tabelle
            // dinamiche + bypass del debounce 150ms di _scheduleEmit negli
            // editor fm-risdoc-pt-editor (chiamando _emit direttamente sync).
            // Senza questo: input commit → PM dispatch → onUpdate → timer 150ms
            // → _save legge _sectionValues con valore VECCHIO → save no-op.
            this._flushPendingInputBlurs();
            this._flushAllEditorEmits();
            await new Promise((r) => setTimeout(r, 30));
            const next = this._mergeSections();
            // Centralizzazione (Opzione B) — SALVA LA STRUTTURA, non il framework
            // idratato: de-idrata il body_pt prima del save così i gruppi dinamici
            // (options_source) tornano compatti (link + SOLO selezioni); il resto
            // (input/textarea/checkbox statiche/config pulsanti) resta inline. Il
            // contenuto curricolare si ri-risolve dinamicamente per la combinazione
            // al caricamento. Vale per TUTTI gli adapter (prima solo i template lo
            // facevano: i doc docente salvavano il framework idratato, gonfio).
            // No-op per i documenti senza gruppi options_source.
            let toSave = next;
            try {
                const { dehydrateDynamicOptions } = await import("../../modules/risdoc/pt/section-to-pt.js");
                toSave = dehydrateDynamicOptions(next) || next;
            } catch (_) { toSave = next; }
            // ADR-030 — per-terna: estrai i valori 🔗 della terna lente nello store
            // (li azzera nella struttura salvata) e ri-allega il blocco ternaStore
            // al body_pt (cifrato con body_pt). No-op per i doc non terna_scoped.
            if (this._ternaScoped) {
                try {
                    const tb = await import("../../modules/risdoc/pt/terna-binding.js");
                    const key = this._lensTernaKey();
                    this._ternaStore = this._ternaStore || {};
                    if (key) { tb.extractTernaValues(toSave, key, this._ternaStore); this._ternaLensKey = key; }
                    toSave = tb.attachTernaStore(toSave, this._ternaStore);
                } catch (_) { /* best-effort: salva comunque la struttura */ }
            }
            await this._adapter.save(toSave);
            // Per la VISTA tieni la forma idratata (anteprima col contenuto pieno).
            this._bodyPt = next;
            // Re-render view client-side (bundle PT ormai caricato). Anteprima
            // senza intestazione se includeHeaderHtml è off (body_pt salvato resta completo).
            const fn = window.FM?.Pt?.ptToHtml;
            this._clientHtml = typeof fn === "function" ? fn(this._publishedBodyPt(next)) : null;
            this._toast("success", "Salvato", "Documento aggiornato");
            // ADR-026: TeX/PDF persiste ma DEVE restare in edit (apre solo il modal,
            // non l'anteprima). exitEdit=false in _exportTex.
            if (exitEdit) this._exitEdit();
        } catch (e) {
            this._status = `✗ ${e.message || e}`;
            this._toast("error", "Salvataggio fallito", String(e.message || e), 6000);
        } finally {
            this._busy = false;
        }
    }

    // ─── Export ───
    _exportHtml() { window.location.href = this._adapter.exportHtmlUrl(); }

    /**
     * ADR-024 — TeX/PDF: apre il MODAL multi-file (editor TeX + anteprima PDF +
     * ricompila), lo STESSO dei modelli risdoc, via window.FM.openVerificaPreview
     * con mode "teacher-content". Niente più download ZIP diretto (quello è il
     * bottone ZIP dedicato).
     */
    async _exportTex() {
        // Il loader del modal (window.FM.openVerificaPreview) è caricato da
        // bootstrap SOLO in editor-context (esercizi/risdoc), NON sulle pagine
        // custom → importalo on-demand qui (è un thin lazy-loader, il bundle
        // pesante resta caricato solo al primo open).
        if (typeof window.FM?.openVerificaPreview !== "function") {
            try {
                await import("../../modules/features/verifica-preview-modal.js");
            } catch (e) { /* gestito sotto */ }
        }
        const opener = window.FM?.openVerificaPreview;
        if (typeof opener !== "function") {
            this._toast("error", "Modal non disponibile",
                "Ricarica la pagina e riprova.", 5000);
            return;
        }
        if (this._mode === "edit") {
            // Persisti le sezioni correnti così il PDF riflette le modifiche.
            // exitEdit=false: il TeX/PDF apre SOLO il modal, NON deve switchare in anteprima.
            await this._save({ exitEdit: false });
        }
        // ADR-026 Step 5 Fix #2 — un MODELLO risdoc (onepath) NON è un
        // teacher_content: il compile è schema-driven server-side via
        // /api/risdoc/templates/{id}/tex-files+compile-pdf (mode risdoc-template),
        // con form_state {fields, state}. Usare mode teacher-content qui apriva
        // il modal su un doc inesistente → editor TeX + PDF vuoti.
        if (this._isRisdoc) {
            try {
                let fields = {}, bodyPt = [];
                try {
                    const { ptToFields, dehydrateDynamicOptions } = await import("../../modules/risdoc/pt/section-to-pt.js");
                    const cur = this._currentBodyPt() || [];
                    fields = ptToFields(cur) || {};
                    // ADR-026 Step 5 Fix #3 — passa il body_pt de-idratato così
                    // ExportController può renderlo via PtToTex (preserva boxed →
                    // \begin{sectionbox}, nesting, marker che lo schema-based ignora).
                    bodyPt = dehydrateDynamicOptions(cur);
                } catch (_) { /* best-effort */ }
                const argo = (this.getAttribute("argomento") || this.docTitle || `template-${this.templateId}`);
                await opener([{
                    id: this.templateId,
                    variant: argo,
                    title: String(argo).replace(/_/g, " "),
                    formState: { fields, state: this._risdocState || {}, body_pt: bodyPt },
                }], { mode: "risdoc-template" });
            } catch (e) {
                this._toast("error", "Apertura TeX/PDF fallita", String(e.message || e), 5000);
            }
            return;
        }
        try {
            await opener([{
                id: this.docId,
                variant: `content-${this.docId}`,
                title: this.docTitle || `Documento ${this.docId}`,
            }], { mode: "teacher-content" });
        } catch (e) {
            this._toast("error", "Apertura TeX/PDF fallita", String(e.message || e), 5000);
        }
    }

    /** ZIP TeX diretto (pacchetto compilabile in locale). */
    async _exportZip() {
        if (this._busy) return;
        this._busy = true;
        this._status = "Generazione ZIP TeX…";
        try {
            const url = await this._adapter.exportTex();
            this._status = "✓ ZIP pronto";
            window.location.href = url;
        } catch (e) {
            this._status = `✗ ${e.message || e}`;
            this._toast("error", "Export ZIP fallito", String(e.message || e), 5000);
        } finally {
            this._busy = false;
        }
    }

    /** VSCode: scarica il pacchetto ZIP TeX da estrarre e aprire in VSCode. */
    async _exportVscode() {
        if (this._busy) return;
        this._busy = true;
        this._status = "Generazione pacchetto VSCode…";
        try {
            const url = await this._adapter.exportTex();
            const a = document.createElement("a");
            a.href = url; a.download = "";
            document.body.appendChild(a); a.click(); a.remove();
            this._status = "";
            this._toast("success", "ZIP scaricato",
                "Estrai la cartella e aprila con VSCode (estensione LaTeX Workshop).", 5000);
        } catch (e) {
            this._status = `✗ ${e.message || e}`;
            this._toast("error", "Export VSCode fallito", String(e.message || e), 5000);
        } finally {
            this._busy = false;
        }
    }

    async _exportJson() {
        try {
            const data = this._currentBodyPt() ?? await this._ensureBodyPt();
            const blob = new Blob([JSON.stringify(data, null, 2)], { type: "application/json" });
            const url = URL.createObjectURL(blob);
            const a = document.createElement("a");
            a.href = url;
            a.download = `document-${this.docId}-body_pt.json`;
            document.body.appendChild(a);
            a.click();
            a.remove();
            URL.revokeObjectURL(url);
        } catch (e) {
            this._toast("error", "Export JSON fallito", String(e.message || e), 4000);
        }
    }

    _importJson() {
        if (!this.canEdit) return;
        const input = document.createElement("input");
        input.type = "file";
        input.accept = "application/json,.json";
        input.addEventListener("change", async () => {
            const file = input.files?.[0];
            if (!file) return;
            try {
                const parsed = JSON.parse(await file.text());
                const bodyPt = Array.isArray(parsed) ? parsed
                    : (Array.isArray(parsed.body_pt) ? parsed.body_pt : null);
                if (!bodyPt) throw new Error("JSON non valido: atteso array PT o {body_pt:[...]}");
                if (!await window.FM.Dialog.confirm(`Importare ${bodyPt.length} block? Il contenuto corrente sarà sostituito.`)) return;
                this._busy = true; this._status = "Import…";
                await this._adapter.save(bodyPt);
                this._bodyPt = bodyPt;
                const fn = window.FM?.Pt?.ptToHtml;
                this._clientHtml = typeof fn === "function" ? fn(bodyPt) : null;
                // In edit: ricostruisci le card sezione dal body importato.
                if (this._mode === "edit") {
                    this._sectionValues = {};
                    this._sections = this._buildSections(bodyPt);
                }
                this._status = "✓ Importato";
                this._toast("success", "Import JSON ok", `${bodyPt.length} block caricati`);
            } catch (e) {
                this._status = `✗ ${e.message || e}`;
                this._toast("error", "Import JSON fallito", String(e.message || e), 5000);
            } finally {
                this._busy = false;
            }
        });
        input.click();
    }

    _toast(type, title, msg, ms = 3000) {
        window.FM?.ToastManager?.show?.(type, title, msg, ms);
    }

    // ─── Render ───
    render() {
        return html`
            ${this._renderTopbar()}
            <div class="ptdoc" data-mode=${this._mode} data-render-mode=${this.renderMode}>
                ${this._renderCustomHeader()}
                ${this._mode === "edit" ? this._renderEdit() : this._renderView()}
                ${this._status ? html`<div class="ptdoc__status" role="status" aria-live="polite">${this._status}</div>` : ""}
            </div>
        `;
    }

    /**
     * Barra "Intestazione e selettori" collassabile per i documenti custom —
     * STESSO componente dei modelli (<fm-risdoc-section-header>). I selettori
     * (classe/sezione/indirizzo/disciplina) si sincronizzano con la sidebar del
     * sito (#sel-iis/cls/mater); la checkbox "Includi intestazione istituto"
     * persiste in metadata.includeHeader (controlla \input intestaLAteX_IIS in
     * main.tex al compile/export). Così il custom ha la stessa barra dei modelli.
     */
    _renderCustomHeader() {
        // 2026-05-30 — checkbox "Includi intestazione e selettori nell'HTML
        // statico pubblicato" (metadata.includeHeaderHtml): agisce SOLO
        // sull'ANTEPRIMA (vista) — in EDIT l'intestazione resta sempre (la stai
        // modificando e ti serve per ri-attivare la checkbox). In vista
        // (Anteprima docente / HTML statico / studente) la nascondi se il flag è
        // off, così il docente vede esattamente ciò che vedrà lo studente.
        // Complementa lo strip server-side (ContentStudyController) per il JS-off.
        if (this._mode !== "edit" && !this._includeHeaderHtml) return "";
        // ADR-026 Step 5 fix — MODELLO risdoc onepath: rende l'INTESTAZIONE
        // (titolo + selettori + "Includi intestazione istituto") da
        // fm-risdoc-section-header, usando l'header estratto dal body_pt. Senza
        // questo l'intestazione spariva (la sezione header diventava solo titolo).
        // ADR-026 #2 storage — content-driven: rende l'intestazione modello
        // se buildSections ha estratto un _risdocHeaderInfo (presente solo se
        // body_pt contiene un sectionHeader-con-selectors → marker modello).
        // Si attiva anche per teacher_content con body_pt forkato dal master.
        if (this._risdocHeaderInfo) {
            const info = this._risdocHeaderInfo;
            const section = {
                type: "header",
                title: info.title || this.argomento || this.docTitle || "",
                selectors: info.selectors && info.selectors.length
                    ? info.selectors : ["classe", "sezione", "indirizzo", "disciplina"],
            };
            const state = { includeHeader: this._includeHeader, includeHeaderHtml: this._includeHeaderHtml, headerTitle: section.title, ...(this._risdocState || {}) };
            return html`
                <fm-risdoc-section-header
                    class="ptdoc__header"
                    .section=${section}
                    .state=${state}
                    @fm:value-change=${this._onHeaderChange}>
                </fm-risdoc-section-header>
            `;
        }
        const section = {
            type: "header",
            title: this.docTitle || "Documento",
            selectors: ["classe", "sezione", "indirizzo", "disciplina"],
        };
        const state = { includeHeader: this._includeHeader, includeHeaderHtml: this._includeHeaderHtml, headerTitle: this.docTitle || "" };
        return html`
            <fm-risdoc-section-header
                class="ptdoc__header"
                .section=${section}
                .state=${state}
                @fm:value-change=${this._onHeaderChange}>
            </fm-risdoc-section-header>
        `;
    }

    _onHeaderChange(e) {
        const d = e.detail || {};
        // ADR-026 Step 5 fix — i selettori dell'intestazione aggiornano il
        // contesto → propaga alle card (re-fetch options_source) + sidebar.
        // Content-driven (#2): scatta se c'è _risdocHeaderInfo (intestazione
        // modello presente nel body_pt) — risdoc nativo + teacher_content fork.
        if (this._risdocHeaderInfo && d.scope === "state"
            && ["indirizzo", "classe", "disciplina", "sezione"].includes(d.name)) {
            e.stopPropagation();
            this._risdocState = { ...(this._risdocState || {}), [d.name]: d.value };
            if (this._adapter) this._adapter._state = { ...(this._adapter._state || {}), [d.name]: d.value };
            this.requestUpdate();
            return;
        }
        // Checkbox "Includi intestazione+selettori nell'HTML statico pubblicato"
        // → metadata.includeHeaderHtml: gate del primo sectionHeader-con-selettori
        // nell'HTML reso agli studenti (ContentStudyController::renderCustomTopicHtml).
        if (d.scope === "state" && d.name === "includeHeaderHtml") {
            e.stopPropagation();
            const next = !!d.value;
            if (next === this._includeHeaderHtml) return;
            this._includeHeaderHtml = next;
            if (typeof this._adapter?.saveIncludeHeaderHtml === "function") {
                this._adapter.saveIncludeHeaderHtml(next)
                    .then(() => this._toast("success", "Intestazione HTML",
                        next ? "Intestazione e selettori inclusi nell'HTML statico pubblicato."
                             : "Intestazione e selettori esclusi dall'HTML statico pubblicato."))
                    .catch((err) => {
                        this._includeHeaderHtml = !next; // rollback ottimistico
                        this._toast("error", "Salvataggio fallito", String(err.message || err), 5000);
                    });
            }
            return;
        }
        if (d.scope !== "state" || d.name !== "includeHeader") return;
        // Solo la checkbox intestazione persiste qui (gli altri selettori del
        // custom sono contestuali alla sidebar, non modificano il documento).
        e.stopPropagation();
        const next = !!d.value;
        if (next === this._includeHeader) return;
        this._includeHeader = next;
        if (typeof this._adapter?.saveIncludeHeader === "function") {
            this._adapter.saveIncludeHeader(next)
                .then(() => this._toast("success", "Intestazione",
                    next ? "Intestazione istituto inclusa nel PDF." : "Intestazione istituto esclusa dal PDF."))
                .catch((err) => {
                    this._includeHeader = !next; // rollback ottimistico
                    this._toast("error", "Salvataggio fallito", String(err.message || err), 5000);
                });
        }
    }

    // ADR-026 #3 — MODELLO risdoc è un caso particolare del custom, stessa
    // shell + stesso motore (fm-risdoc-pt-section onepath). Vecchio
    // _renderRisdocShell rimosso assieme a <fm-risdoc-template>. Header
    // istituzionale collassabile (fm-risdoc-section-header).

    // ─── Titolo editabile (evento dal componente topbar) ───
    async _onRename(e) {
        const next = (e.detail?.title || "").trim();
        if (!next || next === this.docTitle) return;
        try {
            await this._adapter.saveTitle(next);
            this.docTitle = next;
            this._toast("success", "Titolo aggiornato", next);
        } catch (err) {
            this._toast("error", "Rinomina fallita", String(err.message || err), 5000);
        }
    }

    // ─── Routing azioni topbar centralizzata ───
    _onTopbarAction(e) {
        const action = e.detail?.action;
        // ADR-026 #3 — tutto onepath: azioni gestite dagli handler custom +
        // adapter (no più routing a fm-risdoc-template via _onRisdocAction).
        switch (action) {
            case "ptdoc-toggle-edit":   this._mode === "edit" ? this._exitEdit() : this._enterEdit(); break;
            case "ptdoc-save":          this._save({ exitEdit: !this.adminEdit }); break;
            case "ptdoc-toggle-render": this._toggleRenderMode(); break;
            case "ptdoc-tex":           this._exportTex(); break;
            case "ptdoc-zip":           this._exportZip(); break;
            case "ptdoc-vscode":        this._exportVscode(); break;
            case "ptdoc-html":          this._exportHtml(); break;
            case "ptdoc-export-json":   this._exportJson(); break;
            case "ptdoc-import-json":   this._importJson(); break;
            case "ptdoc-model-reset":   this._modelReset(); break;
            case "ptdoc-model-merge":   this._modelMerge(); break;
            case "ptdoc-toggle-terna":  this._onToggleTernaScoped(); break;
        }
    }

    /** ADR-030 — attiva/disattiva "un documento, valori per classe". All'attivazione
     *  il salvataggio cattura i valori 🔗 CORRENTI nello slot della terna lente
     *  (nessuna perdita). Alla disattivazione il documento torna a valori unici. */
    async _onToggleTernaScoped() {
        const next = !(this._ternaScoped === true);
        const prev = this._ternaScoped;
        try {
            this._ternaScoped = next;
            if (typeof this._adapter?.saveTernaScoped === "function") {
                await this._adapter.saveTernaScoped(next);
            }
            // Salva senza uscire dall'edit: se ON, _save estrae i valori correnti
            // nello store della terna lente e allega il blocco ternaStore.
            await this._save({ exitEdit: false });
            this._toast("success",
                next ? "Valori per classe attivati" : "Valori per classe disattivati",
                next
                    ? "I campi collegati alla cartella avranno valori distinti per indirizzo/classe/materia."
                    : "Il documento torna a valori unici per tutte le classi.");
        } catch (e) {
            this._ternaScoped = prev; // rollback
            this._toast("error", "Operazione fallita", String(e.message || e), 5000);
        }
        this.requestUpdate();
    }

    // ── ADR-026 model-sync — reset + merge da master institutional ──

    async _modelReset() {
        if (typeof this._adapter?.resetFromMaster !== "function") return;
        const masterTitle = this._modelSync?.masterTitle || "modello istituzionale";
        const ok = await window.FM.Dialog.confirm(
            `Sovrascrivi questo documento con la versione corrente del modello "${masterTitle}"?\n\n` +
            "ATTENZIONE: tutte le tue modifiche andranno perse.\n" +
            "Per preservare le modifiche locali usa invece 'Aggiorna dal modello' (merge intelligente)."
        );
        if (!ok) return;
        this._busy = true; this._status = "Ricarico dal modello…";
        try {
            await this._adapter.resetFromMaster();
            this._status = "✓ Documento ricaricato dal modello. Ricarica la pagina per vedere il risultato.";
            this._modelSync = { ...(this._modelSync || {}), outdated: false };
            // Reload pulito: il body_pt è cambiato lato server.
            setTimeout(() => window.location.reload(), 600);
        } catch (e) {
            this._status = `⚠ Reset fallito: ${e.message || e}`;
        } finally {
            this._busy = false;
        }
    }

    async _modelMerge() {
        if (typeof this._adapter?.previewMergeFromMaster !== "function") return;
        this._busy = true; this._status = "Calcolo le differenze…";
        let preview;
        try {
            preview = await this._adapter.previewMergeFromMaster();
        } catch (e) {
            this._busy = false;
            this._status = `⚠ ${e.message || e}`;
            alert(e.message || String(e));
            return;
        }
        // Hydration delle options_source dinamiche per il confronto visivo:
        // il fork ha il body_pt DE-IDRATATO (solo selezioni docente, no items
        // del framework) mentre il master appena salvato dall'admin è
        // IDRATATO. Senza hydratation simmetrica la colonna del docente
        // appare vuota. Riusa la stessa logica di fm-risdoc-pt-section
        // (_options-fetcher + checkboxGroupToPt).
        try {
            const sync = await import("../../modules/risdoc/model-sync.js");
            window.FM = window.FM || {};
            window.FM.ModelSync = {
                previewBlocksAsText: sync.previewBlocksAsText,
                computeBlockDelta: sync.computeBlockDelta,
                visualHash: sync.visualHash,
            };
            // Idrata per-decisione i blocchi (current + master) usando lo
            // state corrente del fork (ind/classe/disciplina). Dopo
            // hydration ri-valuta il flag: se i blocchi diventano identici
            // (cosmetic diff pre-hydration) downgrade a "kept" per evitare
            // l'incoerenza "flag rosso ma 0/0 delta visivo".
            const state = this._risdocState || {};
            // visualHash strippa carry metadata invisibili (name/options_source/
            // ecc.) per non flaggare "diff fantasma" su sezioni visivamente
            // identiche.
            const vSig = (blocks) => (Array.isArray(blocks) ? blocks : [])
                .map(sync.visualHash).join("|");
            for (const d of preview.decisions) {
                if (!Array.isArray(d.currentBlocks) && !Array.isArray(d.masterBlocks)) continue;
                try {
                    const h = await sync.hydrateForDiff(d.currentBlocks || [], d.masterBlocks || [], state);
                    if (Array.isArray(h.current)) d.currentBlocks = h.current;
                    if (Array.isArray(h.master))  d.masterBlocks  = h.master;
                    if (d.action === "kept-master-differs"
                        && Array.isArray(d.currentBlocks) && Array.isArray(d.masterBlocks)
                        && vSig(d.currentBlocks) === vSig(d.masterBlocks)) {
                        d.action = "kept";
                        d.detail = "Sezione invariata (le opzioni del framework sono allineate dopo l'hydration).";
                        // Niente più "Adotta dal modello" (sezione identica
                        // → adoption no-op). masterBlocks resta valorizzato
                        // così la colonna master non mostra "(vuota)"; il
                        // bottone Adopt viene gateato altrove su canAdopt
                        // = action === "kept-master-differs" || "added".
                    }
                } catch (_) { /* sezione skip — diff resta raw */ }
            }
        } catch (_) { /* fallback in render */ }
        this._busy = false; this._status = "";
        this._openMergePreviewModal(preview);
    }

    /** Modale di anteprima merge: lista decisioni + Annulla / Applica.
     *  Stilizzata via css/modules/_model-sync-modal.css (BEM .fm-mm__*) →
     *  dark-aware via token --fm-c-surface/--fm-c-text/--fm-c-border.
     *  Modalità additiva: per le righe action ∈ {kept-master-differs, added}
     *  un bottone "Adotta dal modello" permette di adottare la sezione del
     *  master per quella riga (granular). Il bottone resta visibile finché
     *  non cliccato; al click la riga diventa "Adottata" + il merge effettivo
     *  passato a applyMergeFromMaster contiene la sostituzione. */
    _openMergePreviewModal({ merged, decisions, _masterNew, mode }) {
        document.querySelector(".fm-mm")?.remove();
        const wrap = document.createElement("div");
        wrap.className = "fm-mm";
        wrap.setAttribute("role", "dialog");
        wrap.setAttribute("aria-modal", "true");
        wrap.setAttribute("aria-labelledby", "fm-mm-title");
        const counts = decisions.reduce((acc, d) => { acc[d.action] = (acc[d.action] || 0) + 1; return acc; }, {});
        const summary = [
            counts.updated   ? `${counts.updated} aggiornate dal modello` : null,
            counts.added     ? `${counts.added} aggiunte dal modello` : null,
            counts.kept      ? `${counts.kept} tue preservate` : null,
            counts["kept-master-differs"] ? `${counts["kept-master-differs"]} con modifiche da rivedere` : null,
            counts["kept-teacher-added"] ? `${counts["kept-teacher-added"]} tue aggiunte preservate` : null,
            counts.conflict  ? `${counts.conflict} CONFLITTI` : null,
            counts.dropped   ? `${counts.dropped} rimosse` : null,
        ].filter(Boolean).join(" · ") || "Nessuna differenza rilevata.";
        const icon = { updated: "🔁", added: "➕", kept: "🔒", "kept-master-differs": "⚡",
                       "kept-teacher-added": "🆕", conflict: "⚠", dropped: "🗑", unchanged: "·" };
        // Stato locale: adottate (set di key adottati) + expanded (diff visibile).
        const adopted = new Set();
        const expanded = new Set();
        // canAdopt: azioni dove l'utente sceglie se prendere il master.
        // Default checked per updated+added (merge ha già adottato il master);
        // unchecked per kept-master-differs+conflict (current preservato).
        const adoptableActions = new Set([
            "kept-master-differs", "added", "updated", "conflict",
        ]);
        const defaultAdopted = new Set(["updated", "added"]);
        const canAdopt = (d) => adoptableActions.has(d.action)
            && Array.isArray(d.masterBlocks) && d.masterBlocks.length;
        const autoExpandActions = new Set([
            "kept-master-differs", "added", "updated", "conflict", "dropped",
        ]);
        for (const d of decisions) {
            if (autoExpandActions.has(d.action)) expanded.add(d.key);
            if (defaultAdopted.has(d.action) && Array.isArray(d.masterBlocks) && d.masterBlocks.length) {
                adopted.add(d.key);
            }
        }
        const hasDiff = (d) => Array.isArray(d.currentBlocks) || Array.isArray(d.masterBlocks);
        // Per sezioni identiche (action="kept") skippiamo il delta-compute:
        // non c'è differenza per blocco → niente highlight fantasma su tutta
        // la colonna a causa di carry metadata residui non strippati.
        const skipDeltaActions = new Set(["kept", "unchanged"]);
        // delta computation reuse — lazy lookup of computeBlockDelta esposto via FM.ModelSync
        const computeDelta = (cur, mst) => {
            const fn = window.FM?.ModelSync?.computeBlockDelta;
            if (typeof fn === "function") return fn(cur, mst);
            // fallback inline
            const hashB = (b) => { try { return JSON.stringify(b); } catch { return ""; } };
            const setC = new Set((cur||[]).map(hashB)), setM = new Set((mst||[]).map(hashB));
            const onlyInCurrent = new Set(), onlyInMaster = new Set();
            for (const h of setC) if (!setM.has(h)) onlyInCurrent.add(h);
            for (const h of setM) if (!setC.has(h)) onlyInMaster.add(h);
            return { onlyInCurrent, onlyInMaster, hashB };
        };
        // Renderizza un singolo blocco PT (html via ptToHtml o fallback text).
        const renderBlock = (b) => {
            const fn = window.FM?.Pt?.ptToHtml;
            if (typeof fn === "function") {
                try {
                    const html = fn([b]);
                    if (html && typeof html === "string") return html;
                } catch (_) { /* fallback text */ }
            }
            const text = (window.FM?.ModelSync?.previewBlocksAsText || ((bs) => JSON.stringify(bs).slice(0,400)))([b]);
            return `<pre class='fm-mm__diff-text'>${escapeHtml(text)}</pre>`;
        };
        // Render colonna con highlight per blocco (solo-in-questa-colonna = bg colorato).
        const renderColumn = (blocks, side, delta) => {
            if (!Array.isArray(blocks) || !blocks.length) return "<em class='fm-mm__diff-empty'>(vuota)</em>";
            const onlySet = side === "current" ? delta?.onlyInCurrent : delta?.onlyInMaster;
            const out = [];
            for (const b of blocks) {
                const h = delta?.hashB ? delta.hashB(b) : "";
                const isDelta = onlySet?.has(h);
                const cls = isDelta ? `fm-mm__diff-block fm-mm__diff-block--only-${side}` : "fm-mm__diff-block";
                out.push(`<div class="${cls}">${renderBlock(b)}</div>`);
            }
            return out.join("");
        };
        const renderRow = (d) => `
            <li class="fm-mm__item fm-mm__item--${escapeHtml(d.action)}${adopted.has(d.key) ? " fm-mm__item--adopted" : ""}${expanded.has(d.key) ? " fm-mm__item--expanded" : ""}" data-key="${escapeHtml(d.key)}">
                <div class="fm-mm__row">
                    ${canAdopt(d) ? `<label class="fm-mm__check" title="Spunta per adottare la versione del modello per questa sezione.">
                        <input type="checkbox" class="fm-mm__check-box" data-fm-mm-action="adopt" data-key="${escapeHtml(d.key)}"
                            ${adopted.has(d.key) ? "checked" : ""} aria-label="Adotta sezione: ${escapeHtml(d.title || d.key)}">
                    </label>` : `<span class="fm-mm__check fm-mm__check--placeholder" aria-hidden="true"></span>`}
                    <span class="fm-mm__icon" aria-hidden="true">${icon[d.action] || "·"}</span>
                    <div class="fm-mm__body">
                        <strong class="fm-mm__label">${escapeHtml(d.title || d.key || "(senza titolo)")}</strong>
                        <span class="fm-mm__action-tag">[${escapeHtml(adopted.has(d.key) ? "adottata" : d.action)}]</span>
                        ${d.detail ? `<div class="fm-mm__detail">${escapeHtml(d.detail)}</div>` : ""}
                    </div>
                    ${hasDiff(d) ? `<button type="button" class="fm-mm__row-btn fm-mm__row-btn--toggle" data-fm-mm-action="toggle-diff" data-key="${escapeHtml(d.key)}"
                        title="Mostra/nascondi anteprima della sezione (tuo vs modello).">${expanded.has(d.key) ? "▲ Nascondi" : "▼ Confronta"}</button>` : ""}
                </div>
                ${expanded.has(d.key) && hasDiff(d) ? (() => {
                    // Sezioni invariate (kept): no delta-compute → no highlight
                    // fantasma sull'intera colonna.
                    const delta = skipDeltaActions.has(d.action)
                        ? null
                        : computeDelta(d.currentBlocks, d.masterBlocks);
                    const curCount = delta?.onlyInCurrent?.size || 0;
                    const mstCount = delta?.onlyInMaster?.size || 0;
                    return `
                    <div class="fm-mm__diff">
                        <div class="fm-mm__diff-col fm-mm__diff-col--current">
                            <h5 class="fm-mm__diff-head">📄 La tua versione${curCount ? ` <span class="fm-mm__diff-badge fm-mm__diff-badge--cur">${curCount} solo tuoi</span>` : ""}</h5>
                            <div class="fm-mm__diff-content">${renderColumn(d.currentBlocks, "current", delta)}</div>
                        </div>
                        <div class="fm-mm__diff-col fm-mm__diff-col--master">
                            <h5 class="fm-mm__diff-head">📋 Modello istituzionale${mstCount ? ` <span class="fm-mm__diff-badge fm-mm__diff-badge--mst">${mstCount} dal modello</span>` : ""}</h5>
                            <div class="fm-mm__diff-content">${renderColumn(d.masterBlocks, "master", delta)}</div>
                        </div>
                    </div>`;
                })() : ""}
            </li>
        `;
        const modeBanner = mode === "additive"
            ? `<div class="fm-mm__banner">
                <strong>Modalità additiva</strong> — fork creato prima del supporto sync (manca la baseline). Il merge predefinito aggiunge solo le sezioni <em>nuove</em> del modello in coda; le sezioni esistenti restano <em>tue</em>. Se vedi "⚡ <em>kept-master-differs</em>" significa che il modello ha modifiche in quella sezione: usa <em>"Adotta dal modello"</em> per sostituire solo quella, oppure <em>Reset</em> per allinearti integralmente (PERDE tutte le tue modifiche).
               </div>` : "";
        const renderList = () => decisions.map(renderRow).join("");
        wrap.innerHTML = `
            <div class="fm-mm__dialog fm-mm__dialog--wide">
                <header class="fm-mm__header">
                    <h3 id="fm-mm-title" class="fm-mm__title">🔀 Aggiorna dal modello — confronto visivo</h3>
                    <p class="fm-mm__summary">${escapeHtml(summary)}</p>
                    ${modeBanner}
                </header>
                <ul class="fm-mm__list">${renderList() || "<li class='fm-mm__empty'>(merge identico — nessuna sezione da aggiornare)</li>"}</ul>
                <footer class="fm-mm__footer">
                    <div class="fm-mm__footer-counter" aria-live="polite">
                        <strong class="fm-mm__counter-num">0</strong>/<span class="fm-mm__counter-tot">0</span>
                        sezioni selezionate per l'adozione
                    </div>
                    <div class="fm-mm__footer-actions">
                        <button type="button" data-fm-mm-action="cancel" class="fm-doc-topbar__btn fm-doc-topbar__btn--ghost">Annulla</button>
                        <button type="button" data-fm-mm-action="adopt-all" class="fm-doc-topbar__btn"
                            title="Seleziona tutte le checkbox 'Adotta dal modello'.">☑ Tutte</button>
                        <button type="button" data-fm-mm-action="unadopt-all" class="fm-doc-topbar__btn fm-doc-topbar__btn--ghost"
                            title="Deseleziona tutte le checkbox (le tue sezioni restano come sono).">☐ Nessuna</button>
                        <button type="button" data-fm-mm-action="apply" class="fm-doc-topbar__btn fm-doc-topbar__btn--primary"
                            ${decisions.length === 0 ? "disabled" : ""}>Applica merge</button>
                    </div>
                </footer>
            </div>
        `;
        document.body.appendChild(wrap);
        const listEl = wrap.querySelector(".fm-mm__list");
        const totalAdoptable = decisions.filter(canAdopt).length;
        const totEl = wrap.querySelector(".fm-mm__counter-tot");
        if (totEl) totEl.textContent = String(totalAdoptable);
        const refreshList = () => {
            if (listEl) listEl.innerHTML = renderList();
            const numEl = wrap.querySelector(".fm-mm__counter-num");
            if (numEl) numEl.textContent = String(adopted.size);
        };
        refreshList(); // popola counter iniziale
        const close = () => wrap.remove();
        wrap.addEventListener("click", async (ev) => {
            const btn = ev.target.closest("[data-fm-mm-action]");
            const action = btn?.dataset.fmMmAction;
            if (!action) return;
            if (action === "cancel") { close(); return; }
            if (action === "adopt") {
                const key = btn.dataset.key;
                if (adopted.has(key)) adopted.delete(key); else adopted.add(key);
                refreshList();
                return;
            }
            if (action === "toggle-diff") {
                const key = btn.dataset.key;
                if (expanded.has(key)) expanded.delete(key); else expanded.add(key);
                refreshList();
                return;
            }
            if (action === "adopt-all") {
                for (const d of decisions) {
                    if (canAdopt(d)) adopted.add(d.key);
                }
                refreshList();
                return;
            }
            if (action === "unadopt-all") {
                adopted.clear();
                refreshList();
                return;
            }
            if (action === "apply") {
                // Warning per modalità additiva quando l'utente non ha
                // adottato NESSUNA sezione differente → spiega che il merge
                // non cambierà il body_pt, solo allineerà sync state.
                const adoptablePending = decisions.filter(
                    (d) => canAdopt(d) && d.action === "kept-master-differs",
                );
                const hasAddedSections = decisions.some((d) => d.action === "added");
                if (mode === "additive" && adoptablePending.length > 0 && adopted.size === 0 && !hasAddedSections) {
                    const proceed = await window.FM.Dialog.confirm(
                        `Hai ${adoptablePending.length} sezione/i con differenze dal modello ma NON hai cliccato "Adotta dal modello" su nessuna.\n\n` +
                        `Applicando ora il merge:\n` +
                        `- il tuo body_pt resterà invariato (nessuna modifica visibile)\n` +
                        `- il documento verrà marcato come "allineato" (badge "modello aggiornato" sparirà fino al prossimo update del master)\n\n` +
                        `OK → applica così com'è (allinea baseline)\n` +
                        `Annulla → torna alla modale per selezionare le sezioni da adottare (o clicca "✓ Adotta tutte")`,
                    );
                    if (!proceed) return;
                }
                btn.setAttribute("disabled", "1");
                btn.textContent = "Applico…";
                try {
                    let final = merged;
                    if (adopted.size) {
                        // Re-compose merged applicando le adoptions sopra il merged base.
                        const { adoptSection } = await import("../../modules/risdoc/model-sync.js");
                        for (const d of decisions) {
                            if (adopted.has(d.key) && Array.isArray(d.masterBlocks)) {
                                final = adoptSection(final, d.key, d.masterBlocks);
                            }
                        }
                    }
                    await this._adapter.applyMergeFromMaster(final, _masterNew);
                    close();
                    this._status = "✓ Merge applicato. Ricarica per vedere il risultato.";
                    this._modelSync = { ...(this._modelSync || {}), outdated: false };
                    setTimeout(() => window.location.reload(), 600);
                } catch (e) {
                    btn.removeAttribute("disabled");
                    btn.textContent = "Applica merge";
                    alert("Merge fallito: " + (e.message || String(e)));
                }
            }
        });
    }

    /** Walk light + shadow DOM e fa due cose:
     *  1. Per ogni `.pt-table-cell-input`, chiama il closure `__ptCellCommit`
     *     SE il value differisce dall'ultimo committed (no-op altrimenti).
     *     Necessario perché pm-schema.js commit solo su `blur` per evitare
     *     focus loss durante typing → al save force-commit dei valori pending.
     *  2. Blurra activeElement (sicurezza extra). */
    _flushPendingInputBlurs() {
        const flushInput = (root) => {
            try {
                const inputs = root?.querySelectorAll?.(".pt-table-cell-input, .pt-fcell") || [];
                for (const inp of inputs) {
                    if (typeof inp.__ptCellCommit === "function") {
                        try { inp.__ptCellCommit(); } catch (_){}
                    }
                }
            } catch (_){}
            try {
                const active = root?.activeElement;
                if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.isContentEditable)) {
                    if (typeof active.blur === "function") active.blur();
                }
            } catch (_){}
        };
        flushInput(document);
        // Walk shadow DOM (gli editor risdoc-pt-section stanno qui)
        const all = document.querySelectorAll("*");
        for (const el of all) {
            if (el.shadowRoot) {
                flushInput(el.shadowRoot);
                const inner = el.shadowRoot.querySelectorAll?.("*") || [];
                for (const e2 of inner) if (e2.shadowRoot) flushInput(e2.shadowRoot);
            }
        }
    }

    /** Walk light + shadow DOM e forza il `_emit` immediato su ogni
     *  fm-risdoc-pt-editor, bypassando il suo debounce di 150ms su
     *  `_scheduleEmit`. Necessario in `_save` per garantire che le modifiche
     *  appena fatte (incluse le table cell edits propagate via input event)
     *  arrivino a _sectionValues PRIMA che _mergeSections venga chiamato. */
    _flushAllEditorEmits() {
        const flushEditor = (root) => {
            try {
                const editors = root?.querySelectorAll?.("fm-risdoc-pt-editor") || [];
                for (const ed of editors) {
                    if (ed._emitTimer) { clearTimeout(ed._emitTimer); ed._emitTimer = null; }
                    if (typeof ed._emit === "function") {
                        try { ed._emit(); } catch (_) {}
                    }
                }
            } catch (_){}
        };
        flushEditor(document);
        const all = document.querySelectorAll("*");
        for (const el of all) {
            if (el.shadowRoot) {
                flushEditor(el.shadowRoot);
                const inner = el.shadowRoot.querySelectorAll?.("*") || [];
                for (const e2 of inner) if (e2.shadowRoot) flushEditor(e2.shadowRoot);
            }
        }
    }

    async _csrf() {
        return fetchCsrf();
    }

    /** Bottoni LEADING (resi prima della zona meta): toggle HTML statico. */
    _topbarLeadingButtons() {
        if (!this.canEdit) return [];
        const isHtml = this.renderMode === "html";
        // icon-only (no label): tooltip esplicativo. Posizionato in testa alla
        // topbar (prima del titolo) su richiesta UX 2026-05-30.
        return [{ action: "ptdoc-toggle-render", variant: "icon", pressed: isHtml,
            icon: isHtml ? "🧩" : "📄",
            title: isHtml ? "HTML statico → torna interattivo (persistito)"
                          : "Pubblica come HTML statico (persistito)" }];
    }

    /** Bottoni TOOLS (zona target, sinistra): export/compile. */
    _topbarButtons() {
        // ADR-026 #3 — onepath sempre: topbar uniforme custom+risdoc.
        const editing = this._mode === "edit";
        const btns = [];
        // TeX/PDF → modal (editor TeX + anteprima PDF + ricompila), come risdoc.
        btns.push({ action: "ptdoc-tex", icon: "📥", label: "TeX/PDF",
            title: "Apri modal: editor TeX + anteprima PDF + ricompila + download" });
        btns.push({ action: "ptdoc-zip", label: "ZIP",
            title: "Scarica il pacchetto ZIP TeX (compilabile in locale)" });
        btns.push({ action: "ptdoc-vscode", logo: "/img/topbar/vscode.svg", variant: "accent",
            title: "Scarica il pacchetto e aprilo in VSCode (LaTeX Workshop)" });
        btns.push({ action: "ptdoc-html", icon: "⬇", label: "HTML", title: "Scarica HTML standalone sanitizzato" });
        btns.push({ action: "ptdoc-export-json", label: "{ } Export JSON", title: "Scarica body PT come JSON" });
        if (this.canEdit) btns.push({ action: "ptdoc-import-json", icon: "📥", label: "Import JSON", title: "Carica body PT da JSON" });
        // ADR-026 model-sync — controllo SEMPRE disponibile per i fork di un
        // modello istituzionale (modelId presente), non solo quando il master è
        // più recente: il docente può così controllare/adottare dal modello in
        // qualsiasi momento. Quando il master è più nuovo (outdated) il pulsante
        // è "Aggiorna" (primary) e compare anche il "Reset" (distruttivo);
        // quando è allineato è un neutro "Confronta col modello".
        if (this.canEdit && editing && this._modelSync?.modelId) {
            const ms = this._modelSync;
            const outdated = !!ms.outdated;
            const when = (iso) => (iso ? new Date(iso).toLocaleString("it-IT") : "—");
            const tooltip = outdated
                ? `Il modello "${ms.masterTitle || "istituzionale"}" è stato aggiornato il ${when(ms.masterUpdatedAt)}; il tuo fork è fermo al ${when(ms.syncedAt)}. `
                : `Sei allineato al modello "${ms.masterTitle || "istituzionale"}". `;
            btns.push({
                action: "ptdoc-model-merge",
                icon: outdated ? "🔀" : "🔁",
                label: outdated ? "Aggiorna dal modello" : "Confronta col modello",
                variant: outdated ? "primary" : undefined,
                title: tooltip + "Apri il merge intelligente (preserva le tue modifiche).",
            });
            if (outdated) {
                btns.push({ action: "ptdoc-model-reset", icon: "🔄", label: "Reset modello",
                    title: tooltip + "Sovrascrive con il modello corrente (PERDE le tue modifiche)." });
            }
        }
        // ADR-030 — toggle "un documento, valori per classe".
        if (this.canEdit && editing) {
            const on = this._ternaScoped === true;
            btns.push({
                action: "ptdoc-toggle-terna",
                icon: on ? "🔗" : "📌",
                label: on ? "Valori per classe: ON" : "Valori per classe",
                variant: on ? "primary" : undefined,
                title: on
                    ? "ON: un solo documento con valori distinti per indirizzo/classe/materia nei campi 🔗 (collegati alla cartella o marcati). Clic per disattivare."
                    : "Attiva: un solo documento, i campi collegati alla cartella (o marcati 🔗) avranno valori distinti per indirizzo/classe/materia.",
            });
        }
        return btns;
    }

    /** Bottoni ACTIONS (gruppo destro, prima del navigator "Sezioni"):
     *  Salva (sinistra) + Anteprima/Modifica (destra), su richiesta UX 2026-05-30. */
    _topbarActionButtons() {
        const editing = this._mode === "edit";
        const isHtml = this.renderMode === "html";
        if (!this.canEdit || isHtml) return [];
        const btns = [];
        if (editing) btns.push({ action: "ptdoc-save", variant: "save", icon: "💾", label: "Salva" });
        btns.push({ action: "ptdoc-toggle-edit", variant: "primary",
            icon: editing ? "👁" : "✎", label: editing ? "Anteprima" : "Modifica" });
        return btns;
    }

    /**
     * ADR-024 — topbar resa dal componente CENTRALIZZATO <fm-doc-topbar>
     * (stesso usato dai template risdoc). Qui passiamo solo lo spec bottoni +
     * titolo editabile; il routing azioni avviene via evento doc-topbar:action.
     */
    _renderTopbar() {
        // ADR-026 — Widget institutional-only (chip ADMIN/COLLAB + ✕ Esci +
        // chip ISTANZA + ✏️ Modifica struttura) arrivano server-side come
        // topbar-extra-html (vedi TemplateViewController $topbarExtra). Vanno
        // prima del navigator sezioni in coda. Fallback solo navigator se
        // l'attributo è vuoto (custom doc puro, non-fork).
        const trailing = this.topbarExtraHtml
            ? this.topbarExtraHtml + NAVIGATOR_HTML
            : NAVIGATOR_HTML;
        return html`
            <fm-doc-topbar
                doctype=""
                title=${this.docTitle}
                ?editable-title=${this.canEdit && this.renderMode !== "html"}
                variant="custom"
                ?busy=${this._busy}
                .leadingButtons=${this._topbarLeadingButtons()}
                .buttons=${this._topbarButtons()}
                .actionButtons=${this._topbarActionButtons()}
                .trailingHtml=${trailing}
                @doc-topbar:action=${(e) => this._onTopbarAction(e)}
                @doc-topbar:rename=${(e) => this._onRename(e)}>
            </fm-doc-topbar>
        `;
    }

    _renderView() {
        const viewHtml = this._clientHtml ?? this._serverHtml ?? "";
        return html`<div class="ptdoc__body" .innerHTML=${viewHtml}></div>`;
    }

    _renderEdit() {
        // Rework — STESSA interfaccia di risdoc: N card <fm-risdoc-pt-section>
        // (collapse, chrome, dark, focus, save provati) + la toolbar PT unica
        // in alto. Ogni card riceve un `section` STABILE (pt_unified+default);
        // i value-change si accumulano in _sectionValues (no rimount).
        // `repeat` keyed per nome → preservare le istanze quando si aggiunge
        // una sezione (no re-init delle card esistenti).
        return html`
            <div class="ptdoc__edit"
                 @fm:value-change=${this._onSectionChange}
                 @fm:add-pt-section=${this._onAddSection}
                 @fm:add-pt-subsection=${this._onAddSubsection}
                 @fm:delete-pt-section=${this._onDeleteSection}
                 @fm:move-pt-section=${this._onMoveSection}
                 @fm:duplicate-pt-section=${this._onDuplicateSection}>
                <fm-risdoc-pt-toolbar class="ptdoc__pt-toolbar" single-section></fm-risdoc-pt-toolbar>
                <div class="ptdoc__sections">
                    ${repeat(this._sections || [], (s) => s.name, (s) => html`
                        <fm-risdoc-pt-section
                            .section=${s}
                            .fields=${this._sectionValues}
                            .state=${this._risdocState || this._emptyState}
                            .subsections=${s.children || []}
                            compact></fm-risdoc-pt-section>
                    `)}
                </div>
            </div>
        `;
    }
}

if (!customElements.get("fm-pt-document")) {
    customElements.define("fm-pt-document", FmPtDocument);
}
