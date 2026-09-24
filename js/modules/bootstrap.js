/**
 * ES6 module entry point — Phase 8a scaffold.
 *
 * Per ora questo file espone solo un namespace globale `window.FM`
 * che le sotto-fasi successive popoleranno con i moduli estratti.
 * Funge da bridge fra:
 *   - vecchio mondo (script.js, functions-mod.js): usa globals
 *   - nuovo mondo (js/modules/{feature}/*.js): usa import/export
 *
 * Quando 8b inizierà a estrarre Config / AppState / Utils / Api,
 * questo file li importerà e li ri-pubblicherà su window.FM.X
 * affinché il legacy possa continuare a leggerli.
 *
 * In 8i il bootstrap diventa l'unico entry: tutti i template
 * caricano <script type="module" src="/js/modules/bootstrap.js"
 * defer></script> e i bundle vecchi spariscono.
 */

window.FM = window.FM || Object.create(null);
window.FM.version = '9d';
window.FM.modules = Object.create(null);

// Importa moduli ES6 disponibili. Ognuno espone se stesso anche su
// window.FM.X per compatibilità con il legacy script.js / functions-mod.js.
import "./core/api.js";                    // window.FM.Api
import "./core/endpoints.js";              // window.FM.Endpoints
// api-memo: window.FM.memoFetchJson + invalidateMemo (side-effect import).
import "./core/api-memo.js";
// curriculum-codes: fonte UNICA dinamica code→label (window.FM.Curriculum),
// preload /curriculum al boot. Rimpiazza le mappe legacy hardcoded.
import "./core/curriculum-codes.js";
// teacher-caps: capability effettive docente (window.FM.Caps) per limitare le
// opzioni UI a max_visibility/doc_types (ADR-028). Preload al boot.
import "./core/teacher-caps.js";
// section-scope-hint: hint contestuale per-sezione (per-classe vs sempre
// visibili) nella sidebar docente (ADR-028 onboarding).
import "./features/section-scope-hint.js";
// legal-notice: il banner che annuncia una versione nuova dei Termini o
// dell'AUP prima che entri in vigore.
//
// 2026-09-22 — questa riga non c'era, e il banner non si è MAI visto. Il
// markup (`views/partials/_legal_notice_banner.php`) nasce con `hidden`, e
// l'unico `el.hidden = false` del progetto sta dentro questo modulo, che
// nessuno importava: misurato anche sul pacchetto costruito, dove
// `fm-legal-notice` non compariva mentre `fm-bb-menu` sì.
//
// Non era un dettaglio di forma: i Termini §14 e l'AUP §11 promettono
// l'avviso in applicazione trenta giorni prima, ed è un impegno verso chi li
// ha accettati. Una promessa contrattuale tenuta da un file che nessuno carica
// è esattamente il verde che non misura, su un documento che si firma.
import "./core/legal-notice.js";
import "./features/password-visibility.js";
import "./core/utils.js";                  // window.FM.Utils
import { Config as FMConfig }     from "./core/config.js";
import "./core/app-state.js";              // window.FM.AppState
// I selettori seguono l'istituto scelto: senza, cambiare scuola lasciava
// indirizzo e classe di quella precedente, cioe' combinazioni inesistenti.
import "./core/sidebar-cascade.js";
import "./core/path-manager.js";           // window.FM.PathManager
import "./core/data-manager.js";           // window.FM.DataManager
// Phase 25.E11 — logout-time cleanup di localStorage user-scoped (mitiga
// leak cross-user su browser condivisi; defense-in-depth con il sweep
// all'init di fm-pt-document/risdoc — Phase 25.E10).
import "./core/logout-cleanup.js";
// 2026-09-05 — attributi data-fm-* (conferme, chiusure, autosubmit, copia...)
// al posto degli <script> inline di una riga nelle viste.
import "./core/declarative.js";
import "./a11y/form-labels.js"; // WCAG 2.2 AA — nomi accessibili controlli form generati (ADR-023)
// Phase Roadmap perf Fase 3e — PrintInfoManager spostato a lazy block
// (gated su exercise-context). Print info modal usato solo in verifiche.
import "./core/utilities.js";              // window.FM.utilities
import "./ui/batch-delete.js";             // window.FM.BatchDeleteManager
import "./state/clone-manager.js";         // window.FM.CloneManager
import "./ui/ver-generation-overlay.js";   // window.FM.VerGenerationOverlay
import "./ui/container-height.js";         // window.FM.ContainerHeightManager
import "./ui/selection-manager.js";        // window.FM.DomManager
// Phase Roadmap perf Fase 3a — editor/* (LatexRender, ContentProcessor,
// EditorSystem, TableManager) spostati a lazy load condizionale (~600 KB
// raw, ~190 KB gzip). Caricati solo se body.exercise-context o presenza
// .fm-editor-panel/[data-fm-editor]/[data-fm-needs-editor] nel DOM.
// Assegnati a window.FM.* al ready (consumer asincroni via 'fm:editor:ready').
import "./ui/list-manager.js";             // window.FM.ListManager
import "./state/state-manager.js";         // window.FM.StateManager
import "./ui/ui-comp.js";                  // window.FM.UIComp
import "./events/event-handler.js";        // window.FM.EventHendler
// G22.S6 — print-export.js eliminato. Il flusso GENERA/SalvaTEX usa
// topbar-modern.js → /api/verifica/save-tex-batch + queue async S5.
import "./core/path-file-ver-extractor.js"; // window.FM.PathFileVerExtractorClass
// Phase 16 Step 5 — store centralizzato (caricato presto così gli altri
// moduli possono subscribe già durante la loro init).
import { store as FMStore }       from "./core/store.js";
import "./ui/dom-manager.js";              // window.FM.DOMManager
// 2026-05-24 fix: google-apps RIMESSO statico — espone window.App che
// dom-manager.js usa per binding sidebar click (App.loadSidebarContent,
// handleLinkrefClick, handleSelectChange). Lazy gating
// rompeva l'apertura .fm-sb-panel dal click su .fm-sb-sec se App non era
// loaded in tempo (race: legacyBoot init dom-manager handler PRIMA che
// Drive lazy chunks completino fetch).
import { App as GoogleAppsApp }    from "./integrations/google-apps.js";
// Phase Roadmap perf Fase 3b — Drive sub-integrations restano lazy
// (~50 KB raw): google-drive-latex-saver + drive-sync-buttons +
// import-bundle-flow. Caricate su body.fm-can-edit.
// Phase Roadmap perf Fase 3e — PrintClient spostato a lazy (exercise-context).
// VerifichePrintUI → lazy (exercise-context).
import "./ui/toast.js";                    // window.FM.ToastManager
import "./ui/checkmod.js";                 // window.FM.CheckmodManager
import { legacyBoot }             from "./bootstrap-compat.js";
// Phase 25.A1 — DOM utilities centralizzate (esc/escAttr/parseMeta/
// readSelect/fetchCsrf con cache 60s). Importate da feature modules.
import "./core/dom-utils.js";

// Phase 24.71 — sidepage-registry caricato per primo: tutti i feature
// modules (section-edit-mode, db-sidepage, risdoc-sidepage) lo importano
// per risolvere panel/loader/type/fork-capability senza duplicare i mapping.
import "./features/sidepage-registry.js";
// ADR-027 Step 4 — idrata il registry dalle sezioni configurate (admin):
// rende funzionali le sezioni custom e riflette le differenze per-istituto.
// Async non-bloccante: in fallback (guest/DB down) resta la base hardcoded.
import { hydrate as hydrateSidepages } from "./features/sidepage-registry.js";
(async () => {
    try {
        // Guest (sidebar pubblica) o pagina senza sidebar (login, shell modale):
        // niente fetch di endpoint auth-only → evita 401 in console (2026-09-05).
        // Le sezioni publish_public sono già rese server-side.
        const sidebar = document.querySelector("nav.sidebar");
        if (!sidebar || sidebar.dataset.fmGuest === "1") return;
        const r = await fetch("/api/sidebar/config", { credentials: "same-origin", headers: { Accept: "application/json" } });
        if (!r.ok) return;
        const j = await r.json();
        if (j && Array.isArray(j.sections) && j.sections.length) hydrateSidepages(j.sections);
    } catch (_) { /* silent: base hardcoded */ }
})();
// Phase 24.72 — custom-categories storage condiviso (verif/bes/risdoc).
import "./features/sidepage-custom-categories.js";

// WS4/CSP — populator selettori sidebar pubblica (guest) da /curriculum.
import "./features/public-sidebar-selectors.js";

// Phase 24.72 — pre-fetch user-info: serve a localStorage key prefissato
// per-utente in sidepage-custom-categories (e altri consumer). Eseguita
// async, no-blocking; in caso di guest/unauth la chiamata fallisce silently
// e i moduli usano fallback global key.
(async () => {
    try {
        const r = await fetch("/auth/user-info", { credentials: "same-origin" });
        if (!r.ok) return;
        const j = await r.json();
        window.FM = window.FM || {};
        window.FM.user = window.FM.user || {};
        window.FM.user.username = j.username || "";
        window.FM.user.role = j.role || "guest";
        window.FM.user.is_super_admin = !!j.is_super_admin;
    } catch (_) { /* silent */ }
})();
// Phase Roadmap perf Fase 3e — verifica-builder lazy (exercise-context).
import "./features/section-edit-mode.js";
import "./features/student-resource-auth.js";
import "./features/db-sidepage.js";
// 19/9/2026 — per chi studia, le materie con materiali al cambio di classe.
import "./features/materie-di-chi-studia.js";
import "./features/sidepage-highlight.js";
// 23/9/2026 (A-19) — «Scarica .drawio» e il visualizzatore delle mappe grandi:
// erano due <script> in linea scritti da StudyPageRenderer, ora i dati sono
// un'isola JSON della pagina e il comportamento sta qui.
import "./features/mappe-della-pagina.js";
import "./features/admin-banner-badge.js";
import "./features/collapsible.js";
import "./features/upbar-controls.js";
// Phase Roadmap perf Fase 3a — checkin-handlers importa 30+ moduli editor/*
// (~600 KB raw). Spostato a lazy block sotto (gated su exercise-context).
import "./features/verifica-sticky.js";
import "./features/problem-drag.js";
// Phase Roadmap perf Fase 3c — risdoc-editor + admin-risdoc lazy (path-gated).
// 2026-05-24 fix: risdoc-sidepage RIMESSO statico — il binding click su
// .fm-sb-sec[data-sidepage] (loader=risdoc) è accessibile da QUALSIASI
// pagina (sidebar globale), non solo /risdoc/*. Lazy gating rompeva
// l'apertura della sidepage risdoc dalla home/dashboard.
import "./features/risdoc-sidepage.js";
// Phase Roadmap perf Fase 3b — drive-sync-buttons + import-bundle-flow
// spostati a lazy block sotto (gated su body.fm-can-edit).
// Phase Roadmap perf Fase 3d — drawio-editor + verifica-*-modal spostati
// a lazy block (gated su exercise-context). ~160 KB raw / ~50 KB gzip.
// Phase G8 — Modern unified topbar (sostituisce gradualmente .selwrapbtncopy).
import "./features/topbar-modern.js";
// G24 — <fm-pt-document> WebComponent unificato (ADR-022). Registra il
// custom element per le pagine personalizzate (layout=custom). SSR-first:
// graceful degradation se JS off (mostra il body HTML server-rendered).
// L'editor PT interno è lazy (ensurePtEditorLoaded al primo "Modifica").
// 2026-09-04 — import dinamico: Lit viene da npm (non più da un CDN) e il
// suo specifier bare si risolve solo nel bundle Vite. Con i moduli sorgente
// caricati direttamente (sviluppo senza build) l'import fallirebbe, e da
// statico avrebbe fatto cadere tutto bootstrap; così resta un avviso e il
// body server-rendered del documento continua a vedersi.
import("../components/pt-document/fm-pt-document.js").catch((err) => {
    console.warn("[bootstrap] fm-pt-document non caricato (serve il bundle Vite):", err);
});
// Phase G19.36 — File System Access API helper (showDirectoryPicker +
// IndexedDB persistent handle). Esposto su `window.FM.FsAccess`.
import "./features/fs-access-helper.js";
// Phase G19.45 — FM.Dialog: replace browser alert/confirm/prompt nativi
// con modal popup curati (dark theme aware). Esposto su `window.FM.Dialog`.
import "./ui/fm-dialog.js";
// Phase Roadmap perf Fase 3e — verifica-genera-modal + verifica-scelte
// spostati a lazy block (exercise-context).
// G20.7 — Random selection mode (🎲 toggle + 🎯 pick) con distinct titolo_quesito.
import "./features/random-selection.js";
// Phase 25 — Motore scorciatoie LaTeX (hotstring + hotkey) in ogni editor +
// popup cheat-sheet (#fm-shortcuts-btn nella sidebar). Self-init.
import "./features/latex-shortcuts.js";
// Phase 25 — Editor del modello scorciatoie (mount on-demand dalle view
// /area-docente/templates + /admin/templates). Espone window.FM.ShortcutsEditor.
import "./features/shortcuts-editor.js";
// Track 7 CSP — delegation per gli handler del template Elementi_Riservati.html
// (frammento editor caricato via innerHTML: niente on* inline né <script> co-locati).
import "./editor/reserved-template-actions.js";
// Phase 25 — Gestione categorie dedicata (/area-docente/categorie).
// Espone window.FM.CategoryManager (mount on-demand dalla view).
import "./features/category-manager.js";
// Bottom-bar: disclosure link legali/trust (.fm-bb-menu). Self-init al
// DOM ready; hover-intent + focus-within sono CSS-only (fallback no-JS).
import "./features/bottom-bar-panel.js";
// Phase G19.3 — DSA marks (F/GF) per `.fm-li-inline` (modern, no jQuery).
// Sostituisce ui-comp.js _caricaElemRiservati DSA-injection con
// un module idempotente document-level che persiste in sessionStorage.
import "./features/dsa-marks.js";
// Phase Roadmap perf Fase 3e — exercise-wizard + editor-draft-autosave +
// verifica-vscode-launch + verifica-templates-modal spostati a lazy block
// (exercise-context).
// ADR-026 #3 — risdoc export/save/topbar gestiti da fm-pt-document
// (_topbarButtons) internamente. risdoc-text-editor.js eliminato (feature
// dormiente post-engine-delete).

// 23/9/2026 (revisione architetturale, A-48) — qui c'erano ventuno
// assegnazioni `window.FM.X = X` di moduli che si registrano già da sé
// quando vengono valutati (vedi l'ultima riga di ciascuno): ogni chiave era
// scritta due volte, e non si capiva quale delle due contasse. Resta solo chi
// non si registra da sé. Guardia: tests/js-unit/registro-window-fm.test.js.
window.FM.GoogleAppsApp          = GoogleAppsApp;

console.debug('[FM] bootstrap loaded (Phase 9d — 14 namespace extracted)');

// Phase Roadmap perf Fase 3a — editor + checkin-handlers lazy load
// (~600 KB raw / ~190 KB gzip risparmiati su pagine non-esercizio).
// Condizioni di attivazione:
//   - body.exercise-context  (esercizi, risdoc edit/view)
//   - .fm-editor-panel       (editor panels dinamici)
//   - [data-fm-editor]       (markup opt-in custom)
//   - [data-fm-needs-editor] (markup hint future-proof)
// Promise.all parallel → 1 roundtrip waterfall (browser fetcha tutti i
// chunk in parallel via HTTP/2 multiplex). Dispatch 'fm:editor:ready'
// per consumer asincroni (window.FM.* popolate solo dopo .then()).
const _fmEditorNeeded = document.body?.classList.contains('fm-exercise-context')
    || document.body?.classList.contains('exercise-context')
    || !!document.querySelector('.fm-editor-panel, [data-fm-editor], [data-fm-needs-editor]');

// 2026-09-05 — il caricamento e' richiamabile: window.FM.loadEditor() lo
// avvia a richiesta (pagine che scoprono di servirsene dopo, test E2E che
// usano i ganci __*ForTest fuori dal contesto esercizio) e restituisce
// sempre la stessa promise.
let _fmEditorPromise = null;
function loadEditorBundle() {
    if (_fmEditorPromise) return _fmEditorPromise;
    _fmEditorPromise = Promise.all([
        import('./editor/latex-render.js'),
        import('./editor/content-processor.js'),
        import('./editor/editor-system.js'),
        import('./editor/table-manager.js'),
        import('./features/checkin-handlers.js'),
        // Phase 3d — drawio + verifica modals: heavy modals usati in
        // exercise context. Bundle insieme a editor per single roundtrip.
        import('./features/drawio-editor.js'),
        import('./features/verifica-documents-sidepage.js'),
        import('./features/verifica-pdf-modal.js'),
        import('./features/verifica-preview-modal.js'),
        import('./features/verifica-detail-modal.js'),
        // Phase 3e — verifica-* + print + autosave + wizard. Tutti
        // exercise-context only. Bundle per single roundtrip.
        import('./features/verifica-builder.js'),
        import('./features/verifica-genera-modal.js'),
        import('./features/verifica-scelte.js'),
        import('./features/verifica-vscode-launch.js'),
        import('./features/verifica-templates-modal.js'),
        import('./features/exercise-wizard.js'),
        import('./features/editor-draft-autosave.js'),
        import('./print/print-info.js'),
        import('./print/print-client.js'),
        import('./print/verifiche-print-ui.js'),
    ]).then(() => {
        // LatexRender, ContentProcessor, EditorSystem, TableManager,
        // PrintInfoManager, PrintClient, VerifichePrintUI: ciascuno si
        // registra su window.FM da sé (A-48, 23/9/2026).
        document.dispatchEvent(new CustomEvent('fm:editor:ready'));
    }).catch((e) => {
        console.warn('[FM] editor lazy load failed:', e);
    });
    return _fmEditorPromise;
}
window.FM.loadEditor = loadEditorBundle;
if (_fmEditorNeeded) loadEditorBundle();

// Phase Roadmap perf Fase 3b — Drive integrations lazy load (~112 KB raw).
// Trigger: body.fm-can-edit (teacher/admin). Studenti (fm-no-edit): nessun
// caricamento Drive (UI sync banner/buttons mai mostrati comunque).
const _fmDriveNeeded = document.body?.classList.contains('fm-can-edit');

if (_fmDriveNeeded) {
    Promise.all([
        import('./integrations/google-drive-latex-saver.js'),
        import('./features/drive-sync-buttons.js'),
        import('./features/import-bundle-flow.js'),
    ]).then(() => {
        // window.FM.GoogleDriveLatexSaver la scrive il modulo (A-48).
        document.dispatchEvent(new CustomEvent('fm:drive:ready'));
    }).catch((e) => {
        console.warn('[FM] drive lazy load failed:', e);
    });
}

// Phase Roadmap perf Fase 3c — risdoc-editor + admin-risdoc lazy load.
// 2026-05-24 fix: risdoc-sidepage tornato STATIC (vedi sopra) — il click
// handler sulla sidebar btn risdoc serve ovunque. Qui restano editor +
// admin-risdoc (heavy template viewer + admin panel, path-only).
const _fmPath = location.pathname || '/';
const _fmRisdocNeeded = _fmPath.startsWith('/risdoc/')
    || _fmPath.startsWith('/admin/risdoc')
    || !!document.querySelector('[data-fm-risdoc], #fm-ar-root');

if (_fmRisdocNeeded) {
    Promise.all([
        import('./features/risdoc-editor.js'),
        // admin-risdoc.js quando il pannello admin è presente (#fm-ar-root):
        // vale sia per /admin/risdoc sia per /admin/templates (tab RisDoc) —
        // prima il gate era solo /admin/risdoc → su /admin/templates il pannello
        // restava bloccato su "Caricamento…".
        document.querySelector('#fm-ar-root') ? import('./features/admin-risdoc.js') : Promise.resolve(null),
    ]).then(() => {
        document.dispatchEvent(new CustomEvent('fm:risdoc:ready'));
    }).catch((e) => {
        console.warn('[FM] risdoc lazy load failed:', e);
    });
}

// Boot legacy: registra DOMContentLoaded handler che inizializza
// AppState/DOMManager/App e binda i button sidebar
// (.fm-sb-sec[data-sidepage]). Senza questo i pulsanti restano inerti.
legacyBoot();

// Il banner dei consensi e le misure web-vitals non ci sono più (15/9/2026):
// il consenso «funzionali» non bloccava davvero diagrams.net, e le misure
// partivano senza consenso e non le leggeva nessuno. Si toglie dal browser
// la scelta rimasta del vecchio banner, che non serve più a niente.
try { localStorage.removeItem(FMConfig.COOKIE_CONSENT_KEY); } catch { /* storage non disponibile */ }

// Service Worker lazy load: requestIdleCallback per non bloccare il primo
// disegno. Fail silent: non e' una funzione essenziale.
const __fmIdle = window.requestIdleCallback || ((cb) => setTimeout(cb, 1500));
__fmIdle(() => {
    // Registra il service worker e, al confine di logout, purga le sue cache
    // di pagine/API → niente pagina autenticata stantia servita al guest dopo
    // il logout (fix "Torna alla home" che rimbalzava a /login da un
    // esercizio). 2026-09-23 — il confine non è più solo /login: anche
    // /accesso-classe e ogni pagina resa per un ospite, perché chi abbandona
    // la scheda senza uscire non passa da /login (A-72). La decisione sta in
    // `avvia` e `vaPulitaLaCache`, con le loro prove: qui non se ne scrive una
    // copia.
    import("./perf/sw-register.js").then((m) => m.avvia()).catch(() => {});
});

export const FM = window.FM;
