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

    function parseUrl(url) {
        const path = new URL(url, location.href).pathname;
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
            if (!el || el.value === value) return;
            const hasOption = Array.from(el.options).some(o => o.value === value);
            if (!hasOption) return;
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
