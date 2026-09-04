/**
 * G22.S15.bis Fase 5 — Sync log storage in localStorage.
 *
 * Persiste eventi sync (drive/local/github/all) per visualizzazione in
 * /teacher/dashboard sezione "📋 Log sincronizzazioni". Cap a 100 entry
 * (FIFO) per evitare bloat.
 *
 * Disaccoppiato da sync-panel.js: la persistenza è una concern separata
 * dalla UI istantanea. Caller chiama entrambe quando opportuno.
 */

const KEY = "fm:syncLog";
const CAP = 100;

/**
 * @param {'drive'|'local'|'github'|'all'} target
 * @param {'info'|'ok'|'error'} kind
 * @param {string} message
 * @param {object} [extra] dati addizionali serializzati nell'entry
 */
export function persistSyncLog(target, kind, message, extra = {}) {
    if (typeof localStorage === "undefined") return;
    try {
        const raw = localStorage.getItem(KEY);
        const arr = raw ? JSON.parse(raw) : [];
        arr.push({
            ts: new Date().toISOString(),
            target, kind, message,
            ...extra,
        });
        if (arr.length > CAP) arr.splice(0, arr.length - CAP);
        localStorage.setItem(KEY, JSON.stringify(arr));
        try { window.dispatchEvent(new CustomEvent("fm:sync-log-updated")); } catch (_) {}
    } catch (_) { /* storage piena/disabilitata: silent */ }
}

export function readSyncLog() {
    if (typeof localStorage === "undefined") return [];
    try {
        const raw = localStorage.getItem(KEY);
        return raw ? JSON.parse(raw) : [];
    } catch (_) { return []; }
}

export function clearSyncLog() {
    if (typeof localStorage === "undefined") return;
    try {
        localStorage.removeItem(KEY);
        window.dispatchEvent(new CustomEvent("fm:sync-log-updated"));
    } catch (_) { /* silent */ }
}

// Compat globale per legacy callers
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.SyncLog = { persist: persistSyncLog, read: readSyncLog, clear: clearSyncLog };
}
