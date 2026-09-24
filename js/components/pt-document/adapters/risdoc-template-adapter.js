/**
 * RisdocTemplateAdapter — onepath ADR-026 #3 (motore unico).
 *
 * Carica un modello risdoc come body_pt unificato e lo salva come compilation
 * del docente (default) o come master institutional body_pt (admin schema-edit).
 *
 * load():
 *   1. Se esiste data_json.body_pt salvato per la combinazione corrente
 *      (compilation_key=combo_<ind-cls-sez-mat>), lo restituisce così com'è
 *      (source-of-truth post-migrazione Step 5).
 *   2. Fallback: deriva il body_pt dallo schema (compilationToBodyPt) → template
 *      "vuoto" da compilare.
 *
 * save(pt):
 *   - dehydrateDynamicOptions(pt) → ricomprime le opzioni curriculum espanse
 *     (centinaia di nodi) in 1 checkboxGroup compatto (selezioni only).
 *   - adminEdit=true → POST /api/risdoc/templates/{id}/body-pt (master).
 *   - default → POST /api/risdoc/templates/{id}/compilations (docente).
 *
 * Opts:
 *   state {indirizzo, classe, sezione, disciplina}  contesto card dinamiche
 *   instanceKey                                      compilation per istanza
 *   adminEdit                                         flag schema-edit
 *   schemaUrl                                         override (preview pending)
 */
import { fetchJson, fetchCsrf, wafFetch } from "../../../modules/core/dom-utils.js";
import { pacchettoDallaRisposta } from "../../../modules/core/pacchetto.js";

export class RisdocTemplateAdapter {
    /** @param {number|string} templateId risdoc template id
     *  @param {{state?:object, instanceKey?:string, adminEdit?:boolean, schemaUrl?:string}} [opts] */
    constructor(templateId, opts = {}) {
        this.templateId = templateId;
        this._schema = null;
        this._state = (opts.state && typeof opts.state === "object") ? opts.state : {};
        this.instanceKey = opts.instanceKey || "";
        this.adminEdit  = !!opts.adminEdit; // ADR-026 #3 — admin schema-edit: save → master body_pt
        // ADR-026 #3 cleanup — schema-url override (preview pending da
        // RisdocAdminController). Vuoto = default risdoc API path.
        this.schemaUrl  = opts.schemaUrl || "";
        // ADR-046 — l'identificativo della RIGA salvata, non del modello.
        //
        // Prima del 22/9/2026 questo numero passava di qui due volte al giorno
        // e nessuno lo teneva: il server lo restituisce al salvataggio
        // (`{ok:true, id:...}`) e `_loadInstanceBodyPt` lo vede come `m.id`.
        // Tutte e due le volte finiva in una variabile che moriva subito.
        //
        // Serve perché è l'unica cosa che identifica la compilazione aperta.
        // La chiave testuale `combo_<slug>` NON va bene al suo posto: si
        // ricalcola da `this._state` a ogni uso, e lo stato cambia sotto i
        // piedi (l'URL, le card, il caricamento di una compilazione salvata).
        // Segnare per chiave vorrebbe dire rischiare di segnare la riga
        // sbagliata — o nessuna, in silenzio.
        //
        // Resta null quando non c'è una riga sul server: modifica del master
        // da parte del super-admin, bozza in localStorage per scelta
        // dell'Istituto, compilazione mai salvata. In tutti questi casi non
        // c'è niente da segnare, e il null è la guardia.
        this.compilationId = null;
    }

    async _fetchSchema() {
        if (this._schema) return this._schema;
        const url = this.schemaUrl || `/api/risdoc/templates/${this.templateId}/schema`;
        const j = await fetchJson(url);
        if (j.error) throw new Error(`risdoc schema ${this.templateId}: ${j.error}`);
        this._schema = j;
        return this._schema;
    }

    /**
     * READ: schema sections → body_pt concatenato. Lazy import di
     * sectionSchemaToPt (modulo risdoc) per evitare dipendenza upfront.
     * Lossless solo per sezioni pt_unified; le altre sono best-effort.
     */
    async load() {
        // ADR-026 #3 — admin schema-edit: super-admin edita il MASTER
        // institutional. Save → risdoc_templates.body_pt (via /body-pt
        // endpoint). Load DEVE leggere lo stesso campo, NON le compilations
        // del singolo docente (che non sono pertinenti al master). Bypass
        // di _loadInstanceBodyPt che cercherebbe combo_<slug> del docente.
        if (this.adminEdit) {
            try {
                const master = await this._fetchMasterBodyPt();
                if (Array.isArray(master) && master.length) return master;
            } catch { /* fallback sotto allo schema-derived empty */ }
        } else {
            // Percorso docente: se ha una compilation salvata (body_pt
            // formato custom unificato), la usa così com'è.
            try {
                const stored = await this._loadInstanceBodyPt();
                if (Array.isArray(stored) && stored.length) return stored;
            } catch { /* fallback sotto */ }
        }

        // Fallback: nessun body_pt salvato → derivato dallo SCHEMA (template
        // vuoto). Carry completo via sectionSchemaToPt (Step 1-2c).
        //
        // 23/9/2026 (A-23) — un modello creato dal pannello («crea modello»)
        // non ha schema: vive solo nel body_pt del master. Senza schema si
        // parte da quello; se manca anche lui, l'errore dello schema arriva a
        // chi carica, come prima. Con lo schema il master non si chiede.
        let schema;
        try {
            schema = await this._fetchSchema();
        } catch (errore) {
            const master = await this._fetchMasterBodyPt().catch(() => null);
            if (Array.isArray(master) && master.length) return master;
            throw errore;
        }
        const sections = Array.isArray(schema.sections) ? schema.sections : [];
        let compilationToBodyPt, sectionSchemaToPt;
        try {
            ({ compilationToBodyPt, sectionSchemaToPt } = await import("../../../modules/risdoc/pt/section-to-pt.js"));
        } catch {
            return sections.flatMap((s) => Array.isArray(s.default) ? s.default : []);
        }
        try { return compilationToBodyPt(schema, {}, {}); }
        catch { /* fallback granulare */ }
        const body = [];
        for (const s of sections) {
            if (Array.isArray(s.default)) { body.push(...s.default); continue; }
            try { const pt = sectionSchemaToPt(s, {}, {}); if (Array.isArray(pt)) body.push(...pt); }
            catch { /* skip best-effort */ }
        }
        return body;
    }

    /** Carica il master institutional body_pt da risdoc_templates row. Usato
     *  in modalità admin schema-edit (load + save vanno sulla stessa fonte).
     *  cache:'no-store' → l'admin vede sempre il salvataggio appena fatto. */
    async _fetchMasterBodyPt() {
        const j = await fetchJson(`/api/risdoc/templates/${this.templateId}?cb=${Date.now()}`,
            { cache: "no-store" });
        if (j.error) throw new Error(`master ${this.templateId}: ${j.error}`);
        let pt = j.template?.body_pt;
        if (typeof pt === "string") { try { pt = JSON.parse(pt); } catch { pt = null; } }
        return Array.isArray(pt) ? pt : null;
    }

    /** Risolve la compilation dell'istanza corrente e ne estrae data_json.body_pt
     *  (formato custom unificato). Null se assente. */
    async _loadInstanceBodyPt() {
        const lc = await fetch(`/api/risdoc/templates/${this.templateId}/compilations`, { credentials: "same-origin" })
            .then((r) => r.ok ? r.json() : null).catch(() => null);
        const list = lc?.compilations || [];
        if (!list.length) return null;
        // Match per compilation_key = combo_<slug> (stessa chiave usata da
        // save()); fallback a instance_key, poi alla prima.
        const st = this._state || {};
        const slug = [st.indirizzo, st.classe, st.sezione, st.disciplina].map((v) => v || "_").join("-");
        const comboKey = `combo_${slug}`;
        const wantInst = this.instanceKey || "";
        const m = list.find((c) => c.compilation_key === comboKey)
            || (wantInst && list.find((c) => (c.instance_key || c.instanceKey || "") === wantInst))
            || list[0];
        if (!m) return this._loadLocalDraft();
        // ADR-046 — la riga aperta è questa: da qui in poi si sa quale
        // compilazione segnare quando il docente scarica il documento.
        this.compilationId = Number(m.id) || null;
        const detail = await fetch(`/api/risdoc/compilations/${m.id}`, { credentials: "same-origin" })
            .then((r) => r.ok ? r.json() : null).catch(() => null);
        const raw = detail?.compilation?.data_json;
        if (!raw) return this._loadLocalDraft();
        let data; try { data = typeof raw === "string" ? JSON.parse(raw) : raw; } catch { return this._loadLocalDraft(); }
        // ADR-026 Step 5 fix — adotta lo STATE salvato della compilation
        // (indirizzo/classe/disciplina): serve alle card per risolvere le
        // options_source folder-mode (abilità/conoscenze/competenze). L'initial-
        // state dell'URL può non averlo (es. {professore}).
        if (data && data.state && typeof data.state === "object") {
            this._state = { ...(this._state || {}), ...data.state };
        }
        return (data && Array.isArray(data.body_pt) && data.body_pt.length) ? data.body_pt : null;
    }

    /**
     * 2026-09-04 — bozza nel browser. Quando l'Istituto del docente ha scelto
     * che le compilazioni dei modelli istituzionali non restino sul server
     * (il server risponde 403 `compilation_storage_disabled`), la bozza si
     * tiene qui, in localStorage, e si ricarica da qui alla riapertura. Il
     * PDF si esporta normalmente: l'export parte dallo stato del browser.
     */
    _localDraftKey() {
        return `pantedu.risdoc.draft.${this.templateId}.${this.instanceKey || ""}`;
    }

    _loadLocalDraft() {
        try {
            const raw = localStorage.getItem(this._localDraftKey());
            if (!raw) return null;
            const d = JSON.parse(raw);
            if (d && d.state && typeof d.state === "object") {
                this._state = { ...(this._state || {}), ...d.state };
            }
            return (d && Array.isArray(d.body_pt) && d.body_pt.length) ? d.body_pt : null;
        } catch {
            return null;
        }
    }

    _saveLocalDraft(payload) {
        try {
            localStorage.setItem(this._localDraftKey(), JSON.stringify({ saved_at: new Date().toISOString(), ...payload }));
            return true;
        } catch {
            return false;
        }
    }

    /**
     * SAVE onepath: PT modificato → fields (via ptToFields, lossless per
     * back-compat) + state + body_pt source-of-truth → POST /compilations.
     */
    async save(pt) {
        const { ptToFields, dehydrateDynamicOptions } = await import("../../../modules/risdoc/pt/section-to-pt.js");
        const bodyPt = dehydrateDynamicOptions(Array.isArray(pt) ? pt : []);

        // ADR-026 #3 — admin schema-edit: super-admin modifica il master
        // institutional body_pt. Save dedicato → POST /body-pt (Step 4 backfill
        // endpoint), NON crea una compilation per il singolo docente.
        if (this.adminEdit) {
            const csrf = await fetchCsrf();
            const fd = new URLSearchParams({ _csrf: csrf, body_pt: JSON.stringify(bodyPt) });
            const j = await fetchJson(`/api/risdoc/templates/${this.templateId}/body-pt`, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: fd.toString(),
            });
            if (!j.ok) throw new Error(j.error || "body-pt: richiesta non riuscita");
            return { ok: true, mode: "admin" };
        }

        // ADR-026 Step 5 — body_pt = SOURCE-OF-TRUTH unificata (formato custom).
        // DE-HYDRATION: i campi options_source espansi al render (centinaia di nodi)
        // vengono ricompressi in 1 nodo compatto (options_source + selezioni) →
        // evita il bloat/limite 2MB; le opzioni si ri-fetchano al render. `fields`
        // mantenuti per back-compat col motore legacy. Additivo.
        const fields = ptToFields(pt);
        const state = this._state || {};
        const slug = [state.indirizzo, state.classe, state.sezione, state.disciplina]
            .map((v) => v || "_").join("-");
        const labelParts = [state.classe, state.sezione, state.indirizzo, state.disciplina].filter(Boolean);
        const label = labelParts.length ? labelParts.join(" · ") : `Versione ${new Date().toLocaleDateString("it-IT")}`;
        const csrf = await fetchCsrf();
        const payload = { state, fields, body_pt: bodyPt, extra_sections: [], instance_key: this.instanceKey || "" };
        const fd = new URLSearchParams({
            _csrf: csrf,
            compilation_key: `combo_${slug}`,
            label,
            classe: state.classe || "", sezione: state.sezione || "",
            indirizzo: state.indirizzo || "", disciplina: state.disciplina || "",
            data: JSON.stringify(payload),
        });
        const j = await fetchJson(`/api/risdoc/templates/${this.templateId}/compilations`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        if (!j.ok && j.error === "compilation_storage_disabled") {
            // Scelta dell'Istituto del docente (CompilationStoragePolicy): la
            // bozza resta qui, sul server il solo modello.
            const kept = this._saveLocalDraft(payload);
            try {
                const { notify } = await import("../../../modules/ui/sync-panel.js");
                notify("Bozza conservata nel browser", kept ? "warn" : "error",
                    kept
                        ? (j.message || "Per il tuo Istituto le compilazioni di questo modello non si salvano sul server: la bozza resta in questo browser. Esporta il PDF e depositalo nei sistemi della scuola.")
                        : "Il server non salva le compilazioni di questo modello e il browser non consente di conservarle: esporta subito il PDF.",
                    10000);
            } catch { /* il pannello è un di più */ }
            // ADR-046 — sul server non c'è nessuna riga: non c'è niente da
            // segnare e niente che scada. La bozza vive nel browser del
            // docente, e di quella non decidiamo noi.
            this.compilationId = null;
            if (!kept) throw new Error("compilation_storage_disabled");
            return { ok: true, local: true };
        }
        if (!j.ok) throw new Error(j.error || "compilations: richiesta non riuscita");
        // ADR-046 — il server dice qual è la riga: fino al 22/9/2026 questo
        // numero veniva buttato qui.
        if (j.id) this.compilationId = Number(j.id) || null;
        // 2026-09-04 — senza account studente il server svuota i campi riferiti a
        // studenti o genitori (CompilationScrubber) e ne restituisce i nomi: il
        // docente deve saperlo, altrimenti crede di aver salvato ciò che vede.
        const scrubbed = Array.isArray(j.scrubbed) ? j.scrubbed : [];
        if (scrubbed.length) {
            try {
                const { notify } = await import("../../../modules/ui/sync-panel.js");
                notify("Campi non salvati", "warn",
                    `Dati di studenti o genitori non si conservano in questa istanza: ${scrubbed.join(", ")}.`,
                    8000);
            } catch { /* il pannello è un di più: il salvataggio è già avvenuto */ }
        }
        return { ok: true, scrubbed };
    }

    // ── Interfaccia adapter attesa da fm-pt-document (stub no-op finché step 6) ──
    /** Il titolo del modello non è rinominabile dal docente qui. */
    async saveTitle() { return { ok: true }; }
    /** Render-mode non persistito per i modelli (anteprima client). */
    async saveRenderMode() { return { ok: true }; }
    /**
     * Intestazione dell'istituto nel PDF (la spunta «Includi intestazione
     * istituto» nella barra del documento).
     *
     * 21/9/2026 — segnalazione dell'utente: «se tolgo la spunta e genero il
     * PDF, l'intestazione esce lo stesso». Erano due stub: uno rispondeva
     * sempre «inclusa», l'altro «fatto» senza scrivere niente, e il messaggio
     * di conferma arrivava lo stesso. Il verde che non misura, in una riga.
     *
     * Il flag vive nello stato della compilazione (`state.includeHeader`):
     * quello che `save()` manda al server dentro `data` e che
     * `_loadInstanceBodyPt()` rilegge alla riapertura. È anche lo stato che il
     * bottone TEX/PDF manda al compilatore, che sulla sua base commenta la
     * riga dell'intestazione in main.tex (ExportController::buildFiles).
     *
     * Limite dichiarato: in modifica del master (`adminEdit`) lo stato non si
     * salva da nessuna parte — il modello ha solo `body_pt`. Lì la scelta vale
     * per il PDF che si genera adesso e si riazzera alla riapertura; per
     * renderla permanente servirebbe una colonna su `risdoc_templates`.
     */
    async loadIncludeHeader() { return (this._state || {}).includeHeader !== false; }

    async saveIncludeHeader(include) {
        this._state = { ...(this._state || {}), includeHeader: !!include };
        return { ok: true };
    }

    /** L'HTML statico dei modelli non ha intestazione da togliere: resta com'è. */
    async loadIncludeHeaderHtml() { return true; }
    async saveIncludeHeaderHtml() { return { ok: true }; }

    exportHtmlUrl() {
        // I template risdoc non hanno export-html standalone dedicato; il
        // render HTML pulito passa per il loro path schema-driven.
        return `/risdoc/view/${this.templateId}`;
    }

    /**
     * Il pacchetto TeX da compilare in locale.
     *
     * 21/9/2026 — qui si mandava solo `_csrf` e `mode=zip`, e il server, non
     * trovando `form_state`, costruiva il pacchetto dal modello vuoto: il
     * docente si scaricava un archivio senza una riga di quello che aveva
     * scritto. Misurato nei due versi sullo stesso endpoint, stessa sessione,
     * stesso modello: senza `form_state` il valore compilato non c'è, con
     * `form_state` c'è. Il difetto era qui, non nel server.
     *
     * @param {{fields: object, state: object, body_pt: Array}|null} [formState]
     *        quello che il docente ha compilato; senza, esce il modello vuoto
     *        (è il caso del bottone di riga, che un documento aperto non ce l'ha).
     */
    async exportTex(formState = null) {
        const csrf = await fetchCsrf();
        const corpo = new URLSearchParams({ _csrf: csrf });
        if (formState) {
            corpo.append("form_state", JSON.stringify(formState));
        }
        // `wafFetch` e non `fetchJson`: quest'ultima chiede e pretende JSON,
        // mentre qui la risposta buona è l'archivio. E `wafFetch` è l'unica che
        // rifà la richiesta dopo una verifica del filtro di sicurezza.
        const risposta = await wafFetch(`/api/risdoc/templates/${this.templateId}/export`, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: corpo.toString(),
        });
        return pacchettoDallaRisposta(risposta, `modello-${this.templateId}.zip`);
    }
}
