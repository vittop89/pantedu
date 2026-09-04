/**
 * ToastManager — G22.S15.bis Fase 5 refactor: tutte le notifiche delegano
 * al `SyncPanel` unificato (sync-panel.js). API surface preservata per
 * back-compat con caller legacy.
 *
 * Vantaggi vs implementazione jQuery precedente:
 *   - Zero dipendenze jQuery (la dashboard docente non lo carica)
 *   - Stile coerente con sync log (slide-in da destra, log streaming)
 *   - Auto-hide configurabile, accumulabile (non sovrascrive sync attivo)
 *
 * Caller esistenti (editor-system, verifica-pdf-modal, ecc.) continuano a
 * funzionare senza modifiche.
 */

import { notify as panelNotify } from "./sync-panel.js";

const KIND_ICON = {
    success: "✓",
    error:   "✕",
    warning: "⚠",
    loading: "⏳",
    info:    "ℹ",
};

/**
 * Mappa tipo ToastManager → kind del SyncPanel.
 *   - 'success'           → 'ok'
 *   - 'error'             → 'error'
 *   - 'warning' / 'info'  → 'info'
 *   - 'loading'           → 'info' (sticky, no auto-hide)
 */
function mapKind(type) {
    if (type === "success") return "ok";
    if (type === "error")   return "error";
    return "info";
}

function dispatch(type, title, message, duration) {
    const icon = KIND_ICON[type] || "";
    const fullTitle = title ? (icon ? `${icon} ${title}` : title) : icon;
    const ttl = (typeof duration === "number" && duration > 0)
        ? duration
        : (type === "loading" ? 0 : 4000);
    try {
        panelNotify(fullTitle, mapKind(type), message ?? "", ttl);
    } catch (_) {
        console.info(`[toast ${icon} ${title}]`, message ?? "");
    }
}

export const ToastManager = {
    container: null,        // legacy: kept for back-compat (no-op)
    toasts: new Map(),      // legacy: kept for back-compat ID

    init() { /* no-op: SyncPanel auto-init al primo notify */ },

    show(type, title, message, duration = null) {
        const id = "toast-" + Date.now() + "-" + Math.random().toString(36).slice(2, 6);
        dispatch(type, title, message, duration);
        return id;
    },

    showLoading(message = "Salvataggio in corso...") {
        return this.show("loading", "Salvataggio", message);
    },
    showSuccess(message = "Salvato con successo!", duration = 3000) {
        return this.show("success", "Successo", message, duration);
    },
    showError(message, duration = 5000) {
        return this.show("error", "Errore", message, duration);
    },
    showWarning(title, message, duration = 5000) {
        return this.show("warning", title, message, duration);
    },

    /** Legacy: il SyncPanel auto-gestisce cleanup → no-op safe. */
    remove(_toastId) { /* no-op */ },

    /** Legacy "loading → success/error" transition: emette nuova riga. */
    update(_id, type, title, message) {
        dispatch(type, title, message, type === "error" ? 5000 : 3000);
    },
};

window.FM = window.FM || {};
window.FM.ToastManager = ToastManager;
window.ToastManager    = ToastManager;
