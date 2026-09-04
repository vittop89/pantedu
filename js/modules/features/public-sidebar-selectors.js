/**
 * WS4 / CSP — popola i selettori indirizzo/classe/materia della sidebar PUBBLICA
 * (guest, sezioni publish_public) con il curriculum del super-admin docente
 * (GET /curriculum → scope:public), dato che lato server $iisGroups/$clsGroups/
 * $materie sono vuoti per i guest.
 *
 * Sostituisce lo <script> inline in views/partials/sidebar.php → compatibile con
 * CSP strict (nessun inline-script). Side-effect import dal bootstrap.
 */

function fillSelect(id, items) {
    const sel = document.getElementById(id);
    if (!sel || !Array.isArray(items)) return;
    for (const o of items) {
        if (!o || !o.code) continue;
        const opt = document.createElement("option");
        opt.value = o.code;
        opt.textContent = o.label || o.code;
        sel.appendChild(opt);
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
        })
        .catch(() => { /* silent: guest senza curriculum pubblico */ });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initPublicSidebarSelectors, { once: true });
} else {
    initPublicSidebarSelectors();
}
