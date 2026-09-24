/**
 * fm-url-state.js — Phase 6f
 *
 * Sincronizza i <select id="sel-iis|sel-cls|sel-mater"> della
 * sidebar con l'URL corrente. All'arrivo su una pagina
 *   /eser/{iis}/eser_{iis}{cls}/{MAT|FIS|...}/...
 * aggiorna i 3 select senza triggerare un rebuild della scrollbar
 * (evita loop quando lo script.js legacy reagisce al change).
 *
 * Reagisce a:
 *   - DOMContentLoaded                  (primo caricamento)
 *   - custom event "fm:navigated"       (dopo nav SPA)
 *
 * Emette anche l'evento al server per AccessLogger via beacon POST,
 * così il log accessi registra anche le navigate SPA (senza X-Partial
 * sarebbe perso perché il middleware AccessLog gira sul primo
 * request).
 */
(function () {
    'use strict';

    const PATH_RE = /\/(?:eser|lab|map|didattica)_([a-z]+)(\d+[sb]?)(?:\/|[/-])/i;
    const SUBJECT_RE = /\/([A-Z]{2,4})\/[^/]+\.(php|html?|pdf)/;
    // Le pagine di studio portano la terna nell'URL:
    //   /studio/{esercizio|verifica|mappa|...}/{IND}/{CLS}/{MAT}/...
    // e la barra laterale deve dirla. Dal 13/9/2026 i selettori non scelgono
    // piu' da soli il primo valore (js/modules/core/sidebar-cascade.js), quindi
    // senza questo una pagina di studio aperta dall'URL avrebbe la barra vuota.
    const STUDIO_RE = /^\/studio\/[a-z-]+\/([A-Za-z]{2,6})\/([1-9][A-Za-z0-9]{0,5})\/([A-Za-z]{2,6})(?:\/|$)/;

    function parseUrl(url) {
        const path = new URL(url, location.href).pathname;
        const m0   = path.match(STUDIO_RE);
        if (m0) return { iis: m0[1], cls: m0[2], mater: m0[3] };
        const m1   = path.match(PATH_RE);
        const m2   = path.match(SUBJECT_RE);
        if (!m1 && !m2) return null;
        return {
            iis:   m1 ? m1[1] : null,
            cls:   m1 ? m1[2] : null,
            mater: m2 ? m2[1] : null,
        };
    }

    function sync(url) {
        const state = parseUrl(url);
        if (!state) return;
        const guard = (id, value) => {
            if (!value) return;
            const el = document.getElementById(id);
            if (!el) return;
            const hasOption = Array.from(el.options).some(o => o.value === value);
            if (!hasOption) {
                // Le opzioni possono arrivare dopo: per chi studia il
                // selettore delle materie si ricalcola al cambio di classe
                // che abbiamo appena annunciato qui sopra, e la risposta e'
                // asincrona (js/modules/features/materie-di-chi-studia.js).
                // Senza questo segno la materia dell'URL si perdeva:
                // aprendo da segnalibro /studio/esercizio/IND/2/FIS/... la
                // barra tornava a «Scegli la materia:» (20/9/2026).
                el.dataset.fmValoreAtteso = value;
                return;
            }
            delete el.dataset.fmValoreAtteso;
            if (el.value === value) return;
            el.value = value;
            // Silent update: set a marker on the event so script.js
            // can detect "programmatic" changes and skip heavy
            // sidebar rebuild (fallback: we just dispatch without
            // bubbling, so jQuery .on('change') bound to the native
            // element still fires but the router is a no-op).
            el.dispatchEvent(new CustomEvent('change', {
                bubbles: true,
                detail: { fmSource: 'url-state' },
            }));
        };
        guard('sel-iis',   state.iis);
        guard('sel-cls',   state.cls);
        guard('sel-mater', state.mater);
    }

    function logNavigation(url) {
        if (!navigator.sendBeacon) return;
        const form = new FormData();
        form.append('url', url);
        try { navigator.sendBeacon('/analytics/nav', form); } catch (_) { /* ignore */ }
    }

    function onReady() {
        sync(location.href);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', onReady, { once: true });
    } else {
        onReady();
    }

    window.addEventListener('fm:navigated', e => {
        const url = e.detail && e.detail.url ? e.detail.url : location.href;
        sync(url);
        logNavigation(url);
    });
})();
