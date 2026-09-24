/**
 * Banner delle note legali (views/partials/_legal_notice_banner.php).
 *
 * Fino al 2026-09-05 era uno <script> inline nel partial. La chiave di
 * sessione include la data di efficacia (data-fm-legal-effective): pubblicare
 * una versione nuova riporta il banner anche a chi aveva chiuso la precedente.
 */
function initLegalNotice() {
    const el = document.querySelector("[data-fm-legal-notice]");
    if (!el || el.dataset.fmLegalBound === "1") return;
    el.dataset.fmLegalBound = "1";
    const key = `fm_legal_notice_${el.getAttribute("data-fm-legal-effective") || ""}`;
    try {
        if (sessionStorage.getItem(key) === "1") { el.remove(); return; }
    } catch (_) { /* storage non disponibile: mostra comunque */ }
    el.hidden = false;
    const btn = el.querySelector("[data-fm-legal-dismiss]");
    if (btn) {
        btn.addEventListener("click", () => {
            try { sessionStorage.setItem(key, "1"); } catch (_) { /* best-effort */ }
            el.remove();
        });
    }
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initLegalNotice, { once: true });
} else {
    initLegalNotice();
}
window.addEventListener("fm:navigated", initLegalNotice);
