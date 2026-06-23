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
import { fetchJson, fetchCsrf } from "../../../modules/core/dom-utils.js";

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
        const schema = await this._fetchSchema();
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
        if (!m) return null;
        const detail = await fetch(`/api/risdoc/compilations/${m.id}`, { credentials: "same-origin" })
            .then((r) => r.ok ? r.json() : null).catch(() => null);
        const raw = detail?.compilation?.data_json;
        if (!raw) return null;
        let data; try { data = typeof raw === "string" ? JSON.parse(raw) : raw; } catch { return null; }
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
        const fd = new URLSearchParams({
            _csrf: csrf,
            compilation_key: `combo_${slug}`,
            label,
            classe: state.classe || "", sezione: state.sezione || "",
            indirizzo: state.indirizzo || "", disciplina: state.disciplina || "",
            data: JSON.stringify({ state, fields, body_pt: bodyPt, extra_sections: [], instance_key: this.instanceKey || "" }),
        });
        const j = await fetchJson(`/api/risdoc/templates/${this.templateId}/compilations`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: fd.toString(),
        });
        if (!j.ok) throw new Error(j.error || "compilations: richiesta non riuscita");
        return { ok: true };
    }

    // ── Interfaccia adapter attesa da fm-pt-document (stub no-op finché step 6) ──
    /** Il titolo del modello non è rinominabile dal docente qui. */
    async saveTitle() { return { ok: true }; }
    /** Render-mode non persistito per i modelli (anteprima client). */
    async saveRenderMode() { return { ok: true }; }
    /** Intestazione: i modelli la gestiscono via stato schema (no metadata). */
    async loadIncludeHeader() { return true; }
    async saveIncludeHeader() { return { ok: true }; }
    async loadIncludeHeaderHtml() { return true; }
    async saveIncludeHeaderHtml() { return { ok: true }; }

    exportHtmlUrl() {
        // I template risdoc non hanno export-html standalone dedicato; il
        // render HTML pulito passa per il loro path schema-driven.
        return `/risdoc/view/${this.templateId}`;
    }

    async exportTex() {
        // Riusa l'export risdoc esistente (schema-driven via ExportController).
        const csrf = await fetchCsrf();
        const j = await fetchJson(`/api/risdoc/templates/${this.templateId}/export`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf, mode: "zip" }).toString(),
        });
        if (!j.url) throw new Error(j.error || "export risdoc fallito");
        return j.url;
    }
}
