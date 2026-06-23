/**
 * <fm-risdoc-section-navigator> — Phase 25.E12.
 *
 * Port modernizzato di #section-navigator legacy (master branch risdoc.js
 * v1080+). Vanilla JS, no Lit, lightweight (~3KB). Si integra nella
 * .fm-risdoc-toolbar generata da TemplateViewController.
 *
 * Comportamento:
 *  - All'init scansiona il DOM per <fm-risdoc-pt-section> e popola dropdown
 *    con i titoli delle sezioni.
 *  - Click sul navigator apre/chiude dropdown.
 *  - Click su section-item → scrollIntoView del Custom Element corrispondente.
 *  - Hover gestito (mouseenter/leave) per UX desktop.
 *  - Touch events per mobile (tap = open, tap-out = close).
 *  - Auto-hide durante scroll (`.scroll-hidden` class).
 *  - MutationObserver: ri-popola dropdown se le sezioni cambiano (extra
 *    sections aggiunte runtime via "+ Nuova sezione").
 */

(function () {
    "use strict";

    const NAVIGATOR_ID = "section-navigator";
    const DROPDOWN_ID  = "section-dropdown";

    function init() {
        const navigator = document.getElementById(NAVIGATOR_ID);
        const dropdown  = document.getElementById(DROPDOWN_ID);
        if (!navigator || !dropdown) return;
        if (navigator.dataset.fmBound === "1") return;
        navigator.dataset.fmBound = "1";

        populate(dropdown);
        bindEvents(navigator, dropdown);
        // Phase 25.E13 — auto-hide su scroll DISABILITATO (richiesta utente).
        // bindAutoHide(navigator);
        observeMutations(dropdown);
        // Phase 25.E19 — tracking section corrente in viewport.
        observeViewportSections(navigator, dropdown);
    }

    function listSections() {
        // Le pt-section live sotto fm-pt-document (light DOM, ADR-026 #3).
        const tpl = document.querySelector("fm-pt-document");
        if (!tpl) return [];
        const sections = Array.from(tpl.querySelectorAll("fm-risdoc-pt-section"));
        return sections.filter((el) => {
            const t = el?.section?.title;
            return typeof t === "string" && t.trim() !== "";
        });
    }

    function populate(dropdown) {
        const sections = listSections();
        dropdown.innerHTML = "";
        if (sections.length === 0) {
            const empty = document.createElement("div");
            empty.className = "section-item";
            empty.style.fontStyle = "italic";
            empty.style.opacity = ".6";
            empty.textContent = "Nessuna sezione";
            dropdown.appendChild(empty);
            return;
        }
        sections.forEach((sec, idx) => {
            const title = sec.section?.title || `Sezione ${idx + 1}`;
            const item = document.createElement("a");
            item.href = "#";
            item.className = "section-item";
            item.dataset.target = `idx-${idx}`;
            item.textContent = `${idx + 1}. ${title}`;
            item.addEventListener("click", (e) => {
                e.preventDefault();
                e.stopPropagation();
                scrollTo(sec);
                dropdown.classList.remove("show");
            });
            dropdown.appendChild(item);
        });
    }

    function scrollTo(sectionEl) {
        if (!sectionEl?.scrollIntoView) return;
        sectionEl.scrollIntoView({ behavior: "smooth", block: "start" });
        // Highlight visivo breve (outline) per indicare la sezione raggiunta.
        const orig = sectionEl.style.outline;
        sectionEl.style.outline = "2px solid #3b82f6";
        sectionEl.style.outlineOffset = "4px";
        setTimeout(() => {
            sectionEl.style.outline = orig;
            sectionEl.style.outlineOffset = "";
        }, 1200);
    }

    function bindEvents(navigator, dropdown) {
        navigator.addEventListener("mouseenter", () => dropdown.classList.add("show"));
        navigator.addEventListener("mouseleave", () => {
            setTimeout(() => {
                if (!dropdown.matches(":hover")) dropdown.classList.remove("show");
            }, 200);
        });
        dropdown.addEventListener("mouseleave", () => dropdown.classList.remove("show"));

        // Click toggle (anche per touch + a11y keyboard)
        navigator.addEventListener("click", (e) => {
            e.preventDefault();
            e.stopPropagation();
            dropdown.classList.toggle("show");
        });

        // Touch: tap-out su body per chiudere
        document.addEventListener("touchend", (e) => {
            if (!navigator.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.remove("show");
            }
        }, { passive: true });

        // Esc per chiudere
        document.addEventListener("keydown", (e) => {
            if (e.key === "Escape" && dropdown.classList.contains("show")) {
                dropdown.classList.remove("show");
            }
        });
    }

    // Phase 25.E13 — bindAutoHide() rimossa (vedi init()). Mantenuta come no-op.
    // eslint-disable-next-line no-unused-vars
    function bindAutoHide(_navigator) { /* disabilitato */ }

    /**
     * Phase 25.E19 — IntersectionObserver per tracciare la pt-section
     * attualmente in viewport e aggiornare:
     *   - #fm-current-section sticky bar (titolo + visibilità)
     *   - .section-item.is-active nel dropdown
     *   - .fm-section-nav-label nel navigator button (titolo abbreviato)
     *
     * Strategia: rootMargin negativo top per ignorare la parte che passa
     * sotto toolbar+sticky bar; rootMargin negativo bottom per dare priorità
     * alla section che ha il titolo più in alto nello scroll. Multiple
     * threshold per granularità di intersezione.
     */
    function observeViewportSections(navigator, dropdown) {
        if (typeof IntersectionObserver === "undefined") return;
        // Phase 25.E21 — sticky bar #fm-current-section rimossa.
        // Il tracking viewport ora aggiorna SOLO il navigator label +
        // dropdown active highlight.
        const navLabelEl     = navigator.querySelector(".fm-section-nav-label");
        const defaultLabel   = navLabelEl?.dataset.defaultLabel || "Sezioni";

        const visibilityMap = new Map(); // section host → ratio
        let ticking = false;

        function pickActive() {
            // Sezione "attiva" = quella con la maggior intersection ratio
            // > 0; tie-breaker = ordine DOM (la più in alto vince).
            let best = null;
            let bestRatio = 0;
            for (const [el, ratio] of visibilityMap) {
                if (ratio > bestRatio) {
                    best = el;
                    bestRatio = ratio;
                }
            }
            return best;
        }

        function update() {
            ticking = false;
            const active = pickActive();
            const title = active?.section?.title?.trim() || "";

            // Navigator button label. 2026-05-27 — FIX FLICKER: la label resta
            // STATICA ("Sezioni"), NON segue più la sezione attiva su scroll.
            // Prima cambiava ("Sezioni"↔"Nuova sezione") → il bottone cambiava
            // larghezza → reflow topbar → sezioni si spostavano → IntersectionObserver
            // rifà → loop = flicker continuo. La sezione attiva resta evidenziata
            // NEL DROPDOWN (sotto). Scrive solo se necessario (idempotente).
            if (navLabelEl && navLabelEl.textContent !== defaultLabel) {
                navLabelEl.textContent = defaultLabel;
            }
            // Dropdown active item highlight
            const items = dropdown.querySelectorAll(".section-item");
            const sections = listSections();
            const activeIdx = active ? sections.indexOf(active) : -1;
            items.forEach((item, idx) => {
                item.classList.toggle("fm-is-active", idx === activeIdx);
            });
        }

        function scheduleUpdate() {
            if (ticking) return;
            ticking = true;
            requestAnimationFrame(update);
        }

        const obs = new IntersectionObserver((entries) => {
            for (const e of entries) {
                if (e.isIntersecting) {
                    visibilityMap.set(e.target, e.intersectionRatio);
                } else {
                    visibilityMap.delete(e.target);
                }
            }
            scheduleUpdate();
        }, {
            // Margine top negativo = ignora area coperta da toolbar (~46px) +
            // sticky bar (~30px). Margin bottom negativo = considera "attiva"
            // la section il cui titolo è ancora visibile in alto.
            rootMargin: "-80px 0px -50% 0px",
            threshold: [0, 0.1, 0.25, 0.5, 0.75, 1],
        });

        // Observe all current sections + re-observe on mutation.
        function refreshObserver() {
            obs.disconnect();
            visibilityMap.clear();
            const sections = listSections();
            for (const s of sections) obs.observe(s);
            scheduleUpdate();
        }
        refreshObserver();

        // ADR-026 #3 — fm-pt-document light DOM.
        const tpl = document.querySelector("fm-pt-document");
        const target = tpl || document.body;
        const mut = new MutationObserver(() => {
            if (refreshObserver._t) clearTimeout(refreshObserver._t);
            refreshObserver._t = setTimeout(refreshObserver, 200);
        });
        mut.observe(target, { childList: true, subtree: true });
    }

    function observeMutations(dropdown) {
        // Re-popola dropdown se vengono aggiunte/rimosse pt-section runtime
        // (es. user clicca "+ Nuova sezione" o cancella una extra). Le
        // mutation avvengono nel light DOM di fm-pt-document.
        const tpl = document.querySelector("fm-pt-document");
        const target = tpl || document.body;
        const obs = new MutationObserver(() => {
            if (observeMutations._t) clearTimeout(observeMutations._t);
            observeMutations._t = setTimeout(() => populate(dropdown), 150);
        });
        obs.observe(target, { childList: true, subtree: true });

        // Se il template non era ancora montato all'init, attende e riprova.
        // Lit fa render asincrono — la prima populate può capitare prima del
        // primo paint delle pt-section. Polling breve come safety net.
        let retries = 0;
        const retryTimer = setInterval(() => {
            const sections = listSections();
            if (sections.length > 0 || retries >= 20) {
                clearInterval(retryTimer);
                if (sections.length > 0) populate(dropdown);
                return;
            }
            retries++;
        }, 250);
    }

    // Init: aspetta che il toolbar PHP sia presente nel DOM. Se è in partial
    // SPA, il listener fm:navigated re-init.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
    window.addEventListener("fm:navigated", () => {
        // Reset bound flag così il nuovo toolbar viene rebound dopo SPA nav.
        const nav = document.getElementById(NAVIGATOR_ID);
        if (nav) nav.dataset.fmBound = "";
        init();
    });
})();
