/**
 * Phase 25.E11 — logout-time cleanup di localStorage user-scoped.
 *
 * Su click su qualsiasi link/button verso `/logout`, pulisce le chiavi
 * localStorage che contengono dati personali del docente PRIMA della
 * navigazione. Mitiga leak cross-user su browser condivisi (es. marco
 * logout → vittorio login stesso browser).
 *
 * Defense-in-depth rispetto al sweep all'init di fm-pt-document/risdoc
 * (Phase 25.E10): il sweep copre il caso "B logga e visita pagina con
 * componenti risdoc", ma non il caso "B logga e visita home / dashboard".
 * Il cleanup al logout chiude la finestra di esposizione.
 *
 * Chiavi pulite (prefix match):
 *   - fm.risdoc.tmpl.*       — extra sections, valori form risdoc
 *   - fm.risdoc.catLabels.*  — rinomine categorie sidepage per-utente
 *   - fm.sidepage.*          — custom categories per-utente
 *   - fm.risdoc.compilation* — bozze compilation locali
 *
 * Chiavi preservate (preferenze UI globali, no PII):
 *   - fm_dark_mode           — tema chiaro/scuro
 *   - fmv-cookie-consent     — consenso cookie (Art. 7 GDPR, deve sopravvivere
 *                              al logout perché è una scelta consapevole)
 *   - fmv.inCellPreview      — preferenza editor verifica
 *   - fmv.popupPreview       — preferenza editor verifica
 *   - fm_admin_badge_disabled — preferenza UI dev-only
 */

const PURGE_PREFIXES = [
    "fm.risdoc.",
    "fm.sidepage.",
];

function clearUserScopedStorage() {
    let purgedLocal = 0;
    try {
        const toRemove = [];
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (!k) continue;
            if (PURGE_PREFIXES.some((p) => k.startsWith(p))) {
                toRemove.push(k);
            }
        }
        for (const k of toRemove) {
            try { localStorage.removeItem(k); purgedLocal++; } catch (_) {}
        }
    } catch (_) { /* localStorage non disponibile (private mode, quota) */ }

    let purgedSession = 0;
    try {
        const toRemove = [];
        for (let i = 0; i < sessionStorage.length; i++) {
            const k = sessionStorage.key(i);
            if (!k) continue;
            if (PURGE_PREFIXES.some((p) => k.startsWith(p))) {
                toRemove.push(k);
            }
        }
        for (const k of toRemove) {
            try { sessionStorage.removeItem(k); purgedSession++; } catch (_) {}
        }
    } catch (_) { /* sessionStorage non disponibile */ }

    if (purgedLocal || purgedSession) {
        console.debug(
            `[logout-cleanup] purged localStorage=${purgedLocal} sessionStorage=${purgedSession} user-scoped keys`,
        );
    }
    return { purgedLocal, purgedSession };
}

function isLogoutLink(a) {
    if (!a || a.tagName !== "A") return false;
    const href = a.getAttribute("href") || "";
    // Match /logout esatto + varianti relative (../logout, ./logout)
    return href === "/logout" || href === "logout"
        || href.endsWith("/logout") || /\/logout(\?|#|$)/.test(href);
}

// Intercetta click su <a href="/logout"> in fase capture (PRIMA che fm-router
// SPA li intercetti — fm-router fa preventDefault su same-origin links).
document.addEventListener("click", (e) => {
    const a = e.target.closest && e.target.closest("a[href]");
    if (a && isLogoutLink(a)) {
        clearUserScopedStorage();
    }
}, true);

// Form POST /logout (futuro — il pentest 2026-04-29 raccomanda POST per logout
// per evitare CSRF logout, vedi remediation P3).
document.addEventListener("submit", (e) => {
    const f = e.target;
    if (!f || f.tagName !== "FORM") return;
    const action = f.getAttribute("action") || "";
    if (action === "/logout" || action.endsWith("/logout")) {
        clearUserScopedStorage();
    }
}, true);

// Espone per cleanup manuale (utilities, debugging, manual force-clear da console).
if (typeof window !== "undefined") {
    window.FM = window.FM || {};
    window.FM.clearUserScopedStorage = clearUserScopedStorage;
}

export { clearUserScopedStorage };
