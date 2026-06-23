/**
 * CSP strict — event delegation per gli handler del template
 * `views/admin/Elementi_Riservati.html`.
 *
 * Quel frammento (toolbar editor + control-panel mappe) viene caricato via
 * fetch e iniettato con innerHTML: gli `on*` inline sono vietati dalla CSP
 * strict (script-src-attr) e gli `<script>` co-locati NON vengono eseguiti
 * quando si assegna innerHTML. Quindi le azioni passano da qui, con un
 * delegato a livello document (sempre attivo, importato dal bootstrap).
 *
 * Tutte le chiamate sono guardate con `?.`: se un global non è (ancora)
 * disponibile diventa un no-op, senza errori.
 */
document.addEventListener("click", (e) => {
    const el = e.target.closest("[data-fmr]");
    if (!el) return;
    switch (el.dataset.fmr) {
        case "createlink": window.EditorSystem?.execCmd?.("createLink"); break;
        case "sol":        window.EditorSystem?.insertSOLSpan?.(); break;
        case "dsa":        window.EditorSystem?.insertDSASpan?.(); break;
        case "findtoggle": window.EditorSystem?.toggleFindReplace?.(); break;
        case "findnext":   window.EditorSystem?.findNext?.(); break;
        case "findprev":   window.EditorSystem?.findPrevious?.(); break;
        case "replace":    window.EditorSystem?.replace?.(); break;
        case "replaceall": window.EditorSystem?.replaceAll?.(); break;
        case "copilot":    window.CopilotAI?.togglePanel?.(); break;
        case "togglecp":   window.App?.toggleControlPanel?.(); break;
        case "gas":        window.executeAction?.(el.dataset.arg); break;
        case "gas-stop":   window.stopSync?.(); break;
        case "gas-close":  window.closeControlPanel?.(); break;
    }
});

document.addEventListener("change", (e) => {
    const el = e.target.closest('select[data-fmr="listType"]');
    if (el) window.ListManager?.changeListType?.(el.value, window.editorID);
});
