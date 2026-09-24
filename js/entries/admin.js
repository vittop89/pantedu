/**
 * Entry Vite condiviso dalle pagine /admin/* (incluso da
 * views/admin/_partials/page_head.php).
 *
 * Nato come scheletro (Phase Roadmap 10) e mai incluso da nessuna vista; dal
 * 2026-09-05 ospita quello che prima era inline in page_head.php: il menu a
 * tendina della nav admin (Phase 25.R.30, un gruppo aperto alla volta).
 * Bundle target: <30 kB gzip.
 */

function initAdminToolnav() {
    const nav = document.querySelector(".fm-admin-toolnav--menus");
    if (!nav || nav.dataset.fmBound === "1") return;
    nav.dataset.fmBound = "1";

    function closeAll(except) {
        nav.querySelectorAll(".fm-admin-toolnav__group.is-open").forEach((g) => {
            if (g === except) return;
            g.classList.remove("is-open");
            g.querySelector(".fm-admin-toolnav__grpbtn")?.setAttribute("aria-expanded", "false");
            g.querySelector(".fm-admin-toolnav__menu")?.setAttribute("hidden", "");
        });
    }
    nav.addEventListener("click", (e) => {
        const btn = e.target.closest(".fm-admin-toolnav__grpbtn");
        if (!btn) return;
        const grp = btn.closest(".fm-admin-toolnav__group");
        const open = grp.classList.toggle("is-open");
        closeAll(open ? grp : null);
        btn.setAttribute("aria-expanded", open ? "true" : "false");
        const menu = grp.querySelector(".fm-admin-toolnav__menu");
        if (menu) { if (open) menu.removeAttribute("hidden"); else menu.setAttribute("hidden", ""); }
    });
    document.addEventListener("click", (e) => { if (!e.target.closest(".fm-admin-toolnav__group")) closeAll(null); });
    document.addEventListener("keydown", (e) => { if (e.key === "Escape") closeAll(null); });
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", initAdminToolnav, { once: true });
} else {
    initAdminToolnav();
}
window.addEventListener("fm:navigated", initAdminToolnav);
