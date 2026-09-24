/**
 * G24.faseB.3 — DialogHost: register-by-id + lazy `import()` per dialog
 * modali poco frequenti. Permette di tenere fuori dal main bundle dialog
 * pesanti (CM6 editor, GeoGebra modal, template filler) caricandoli solo
 * al primo use.
 *
 * Pattern:
 *   1. Bootstrap registra ID → loader (factory async che import + apre).
 *   2. Caller invoca `dialogHost.open(id, opts)` → loader risolve modulo +
 *      ritorna Promise.
 *   3. Cache lazy: il modulo viene caricato 1 sola volta.
 *
 * Esempio:
 *   dialogHost.register("find-replace", async (opts) => {
 *     const mod = await import("./find-replace-dialog.js");
 *     return mod.openFindReplaceDialog(opts.panel, opts);
 *   });
 *   // poi:
 *   await dialogHost.open("find-replace", { panel, initialQuery: "x" });
 *
 * Benefici:
 *   - Single point per registrazione/discovery dialog
 *   - Dynamic import → main bundle slim
 *   - Future: deduplica concurrent open + lifecycle (close all on route change)
 */

class DialogHost {
    constructor() {
        /** @type {Map<string, Function>} id → loader(opts) → Promise */
        this._loaders = new Map();
        /** @type {Map<string, *>} id → module cache (post-first-load) */
        this._cache = new Map();
    }

    /** Registra un loader per `id`. Se chiamato 2 volte con stesso id,
     *  l'ultimo vince (utile per hot-replace dev). */
    register(id, loader) {
        if (typeof loader !== "function") {
            throw new Error(`DialogHost.register: loader for "${id}" not a function`);
        }
        this._loaders.set(id, loader);
    }

    /** Apre dialog `id` con opts. Throws se id non registrato. */
    async open(id, opts = {}) {
        const loader = this._loaders.get(id);
        if (!loader) throw new Error(`DialogHost: dialog "${id}" non registrato`);
        return loader(opts);
    }

    /** Pre-warm: forza il dynamic import senza aprire (utile per idle
     *  prefetch dopo che la UI principale è pronta). */
    async prefetch(id) {
        const loader = this._loaders.get(id);
        if (!loader) return null;
        // Marker: chiamiamo loader con flag `{__prefetch: true}` per non
        // aprire effettivamente. Loader compliant può supportarlo; default
        // ignora il flag → comportamento equivalente a open.
        try { return await loader({ __prefetch: true }); }
        catch (_) { return null; }
    }

    /** Lista id registrati (debug). */
    list() {
        return Array.from(this._loaders.keys());
    }
}

/** Singleton globale. */
export const dialogHost = new DialogHost();

// Esposto su window.FM per debug + invocazione esterna (es. test E2E).
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.dialogHost = dialogHost;
}

// ── Auto-register dialog disponibili (lazy import al primo open) ──────

dialogHost.register("find-replace", async (opts) => {
    if (opts.__prefetch) { await import("./find-replace-dialog.js"); return; }
    const mod = await import("./find-replace-dialog.js");
    return mod.openFindReplaceDialog(opts.panel, opts);
});

// G24.faseE — idle prefetch dei dialog rari dopo il main bundle load.
// Riduce TTI delle prime aperture senza penalizzare il critical path.
if (typeof window !== "undefined") {
    const idle = window.requestIdleCallback || ((cb) => setTimeout(cb, 1500));
    idle(() => dialogHost.prefetch("find-replace").catch(() => {}));
}
