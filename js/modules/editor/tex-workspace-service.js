/**
 * G24.faseB.1 — Service centralizzato per il workspace TikZ del docente.
 *
 * Sostituisce il pattern legacy `window.__fmTikzGroups` (global mutabile)
 * con un servizio singleton typed:
 *   - fetch chain con fallback (workspace > effective-templates > legacy JSON)
 *   - cache in-memory con invalidate esplicita
 *   - pub/sub change: invalida + re-fetch trigger automatico per gli observer
 *
 * Pattern uso:
 *   import { texWorkspace } from "./tex-workspace-service.js";
 *   const data = await texWorkspace.load();          // primo fetch + cache
 *   await texWorkspace.refresh();                     // force re-fetch
 *   const off = texWorkspace.onChange(() => ...);    // sottoscrivi cambi
 *   texWorkspace.invalidate();                       // drop cache (next load re-fetch)
 *
 * Fetch chain (priorità decrescente):
 *   1. /tikz/workspace                — workspace personale lazy-init
 *   2. /tikz/effective-templates      — defaults effettivi (admin+overrides)
 *   3. /modelli_tikz_elements.json    — JSON regenerato post CRUD (legacy)
 *   4. /modelli_tikz.json             — legacy ensureJson admin static
 */

class TexWorkspaceService {
    constructor() {
        /** @type {Object|null} cache dati workspace (group → items[]) */
        this._cache = null;
        /** @type {Promise<Object>|null} in-flight fetch (de-dupe concurrent loads) */
        this._inflight = null;
        /** @type {Set<Function>} change listeners (chiamate post-refresh) */
        this._listeners = new Set();
    }

    /** Get-or-load: ritorna cache se presente, altrimenti fetch chain.
     *  De-dupe concurrent calls (await tutte la stessa Promise). */
    async load() {
        if (this._cache) return this._cache;
        if (this._inflight) return this._inflight;
        this._inflight = this._doFetch().finally(() => { this._inflight = null; });
        return this._inflight;
    }

    /** Force re-fetch: drop cache + load fresco. Emette change agli observer. */
    async refresh() {
        this._cache = null;
        const data = await this.load();
        this._emitChange();
        return data;
    }

    /** Drop cache senza re-fetch. Next `load()` ri-prenderà dal server. */
    invalidate() {
        this._cache = null;
        this._emitChange();
    }

    /** Sync getter cache corrente (null se non ancora loaded). Per call-site
     *  legacy che facevano `window.__fmTikzGroups || {}`. */
    getCached() {
        return this._cache;
    }

    /** Set cache da fonte esterna (es. dopo save che restituisce il workspace
     *  aggiornato): evita un re-fetch round-trip. */
    setCache(data) {
        this._cache = data || null;
        this._emitChange();
    }

    /** Sottoscrivi cambi cache. Ritorna unsubscribe function. */
    onChange(fn) {
        this._listeners.add(fn);
        return () => this._listeners.delete(fn);
    }

    _emitChange() {
        this._listeners.forEach((fn) => {
            try { fn(this._cache); } catch (e) { console.error("[TexWorkspace]", e); }
        });
    }

    async _doFetch() {
        const urls = [
            "/tikz/workspace",
            "/tikz/effective-templates",
            "/modelli_tikz_elements.json",
            "/modelli_tikz.json",
        ];
        for (const url of urls) {
            try {
                const res = await fetch(url, { credentials: "same-origin", cache: "no-store" });
                if (!res.ok) continue;
                const data = await res.json();
                if (data && typeof data === "object") {
                    this._cache = data;
                    return data;
                }
            } catch (e) {
                // Continua al fallback successivo
            }
        }
        throw new Error("TexWorkspace: nessun endpoint risponde");
    }
}

/** Singleton globale del servizio. Importabile da ovunque. */
export const texWorkspace = new TexWorkspaceService();

/** Esposto su window.FM per legacy debug + transition compat. */
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.texWorkspace = texWorkspace;
}
