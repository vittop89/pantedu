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

import { caricaEntry } from "../../modules/core/carica-entry.js";

/** Il manifest fresco, una richiesta sola alla volta e l'avviso se non
 *  arriva li dà caricaEntry (23/9/2026, A-46). */
export async function ensurePtEditorLoaded() {
    await caricaEntry("js/entries/risdoc-pt-editor.js", {
        pronto: () => !!customElements.get("fm-risdoc-pt-editor"),
    });
}
