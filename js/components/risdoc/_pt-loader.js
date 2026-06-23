/**
 * Shared lazy-loader per il bundle <fm-risdoc-pt-editor> (Phase 23.4).
 *
 * Importato da tutti i wrapper risdoc che mountano un PT editor interno
 * (fm-risdoc-nota-pt-rich, fm-risdoc-checkbox-group refactored, ecc.).
 * Evita duplicazione + garantisce single-import del bundle Vite.
 *
 * Usage:
 *   import { ensurePtEditorLoaded } from "./_pt-loader.js";
 *   await ensurePtEditorLoaded();
 *   // <fm-risdoc-pt-editor> ora è registrato come custom element
 */

let _loadingPromise = null;

export async function ensurePtEditorLoaded() {
    if (customElements.get("fm-risdoc-pt-editor")) return;
    if (_loadingPromise) return _loadingPromise;

    _loadingPromise = (async () => {
        // cache-bust + no-store: senza, il browser teneva il manifest vecchio
        // dopo un deploy → caricava il bundle PT con hash precedente (nuovi
        // pulsanti allineamento/elenchi non visibili finché non si forzava
        // Ctrl+Shift+R). Il manifest è minuscolo: fetch fresco a ogni load.
        const manifestUrl = `/build/manifest.json?t=${Date.now()}`;
        const res = await fetch(manifestUrl, { credentials: "same-origin", cache: "no-store" }).catch(() => null);
        if (!res?.ok) {
            throw new Error(`manifest HTTP ${res?.status ?? "err"} (${manifestUrl}) — esegui "npm run build"`);
        }
        const manifest = await res.json();
        const entry = manifest["js/entries/risdoc-pt-editor.js"];
        if (!entry) {
            throw new Error(`entry risdoc-pt-editor assente nel manifest`);
        }
        await import(`/build/${entry.file}`);
    })();

    try {
        await _loadingPromise;
    } catch (e) {
        _loadingPromise = null; // allow retry
        throw e;
    }
}
