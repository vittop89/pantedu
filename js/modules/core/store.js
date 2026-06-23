/**
 * Phase 16 Step 5 — Store centralizzato per lo stato frontend.
 *
 * Consolidamento stato precedentemente sparso:
 *   - `window.__fmTeacherSources` → `store.get('cache.teacherSources')`
 *   - `window.__fmHeaderPage`     → `store.get('cache.headerPage')`
 *   - `window.__fmOriginsCache`   → `store.get('cache.origins')`
 *   - `sessionStorage.fmMultiarg` + `body.fm-multiarg` + `AppState.moreArg`
 *                                 → `store.get('verifica.multiarg')` + subscribers
 *
 * API minimale (niente dipendenze):
 *   store.get(key)
 *   store.set(key, value)         // notifica subscriber + persist se configurato
 *   store.subscribe(key, fn)      // fn(newVal, oldVal) → ritorna unsubscribe
 *   store.configure(key, { persist, initial })
 *
 * Persist modes:
 *   'session'  → sessionStorage (`fm.<key>`)
 *   'local'    → localStorage   (`fm.<key>`)
 *   null       → in-memory only (cache)
 *
 * Naming convention chiavi: `<namespace>.<field>` (es. "verifica.multiarg").
 * Non invasivo: i moduli possono migrare gradualmente. Chi non migra continua
 * a leggere `window.__fm*` che restano compat-layer fino a rimozione.
 */

class FMStore {
    constructor() {
        this._state = Object.create(null);
        this._subs = new Map();     // key → Set<fn>
        this._persist = new Map();  // key → 'session'|'local'|null
    }

    get(key) { return this._state[key]; }

    set(key, value) {
        const prev = this._state[key];
        if (prev === value) return;
        this._state[key] = value;
        this._persistValue(key, value);
        const set = this._subs.get(key);
        if (!set) return;
        for (const fn of set) {
            try { fn(value, prev); } catch (e) { console.warn("[store]", key, e); }
        }
    }

    /** Patch shallow su un oggetto-valore (non triggera se il ref resta invariato). */
    patch(key, partial) {
        const cur = this._state[key] || {};
        this.set(key, { ...cur, ...partial });
    }

    subscribe(key, fn) {
        let set = this._subs.get(key);
        if (!set) this._subs.set(key, (set = new Set()));
        set.add(fn);
        return () => set.delete(fn);
    }

    configure(key, { persist = null, initial } = {}) {
        this._persist.set(key, persist);
        if (this._state[key] !== undefined) return;
        const loaded = this._loadValue(key);
        this._state[key] = loaded !== undefined ? loaded : initial;
    }

    _persistValue(key, value) {
        const mode = this._persist.get(key);
        if (!mode) return;
        const storage = mode === "local" ? localStorage : sessionStorage;
        try {
            if (value == null) storage.removeItem(`fm.${key}`);
            else storage.setItem(`fm.${key}`, JSON.stringify(value));
        } catch { /* quota / disabled */ }
    }

    _loadValue(key) {
        const mode = this._persist.get(key);
        if (!mode) return undefined;
        const storage = mode === "local" ? localStorage : sessionStorage;
        try {
            const raw = storage.getItem(`fm.${key}`);
            return raw == null ? undefined : JSON.parse(raw);
        } catch { return undefined; }
    }
}

export const store = new FMStore();

// Configurazione chiavi consolidate Phase 16 Step 5.
store.configure("verifica.multiarg",    { persist: "session", initial: false });
store.configure("cache.teacherSources", { persist: null, initial: null });
store.configure("cache.headerPage",     { persist: null, initial: null });
store.configure("cache.origins",        { persist: null, initial: null });

window.FM = window.FM || {};
window.FM.store = store;
