/**
 * Bootstrap compat layer.
 *
 * Contiene:
 *  - legacyBoot(): DOMContentLoaded handler che inizializza i manager
 *    side-effect globals (AppState, DOMManager, App)
 *    i quali si auto-registrano su window.* al primo import
 *
 * Lo stile del sync status indicator (ex injectSyncStyles, CSS-in-JS vietato
 * da ADR-023 Fase 5) vive ora in css/modules/_sync-status.css.
 *
 * Richiede che tutti i moduli core (AppState, DOMManager, App)
 * siano già stati importati da bootstrap.js
 * così `window.X` è disponibile quando `ready` fa fire.
 */

// La politica del tema all'avvio (window.fmTemaScuro): un posto solo, lo
// stesso file che il PHP mette in linea nel <head> (A-50).
import "../tema-iniziale.js";

export function legacyBoot() {
    // In contesto iframe: la pagina è embedded come legacy chrome (vedi
    // DOMManager.loadUrlInFrame). Non bootstrappare i manager — non c'è
    // sidebar né #fm-content, e App.init() rilegge AppState.linkref
    // scatenando un loop di re-navigation.
    const inIframe = (() => { try { return window.top !== window.self; } catch (_) { return true; } })();
    if (inIframe) return;

    // Vanilla (no jQuery): esegui al DOMContentLoaded, o subito se già pronto.
    const boot = () => {
        // Phase G7 — `scriptGoogle_sync/gas-client-secure.js` deprecato e
        // spostato in docs/archive/scriptGoogle_sync-deprecated/. Reference
        // rimossa: il sistema Drive integrato (DriveClient + MapSyncService)
        // non richiede config GAS legacy.
        if (window.AppState?.init)         window.AppState.init();
        if (window.DOMManager?.init)       window.DOMManager.init();
        if (window.App?.init)              window.App.init();
    };
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", boot, { once: true });
    } else {
        boot();
    }
}

/**
 * Sync body.exercise-context + init LogoutButton widget in base al
 * pathname corrente. Le rotte wrappate da ExerciseViewController
 * (eser/lab/mappe/didattica/verifiche/risdoc/strcomp_bes_altro/drafts)
 * vogliono layout_es.css scope attivo; le altre no. fm-router
 * sostituisce #fm-content ma non tocca <body>, quindi gestiamo qui
 * la sincronizzazione.
 */
const EXERCISE_PREFIXES = [
    "/eser/", "/lab/", "/mappe/", "/didattica/",
    "/verifiche/", "/risdoc/", "/strcomp_bes_altro/", "/drafts/",
    "/studio/",   // Phase 15: contract-backed rendering modern route
];

function isExerciseRoute() {
    return EXERCISE_PREFIXES.some(p => location.pathname.startsWith(p));
}

function syncExerciseContext() {
    document.body.classList.toggle("fm-exercise-context", isExerciseRoute());
    // Phase 21 — `fm-has-upbar` identifica route CON upbar (esercizio/verifica),
    // distinguendole da mappa/risdoc/bes che hanno `exercise-context` per
    // layout_es.css ma NON upbar. Usato da:
    //   - CSS: scope per body padding-top + #fm-content margin-top
    //   - JS: gate ensureVerificaMode (infoVer injection) in verifica-builder.js
    // Source-of-truth: presenza `.fm-upbar` nel DOM (server wrapInShell la
    // inietta solo per pagine esercizio/verifica).
    document.body.classList.toggle("fm-has-upbar", !!document.querySelector(".fm-upbar"));
    initDarkModeToggleIfNeeded();
    // Phase 20 — toggle .giustifica via .checkgiust. Idempotente:
    // UIComp.caricaGiust usa namespace off/on su document. Esegue anche il
    // trigger iniziale per allineare visibility al default checked.
    try { window.FM?.UIComp?.caricaGiust?.(); } catch (_) {}
}

/**
 * Dark mode globale: il btn `.fm-sb-dark` toggla body.fm-dark. Persiste
 * scelta in localStorage. Il CSS scope (sidebar + upbar + content) vive
 * in layout.css sotto body.fm-dark.
 */
const DARKMODE_SELECTOR = ".fm-sb-dark";

function findDarkModeBtn() {
    return document.querySelector(DARKMODE_SELECTOR);
}

function initDarkModeToggleIfNeeded() {
    const btn = findDarkModeBtn();
    if (btn && btn.dataset.fmBound !== "1") {
        btn.dataset.fmBound = "1";
        // ARIA: dark-mode toggle e' un button stateful -> aria-pressed.
        if (!btn.hasAttribute("aria-label")) {
            btn.setAttribute("aria-label", "Attiva o disattiva modalita' scura");
        }
        btn.addEventListener("click", () => {
            const on = !document.body.classList.contains("fm-dark");
            setDarkMode(on);
        });
    }
    applyPersistedDarkMode();
}

// Phase C.3 — single source of truth per applicare dark/light mode.
// Aggiorna body.fm-dark (legacy), html[data-theme] (preferito post-C.3),
// localStorage, aria-pressed sul btn.
function setDarkMode(on) {
    document.body.classList.toggle("fm-dark", on);
    document.documentElement.setAttribute("data-theme", on ? "dark" : "light");
    try { localStorage.setItem("fm_dark_mode", on ? "1" : "0"); } catch (_) {}
    updateDarkModeButton(on);
}

/** La politica del tema è una e sta in js/tema-iniziale.js (importato in
 *  cima a questo file, e in linea nel <head>): qui la si applica soltanto
 *  (23/9/2026, A-50). */
function applyPersistedDarkMode() {
    setDarkMode(window.fmTemaScuro());
}

function updateDarkModeButton(on) {
    const btn = findDarkModeBtn();
    if (!btn) return;
    const icon = btn.querySelector(".fm-darkmode-icon");
    const text = btn.querySelector(".fm-darkmode-text");
    if (icon) icon.textContent = on ? "☀" : "🌙";
    if (text) text.textContent = on ? "LIGHT" : "DARK";
    // ARIA: aria-pressed riflette lo stato dark mode attivo o no.
    btn.setAttribute("aria-pressed", on ? "true" : "false");
}

// Al primo load + ad ogni navigazione SPA, riallinea scope + widget.
// Dark mode applicato SEMPRE (anche home/dashboard) così la scelta
// utente è coerente in tutta l'app.
window.addEventListener("fm:navigated", syncExerciseContext);
document.addEventListener("DOMContentLoaded", () => {
    applyPersistedDarkMode();
    syncExerciseContext();
});

window.FM = window.FM || {};
window.FM.legacyBoot          = legacyBoot;
window.FM.syncExerciseContext = syncExerciseContext;
