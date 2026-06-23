/**
 * bottom-bar-panel.js — disclosure dei link legali/trust della #bottom-bar.
 *
 * Markup: views/partials/modals.php → .fm-bb-menu (BEM).
 * CSS:    css/modules/_bottom-bar-panel.css.
 *
 * Responsabilità JS (progressive enhancement):
 *   - click sul trigger → toggle .fm-bb-menu--open + aria-expanded
 *   - Escape            → chiude e riporta il focus al trigger
 *   - click esterno / focusout → chiude
 *
 * L'apertura su mouseover (~0.5s, hover-intent) e su focus-within è
 * gestita interamente in CSS, così i link GDPR restano raggiungibili
 * anche senza JavaScript. Qui ci occupiamo solo dello stato "click".
 *
 * Vanilla JS (no jQuery), idempotente.
 */

const OPEN_CLASS = "fm-bb-menu--open";
const HOVER_DELAY_MS = 500;   // attesa mouseover prima dell'apertura (hover-intent)

function initMenu(menu) {
    if (menu.dataset.fmBbBound === "1") return;   // idempotente
    // Marca JS attivo: disattiva il fallback CSS :hover (vedi _bottom-bar-panel.css),
    // così il timing è governato solo dal setTimeout qui sotto.
    menu.dataset.fmBbBound = "1";

    const trigger = menu.querySelector(".fm-bb-menu__trigger");
    if (!trigger) return;

    let hoverTimer = null;
    const clearHoverTimer = () => {
        if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null; }
    };

    const open = () => {
        clearHoverTimer();
        menu.classList.add(OPEN_CLASS);
        trigger.setAttribute("aria-expanded", "true");
    };
    const close = () => {
        clearHoverTimer();
        menu.classList.remove(OPEN_CLASS);
        trigger.setAttribute("aria-expanded", "false");
    };
    const isOpen = () => menu.classList.contains(OPEN_CLASS);

    trigger.addEventListener("click", (e) => {
        e.preventDefault();
        isOpen() ? close() : open();
    });

    // Hover-intent: apertura dopo HOVER_DELAY_MS preciso. mouseenter/mouseleave
    // non bubblano e ignorano i figli; il ::before (CSS) copre il gap col
    // pannello così muovere il mouse dal trigger al pannello non chiude.
    menu.addEventListener("mouseenter", () => {
        if (isOpen()) return;
        clearHoverTimer();
        hoverTimer = setTimeout(open, HOVER_DELAY_MS);
    });
    menu.addEventListener("mouseleave", () => {
        clearHoverTimer();
        close();
    });

    // Escape: chiudi e riporta il focus al trigger (WCAG 2.1.2 no keyboard trap).
    menu.addEventListener("keydown", (e) => {
        if (e.key === "Escape" && isOpen()) {
            close();
            trigger.focus();
        }
    });

    // Click esterno → chiudi.
    document.addEventListener("click", (e) => {
        if (isOpen() && !menu.contains(e.target)) close();
    });

    // Focus uscito dal menu (tastiera) → chiudi.
    menu.addEventListener("focusout", (e) => {
        if (isOpen() && !menu.contains(e.relatedTarget)) close();
    });
}

function init() {
    document.querySelectorAll("[data-fm-bb-menu]").forEach(initMenu);
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
} else {
    init();
}

export const BottomBarPanel = { init };
