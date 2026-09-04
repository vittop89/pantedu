/**
 * Entry Vite dedicato per <fm-risdoc-pt-editor> (Phase 22.3b).
 *
 * Bundled separatamente da `bootstrap.js` per evitare di caricare Tiptap
 * (~80kB gz) sulle pagine che non hanno editor risdoc.
 *
 * Side-effect:
 *   - Registra il custom element <fm-risdoc-pt-editor>
 *   - Espone helper su `window.FM.Pt = { ptToHtml, ptToPmDoc, pmDocToPt }`
 *     (Vite tree-shakes ES exports da entry chunks; window global è il
 *     canale robusto per consumer esterni al bundle).
 *
 * Lazy load pattern:
 * ```js
 * const manifest = await fetch("/build/manifest.json").then(r => r.json());
 * const entry = manifest["js/entries/risdoc-pt-editor.js"];
 * await import(`/build/${entry.file}`);
 * // <fm-risdoc-pt-editor> registrato; window.FM.Pt.ptToHtml disponibile.
 * ```
 */
import "../components/risdoc/fm-risdoc-pt-editor.js";
// G23 Sprint 6 — registra anche toolbar custom element (necessario per
// inline editor in pagine layout=custom, pt-inline-editor.js mount).
import "../components/risdoc/fm-risdoc-pt-toolbar.js";
import { ptToHtml } from "../modules/risdoc/pt/pt-to-html.js";
import { ptToPmDoc, pmDocToPt } from "../modules/risdoc/pt/pm-pt-converter.js";
// G23 page-doc — sanitizer client proprio EUPL (defense-in-depth per staticContent NodeView).
import { sanitizeForPageDoc, selfTest as ptSanitizerSelfTest } from "../modules/risdoc/pt/html-sanitizer.js";

window.FM = window.FM || {};
window.FM.Pt = { ptToHtml, ptToPmDoc, pmDocToPt };
// G23 — esposto su window per consumer NodeView (PtStaticContent body sanitize on blur).
window.FM.PtSanitizer = { sanitizeForPageDoc, selfTest: ptSanitizerSelfTest };

// Phase 24.10b — registry globale per toolbar condivisa (sticky).
// currentState: propagato da fm-pt-document → fm-risdoc-pt-section per fetch
// options_source runtime da parte di ptSelect NodeView (file/folder mode).
if (!window.FM.pt) {
    const listeners = new Set();
    const stateListeners = new Set();
    window.FM.pt = {
        currentEditor: null,
        // G23 Sprint 10b — lastEditor: ultimo editor che ha avuto focus, MAI
        // resettato a null su blur. Usato dalla toolbar per applicare B/I/U
        // (sempre attivi) anche quando il focus è su un input NodeView interno.
        lastEditor: null,
        currentState: {},
        onFocusChange(fn) {
            listeners.add(fn);
            fn(this.currentEditor); // emit stato iniziale
            return () => listeners.delete(fn);
        },
        setFocused(editor) {
            if (editor) this.lastEditor = editor; // persiste per B/I/U
            if (this.currentEditor === editor) return;
            this.currentEditor = editor;
            for (const fn of listeners) {
                try { fn(editor); } catch (e) { console.warn("[FM.pt]", e); }
            }
        },
        onStateChange(fn) {
            stateListeners.add(fn);
            fn(this.currentState);
            return () => stateListeners.delete(fn);
        },
        setState(state) {
            this.currentState = state || {};
            for (const fn of stateListeners) {
                try { fn(this.currentState); } catch (e) { console.warn("[FM.pt]", e); }
            }
        },
    };
}
