/**
 * G24 — TeacherContentAdapter per <fm-pt-document>.
 *
 * Data-source adapter per documenti teacher_content (layout=custom).
 * Astrae load/save/export del body_pt verso /api/teacher/content/*.
 *
 * Interfaccia adapter (contract per <fm-pt-document>):
 *   load()            → Promise<Array> body_pt
 *   save(bodyPt)      → Promise<void>   (preserva resto metadata)
 *   exportHtmlUrl()   → string          (GET download HTML pulito)
 *   exportTex()       → Promise<string> (POST → URL ZIP TeX)
 */
import { RisdocTemplateAdapter } from "./risdoc-template-adapter.js";
import { fetchJson, fetchCsrf } from "../../../modules/core/dom-utils.js";

export class TeacherContentAdapter {
    /** @param {number|string} docId teacher_content id */
    constructor(docId) {
        this.docId = docId;
    }

    async _csrf() {
        return fetchCsrf();
    }

    async _fetchContent() {
        const j = await fetchJson(`/api/teacher/content/${this.docId}`);
        if (j.error) throw new Error(`fetch content ${this.docId}: ${j.error}`);
        const meta = j.content?.metadata || (() => {
            try { return JSON.parse(j.content?.metadata_json || "{}"); } catch { return {}; }
        })();
        return { row: j.content || {}, meta };
    }

    async _fetchMeta() {
        const { meta } = await this._fetchContent();
        return meta;
    }

    async load() {
        const meta = await this._fetchMeta();
        return Array.isArray(meta.body_pt) ? meta.body_pt : [];
    }

    async save(bodyPt) {
        return this._patchMeta((meta) => { meta.body_pt = bodyPt; });
    }

    /**
     * ADR-024 — persiste metadata.render_mode (interactive|html) preservando
     * il resto dei metadata (body_pt/layout/category/…). Determina la
     * presentazione SSR alla successiva apertura del documento.
     */
    async saveRenderMode(mode) {
        const m = mode === "html" ? "html" : "interactive";
        return this._patchMeta((meta) => { meta.render_mode = m; });
    }

    /** Carica il flag intestazione (metadata.includeHeader, default true). */
    async loadIncludeHeader() {
        try {
            const meta = await this._fetchMeta();
            return meta.includeHeader !== false;
        } catch { return true; }
    }

    /**
     * Persiste metadata.includeHeader (checkbox "Includi intestazione istituto"):
     * controlla se main.tex include \input{texCommon/intestaLAteX_IIS.tex} al
     * compile/export (vedi TeacherContentController::buildTexBundle). Default true.
     */
    async saveIncludeHeader(include) {
        return this._patchMeta((meta) => { meta.includeHeader = !!include; });
    }

    /** Carica il flag intestazione+selettori nell'HTML statico pubblicato
     *  (metadata.includeHeaderHtml, default true). */
    async loadIncludeHeaderHtml() {
        try {
            const meta = await this._fetchMeta();
            return meta.includeHeaderHtml !== false;
        } catch { return true; }
    }

    /**
     * Persiste metadata.includeHeaderHtml (checkbox "Includi intestazione e
     * selettori nell'HTML statico pubblicato"): controlla se il primo
     * sectionHeader-con-selettori del body_pt compare nell'HTML reso agli
     * studenti (ContentStudyController::renderCustomTopicHtml). Default true.
     */
    async saveIncludeHeaderHtml(include) {
        return this._patchMeta((meta) => { meta.includeHeaderHtml = !!include; });
    }

    /** ADR-030 — il documento usa il modello "un doc, valori per terna"?
     *  (metadata.terna_scoped). Default false → comportamento legacy invariato. */
    async loadTernaScoped() {
        try { const meta = await this._fetchMeta(); return meta.terna_scoped === true; }
        catch { return false; }
    }

    /** ADR-030 — attiva/disattiva il modello per-terna sul documento. */
    async saveTernaScoped(on) {
        return this._patchMeta((meta) => { meta.terna_scoped = !!on; });
    }

    /** ADR-024 — rinomina documento (campo title, non in metadata). */
    async saveTitle(title) {
        const csrf = await this._csrf();
        const r = await fetch(`/api/teacher/content/${this.docId}/update`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf, title }).toString(),
        });
        if (!r.ok) throw new Error(r.status === 404 ? "Documento non trovato (404)" : `HTTP ${r.status}`);
        return await r.json();
    }

    /** Fetch meta → mutate → POST update (preserva tutti gli altri campi). */
    async _patchMeta(mutator) {
        const csrf = await this._csrf();
        const meta = await this._fetchMeta(); // preserva layout/category/body_pt/etc
        mutator(meta);
        const r = await fetch(`/api/teacher/content/${this.docId}/update`, {
            method: "POST", credentials: "same-origin",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf, metadata: JSON.stringify(meta) }).toString(),
        });
        if (!r.ok) {
            const detail = r.status === 404
                ? "Documento non trovato (404). Se è un'istanza fork da template, l'editing non è supportato qui."
                : `HTTP ${r.status}`;
            throw new Error(detail);
        }
        return await r.json();
    }

    // ── ADR-026 model-sync: rilievo "modello aggiornato" + reset + merge ──

    /** Stato sync vs master institutional. null se il doc non è un fork.
     *  Ritorna { outdated, modelId, masterUpdatedAt, syncedAt, masterTitle,
     *           hasBaseline, syncedFromCreatedAt } o null se metadata.model_template_id
     *  assente. Per fork pre-feature (senza model_synced_at) usa
     *  teacher_content.created_at come sostituto: un master modificato dopo
     *  la nascita del fork è di sicuro più recente del fork. */
    async getModelSyncStatus() {
        const { row, meta } = await this._fetchContent();
        // ADR-026 model-sync: due alias possibili. createForkInstance scrive
        // model_template_id; prepareContentFields (modal custom+templateSeed)
        // scrive template_seed_id (legacy). Da 2026-05-28 entrambi vengono
        // popolati per i fork nuovi; gli alias servono per i fork pre-fix.
        const modelId = parseInt(meta.model_template_id || meta.template_seed_id || 0, 10);
        if (!modelId) return null;
        let syncedAt = meta.model_synced_at || null;
        let syncedFromCreatedAt = false;
        if (!syncedAt && row.created_at) {
            syncedAt = row.created_at;
            syncedFromCreatedAt = true;
        }
        const hasBaseline = Array.isArray(meta.model_body_pt_baseline)
            && meta.model_body_pt_baseline.length > 0;
        let masterUpdatedAt = null, masterTitle = "";
        try {
            // cache:'no-store' + cb param: il fork deve sempre vedere il master
            // freschissimo (l'admin potrebbe aver appena salvato in un'altra tab).
            const r = await fetch(`/api/risdoc/templates/${modelId}?cb=${Date.now()}`,
                { credentials: "same-origin", cache: "no-store" });
            if (r.ok) {
                const j = await r.json();
                masterUpdatedAt = j.template?.updated_at || null;
                masterTitle     = j.template?.argomento || j.template?.code || "";
            }
        } catch (_) { /* network: outdated rimane false */ }
        const { isOutdated } = await import("../../../modules/risdoc/model-sync.js");
        return {
            outdated: isOutdated(masterUpdatedAt, syncedAt),
            modelId, masterUpdatedAt, syncedAt, masterTitle, hasBaseline,
            syncedFromCreatedAt,
        };
    }

    /** Fetch master fresh + body_pt (richiede with_body_pt=1 implicito in show()
     *  che ritorna il row completo). cache:'no-store' → sempre freschissimo. */
    async _fetchMasterBodyPt(modelId) {
        const j = await fetchJson(`/api/risdoc/templates/${modelId}?cb=${Date.now()}`,
            { cache: "no-store" });
        if (j.error) throw new Error(`master ${modelId}: ${j.error}`);
        const raw = j.template?.body_pt;
        if (!raw) throw new Error("Il modello non ha body_pt salvato");
        let pt = raw;
        if (typeof pt === "string") { try { pt = JSON.parse(pt); } catch { pt = []; } }
        if (!Array.isArray(pt) || !pt.length) throw new Error("body_pt del modello vuoto o invalido");
        return pt;
    }

    /** RESET: sovrascrive body_pt del fork con il master corrente.
     *  Aggiorna model_synced_at + model_body_pt_baseline. PERDE le modifiche. */
    async resetFromMaster() {
        const meta = await this._fetchMeta();
        const modelId = parseInt(meta.model_template_id || meta.template_seed_id || 0, 10);
        if (!modelId) throw new Error("Documento non collegato a un modello istituzionale");
        const fresh = await this._fetchMasterBodyPt(modelId);
        return this._patchMeta((m) => {
            m.body_pt = fresh;
            m.model_body_pt_baseline = fresh;
            m.model_synced_at = new Date().toISOString();
        });
    }

    /** MERGE preview: 3-way diff se baseline presente, altrimenti merge
     *  additivo 2-way (preserva tutto del fork + aggiunge sezioni nuove del
     *  master). Ritorna { merged, decisions, _masterNew, mode }. */
    async previewMergeFromMaster() {
        const meta = await this._fetchMeta();
        const modelId = parseInt(meta.model_template_id || meta.template_seed_id || 0, 10);
        if (!modelId) throw new Error("Documento non collegato a un modello istituzionale");
        const baseline = Array.isArray(meta.model_body_pt_baseline) ? meta.model_body_pt_baseline : null;
        const current = Array.isArray(meta.body_pt) ? meta.body_pt : [];
        const masterNew = await this._fetchMasterBodyPt(modelId);
        const sync = await import("../../../modules/risdoc/model-sync.js");
        if (baseline) {
            const { merged, decisions } = sync.threeWayMerge(baseline, masterNew, current);
            return { merged, decisions, _masterNew: masterNew, mode: "3-way" };
        }
        // Fallback per fork pre-feature: 2-way additivo.
        const { merged, decisions } = sync.additiveMerge(masterNew, current);
        return { merged, decisions, _masterNew: masterNew, mode: "additive" };
    }

    /** Applica un merge precedentemente previewato + aggiorna baseline+synced_at. */
    async applyMergeFromMaster(mergedBodyPt, masterNew) {
        return this._patchMeta((m) => {
            m.body_pt = mergedBodyPt;
            if (Array.isArray(masterNew) && masterNew.length) m.model_body_pt_baseline = masterNew;
            m.model_synced_at = new Date().toISOString();
        });
    }

    exportHtmlUrl() {
        return `/api/teacher/content/${this.docId}/export-html`;
    }

    /** POST export ZIP TeX → ritorna { url } per download. */
    async exportTex() {
        const csrf = await this._csrf();
        const j = await fetchJson(`/api/teacher/content/${this.docId}/export`, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ _csrf: csrf, mode: "zip" }).toString(),
        });
        if (!j.ok || !j.url) throw new Error(j.error || "export TeX fallito");
        return j.url;
    }
}

/**
 * Factory: crea l'adapter giusto in base a source.
 * G24 — adapter pattern unificato (ADR-022). teacher-content è il path
 * attivo in produzione; risdoc-template è read-preview opt-in (NON wired,
 * vedi risdoc-template-adapter.js + ADR-022 Fase 5).
 */
export async function createAdapterAsync(source, docId) {
    if (source === "risdoc-template") {
        const { RisdocTemplateAdapter } = await import("./risdoc-template-adapter.js");
        return new RisdocTemplateAdapter(docId);
    }
    return new TeacherContentAdapter(docId);
}

/** Sync factory. ADR-026 "percorso unico": risdoc-template usa RisdocTemplateAdapter
 *  (schema→PT via sectionSchemaToPt) così la pipeline è UNA sola (come custom). */
export function createAdapter(source, docId, opts = {}) {
    if (source === "risdoc-template") return new RisdocTemplateAdapter(docId, opts);
    return new TeacherContentAdapter(docId);
}
