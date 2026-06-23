/**
 * Phase G21.1 — Verifica Preview Modal (lazy loader).
 *
 * Caricato in `bootstrap.js`. Espone `window.FM.openVerificaPreview(docs)`
 * che lazy-importa il bundle pesante (CodeMirror 6 + parser SyncTeX +
 * helpers) da `js/entries/verifica-preview-editor.js` solo al primo invoke.
 *
 * Pattern lazy load (uguale a risdoc-pt-editor):
 *   1. fetch /build/manifest.json
 *   2. import dinamico del file hashato
 *   3. window.FM.VerificaPreview.openPreview(docs) disponibile
 *
 * G22.S15.bis Fase 5 — il bottone "Anteprima" in topbar è stato rimosso.
 * Restano solo i call-site programmatici tramite `window.FM.openVerificaPreview`:
 *   - verifica-detail-modal.js (click su una verifica nel popup dettaglio)
 *   - risdoc-toolbar-actions.js (toolbar risdoc)
 */

let _loadingPromise = null;

async function ensurePreviewBundleLoaded() {
    if (window.FM?.VerificaPreview?.openPreview) return;
    if (_loadingPromise) return _loadingPromise;

    _loadingPromise = (async () => {
        // G21.1 — cache-buster sul manifest per evitare versione vecchia
        // dopo rebuild. L'hash nel filename del bundle è già una guard ma il
        // manifest stesso può essere cached dal browser tra sessioni.
        const cacheBust = `?t=${Date.now()}`;
        const res = await fetch(`/build/manifest.json${cacheBust}`, {
            credentials: "same-origin",
            cache: "no-store",
        });
        if (!res.ok) {
            throw new Error(`manifest HTTP ${res.status} — esegui "npm run build"`);
        }
        const manifest = await res.json();
        const entry = manifest["js/entries/verifica-preview-editor.js"];
        if (!entry) {
            throw new Error("entry verifica-preview-editor assente nel manifest");
        }
        console.info("[verifica-preview] loading bundle:", entry.file);
        await import(/* @vite-ignore */ `/build/${entry.file}`);
        if (!window.FM?.VerificaPreview?.openPreview) {
            throw new Error("bundle caricato ma window.FM.VerificaPreview non popolato");
        }
    })();

    try {
        await _loadingPromise;
    } catch (e) {
        _loadingPromise = null;
        throw e;
    }
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
