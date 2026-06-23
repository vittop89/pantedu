/**
 * G24.faseD — EditorSession orchestrator: state-machine per il ciclo
 * di vita di un editor inline (item o group).
 *
 * Sostituisce il pattern legacy di state sparso (dataset.fmEditing,
 * problem._fmGroupAutosaveKey, _fmPreEditSnapshot, ecc) con un'API
 * esplicita classe-based che encapsula:
 *
 *   - mount(): apri panel inline + acquire lock multi-tab
 *   - capture(): snapshot fields correnti (per save / revert)
 *   - applyToDOM(fields): patch DOM post-save
 *   - save(): apiPost con conflict resolution
 *   - revert(): ripristina snapshot pre-edit
 *   - unmount(): rilascia lock + rimuove panel + sync UI toolbar
 *
 * Sub-class:
 *   ItemEditorSession    — target = .fm-collection__item, kind = "item"
 *   GroupEditorSession   — target = .fm-groupcollex, kind = "group"
 *
 * Migrazione progressiva: le funzioni legacy (openItemEditor, ecc) sono
 * mantenute come thin wrappers che istanziano la session corretta. Le
 * call-site interne (ContractAggregate, FIELD_APPLIERS) possono usare
 * la session API o le legacy function indistintamente.
 *
 * FIELD_APPLIERS registry: applier sono per-kind (item/group) e possono
 * essere registrati al boot di ogni dominio (RM, TikZ, metadata, ecc) per
 * estensibilità open/closed.
 */

/** Registry globale sessioni attive (target → session). Permette
 *  introspection ("sessione attualmente sul .fm-collection__item X?") + cleanup
 *  forzato su unload page. */
const _activeSessions = new WeakMap();

/** Map<kind, Map<fieldName, applier>>. Applier = (target, value) => void.
 *  Domain modules auto-registrano al boot:
 *    EditorSession.registerApplier("item", "options", applyRmTableEdits);
 *    EditorSession.registerApplier("group", "intro", applyGroupIntro);
 */
const _appliers = new Map();

export class EditorSession {
    /**
     * @param {Element} target — .fm-collection__item o .fm-groupcollex
     * @param {object} config
     * @param {string} config.kind — "item" | "group"
     * @param {string} config.lockKey — chiave lock multi-tab
     * @param {Function} [config.onMount]
     * @param {Function} [config.onUnmount]
     * @param {Function} [config.capture]
     * @param {Function} [config.save]
     */
    constructor(target, config) {
        if (!target) throw new Error("EditorSession: target richiesto");
        if (!config?.kind) throw new Error("EditorSession: kind richiesto");
        this.target = target;
        this.kind = config.kind;
        this.lockKey = config.lockKey || null;
        this.config = config;
        /** Snapshot fields al mount per revert. */
        this.preEditSnapshot = null;
        /** Panel DOM mounted (set da mount). */
        this.panel = null;
        /** State machine: "idle" → "editing" → "closed" */
        this.state = "idle";
    }

    /** Acquisisce session: lock, mount panel, snapshot. */
    mount(panel) {
        if (this.state !== "idle") return;
        this.panel = panel;
        this.state = "editing";
        _activeSessions.set(this.target, this);
        // capture snapshot post-mount (async, panel deve essere già popolato)
        setTimeout(() => {
            if (this.state === "editing") {
                this.preEditSnapshot = this.capture();
            }
        }, 100);
        this.config.onMount?.(this);
    }

    /** Cattura fields correnti via config.capture (delega specifica). */
    capture() {
        try { return this.config.capture?.(this) || null; }
        catch (e) { console.error("EditorSession.capture", e); return null; }
    }

    /** Save con strategy 409 (delegato a config.save che incapsula apiPost). */
    async save() {
        try { return await this.config.save?.(this); }
        catch (e) { console.error("EditorSession.save", e); return false; }
    }

    /** Applica fields al DOM tramite applier registry (per-kind). */
    applyToDOM(fields) {
        const kindAppliers = _appliers.get(this.kind);
        if (!kindAppliers) return;
        for (const [field, value] of Object.entries(fields || {})) {
            const applier = kindAppliers.get(field);
            if (applier) {
                try { applier(this.target, value, this); }
                catch (e) { console.error(`applier(${this.kind}/${field})`, e); }
            }
        }
    }

    /** Ripristina snapshot pre-edit (revert). */
    revert() {
        if (!this.preEditSnapshot) return false;
        this.applyToDOM(this.preEditSnapshot);
        return true;
    }

    /** Smonta: rilascia lock + sync UI + run cleanup. */
    unmount() {
        if (this.state === "closed") return;
        this.state = "closed";
        this.config.onUnmount?.(this);
        _activeSessions.delete(this.target);
        this.panel = null;
    }

    /** Sessione attiva per target (introspection). */
    static for(target) {
        return _activeSessions.get(target) || null;
    }

    /** Registra applier per kind + field. Open/closed extension. */
    static registerApplier(kind, field, fn) {
        if (!_appliers.has(kind)) _appliers.set(kind, new Map());
        _appliers.get(kind).set(field, fn);
    }

    /** Lista applier registrati (debug). */
    static listAppliers() {
        const out = {};
        _appliers.forEach((m, kind) => { out[kind] = Array.from(m.keys()); });
        return out;
    }
}

/** Item editor session — wrapper specializzato. */
export class ItemEditorSession extends EditorSession {
    constructor(item, config = {}) {
        super(item, { ...config, kind: "item" });
    }
}

/** Group editor session — wrapper specializzato. */
export class GroupEditorSession extends EditorSession {
    constructor(problem, config = {}) {
        super(problem, { ...config, kind: "group" });
    }
}

// Esposto su window.FM per debug + invocazione esterna
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.EditorSession = EditorSession;
    window.FM.ItemEditorSession = ItemEditorSession;
    window.FM.GroupEditorSession = GroupEditorSession;
}
