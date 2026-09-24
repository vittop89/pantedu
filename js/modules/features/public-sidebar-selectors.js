/**
 * WS4 / CSP — popola i selettori indirizzo/classe/materia della sidebar PUBBLICA
 * (guest, sezioni publish_public) con il curriculum del super-admin docente
 * (GET /curriculum → scope:public), dato che lato server $iisGroups/$clsGroups/
 * $materie sono vuoti per i guest.
 *
 * Sostituisce lo <script> inline in views/partials/sidebar.php → compatibile con
 * CSP strict (nessun inline-script). Side-effect import dal bootstrap.
 */

/**
 * Aggiunge le voci a un <select>. Le classi portano il loro indirizzo in
 * `data-indirizzo`, come quelle disegnate dal server: e' quello che la cascata
 * (sidebar-cascade.js) usa per tenere solo le classi dell'indirizzo scelto.
 */
export function fillSelect(id, items) {
    const sel = document.getElementById(id);
    if (!sel || !Array.isArray(items)) return;
    for (const o of items) {
        if (!o || !o.code) continue;
        const opt = document.createElement("option");
        opt.value = o.code;
        opt.textContent = o.label || o.code;
        if (o.indirizzo) opt.dataset.indirizzo = String(o.indirizzo);
        sel.appendChild(opt);
    }
}

/** I selettori e la chiave in sessione con cui sidebar-cascade.js ricorda la scelta. */
const SCELTE = [["sel-iis", "selectedIIS"], ["sel-cls", "selectedCLS"], ["sel-mater", "selectedMATER"]];

/**
 * Rimette le scelte della sessione, nell'ordine indirizzo → classe → materia,
 * dopo che le voci sono arrivate dalla rete.
 *
 * Fino al 15/9/2026 per il visitatore senza login le scelte si ricordavano ma
 * al ricaricamento si perdevano: dom-manager.js scrive il valore quando le
 * opzioni non ci sono ancora, il <select> resta senza scelta, e quando le voci
 * arrivano il browser mostra la prima («Artistico»). Una scelta che non esiste
 * più torna al segnaposto, mai alla prima voce.
 *
 * Il change che parte non viene dall'utente (isTrusted falso): la cascata
 * filtra le classi senza azzerare, e chi disegna i contenuti li ricarica.
 */
export function ripristinaScelte() {
    for (const [id, chiave] of SCELTE) {
        const sel = document.getElementById(id);
        if (!sel) continue;
        let ricordato = "";
        try {
            ricordato = sessionStorage.getItem(chiave) || "";
        } catch (_) {
            ricordato = "";
        }
        const opzioni = Array.from(sel.options);
        const scelta = ricordato !== "" ? opzioni.find((o) => o.value === ricordato && !o.disabled) : undefined;
        const segnaposto = opzioni.find((o) => o.value === "");
        if (scelta) {
            scelta.selected = true;
        } else if (segnaposto) {
            segnaposto.selected = true;
        }
        sel.dispatchEvent(new Event("change", { bubbles: true }));
    }
}

export function initPublicSidebarSelectors() {
    // Solo sidebar guest con sezioni pubbliche (markup server-side).
    if (!document.querySelector('nav.sidebar[data-fm-guest="1"]')) return;
    if (window.__fmPublicSelLoaded) return;       // idempotente
    window.__fmPublicSelLoaded = true;
    fetch("/curriculum", { credentials: "same-origin", headers: { Accept: "application/json" } })
        .then((r) => (r.ok ? r.json() : null))
        .then((j) => {
            if (!j || !j.curriculum) return;
            fillSelect("sel-iis", j.curriculum.indirizzi);
            fillSelect("sel-cls", j.curriculum.classi);
            fillSelect("sel-mater", j.curriculum.materie);
            ripristinaScelte();
        })
        .catch(() => { /* silent: guest senza curriculum pubblico */ });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPublicSidebarSelectors, { once: true });
} else {
    initPublicSidebarSelectors();
}
