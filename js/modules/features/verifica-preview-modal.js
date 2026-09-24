/**
 * Phase G21.1 — Verifica Preview Modal (lazy loader).
 *
 * Caricato in `bootstrap.js`. Espone `window.FM.openVerificaPreview(docs)`
 * che lazy-importa il bundle pesante (CodeMirror 6 + parser SyncTeX +
 * helpers) da `js/entries/verifica-preview-editor.js` solo al primo invoke.
 *
 * Il bundle arriva con `caricaEntry` (core/carica-entry.js); dopo,
 * window.FM.VerificaPreview.openPreview(docs) è disponibile.
 *
 * G22.S15.bis Fase 5 — il bottone "Anteprima" in topbar è stato rimosso.
 * Restano solo i call-site programmatici tramite `window.FM.openVerificaPreview`:
 *   - verifica-detail-modal.js (click su una verifica nel popup dettaglio)
 *   - risdoc-toolbar-actions.js (toolbar risdoc)
 */

import { caricaEntry } from "../core/carica-entry.js";

function ensurePreviewBundleLoaded() {
    return caricaEntry("js/entries/verifica-preview-editor.js", {
        pronto: () => !!window.FM?.VerificaPreview?.openPreview,
    });
}

function ensureToast(kind, title, msg, ms = 4500) {
    if (window.FM?.ToastManager?.show) {
        window.FM.ToastManager.show(kind, title, msg, ms);
    } else {
        console.warn(`[verifica-preview] ${title}: ${msg}`);
    }
}

async function openPreviewLazy(docs, opts = {}) {
    try {
        await ensurePreviewBundleLoaded();
        return window.FM.VerificaPreview.openPreview(docs, opts);
    } catch (e) {
        ensureToast("error", "Anteprima",
            `Caricamento bundle fallito: ${e.message}`, 8000);
        console.error("[verifica-preview] load failed:", e);
    }
}

// G22.S15.bis Fase 5 — click delegation per il bottone topbar
// `[data-fm-action="anteprima"]` rimossa: il bottone non esiste più.
// Idem auto-open su `fm:verifica-pdf-batch` (l'evento non viene più
// dispatchato). L'editor si apre solo via call programmatici da
// verifica-detail-modal / risdoc-toolbar-actions.

// Esponi loader per chiamate programmatiche (verifica-detail-modal,
// risdoc-toolbar-actions, debug).
window.FM = window.FM || {};
window.FM.openVerificaPreview = openPreviewLazy;
