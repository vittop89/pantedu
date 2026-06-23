/**
 * Pantedu SPA router — Phase 6d.
 *
 * - Intercetta i click su <a href> same-origin e carica la pagina
 *   via fetch con header `X-Partial: 1`.
 * - Il backend (LegacyController + views/layout/app.php) ritorna
 *   solo il contenuto del <body>.
 * - Lo inietta in #fm-content, aggiorna l'URL via history.pushState,
 *   ripristina scroll e dispatcha `fm:navigated` perché gli altri
 *   script possano riagganciarsi (sel-wrapper, MathJax, ecc.).
 * - popstate riapplica la navigazione su back/forward.
 * - Opt-out per singolo link: attributo `data-full-reload` o
 *   `target="_blank"` o modificatori del mouse (ctrl/cmd/middle click).
 *
 * Zero dipendenze. ~80 righe.
 */
(function () {
    'use strict';

    const CONTENT_ID = 'fm-content';
    const PARTIAL_HDR = {'X-Partial': '1', 'Accept': 'text/html'};

    // Phase 14 — rotte che richiedono asset completi in <head> (MathJax v4,
    // Quill). Se entriamo qui via SPA da una pagina senza asset,
    // forziamo full reload così head.php carica _exercise_assets.php.
    // (TikZ rendering ora server-side via /tikz/render, no asset frontend.)
    const EXERCISE_PREFIXES = [
        '/eser/', '/lab/', '/mappe/', '/didattica/',
        '/verifiche/', '/risdoc/', '/strcomp_bes_altro/', '/drafts/',
        '/studio/',
    ];
    function targetNeedsExerciseAssets(pathname) {
        // Phase G7 — /studio/mappa/ non richiede MathJax/Quill
        // (le mappe drawio rendono internamente). Senza questa esclusione,
        // navigare tra mappe forzava full reload (perche' la pagina mappa
        // NON setta bodyClass=exercise-context → MathJax mai caricato →
        // assetsReady() false → fallback location.href).
        if (pathname.startsWith('/studio/mappa/')) return false;
        return EXERCISE_PREFIXES.some(p => pathname.startsWith(p));
    }
    function assetsReady() {
        // MathJax è l'indicatore: se window.MathJax esiste i bundle
        // exercise sono stati caricati in head.
        return typeof window.MathJax !== 'undefined';
    }

    function isInternalClick(e, a) {
        if (!a || a.target === '_blank') return false;
        if (a.hasAttribute('data-full-reload')) return false;
        if (e.defaultPrevented) return false;
        if (e.button !== 0) return false;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return false;
        const url = new URL(a.href, location.href);
        if (url.origin !== location.origin) return false;
        // download links stay native
        if (a.hasAttribute('download')) return false;
        // anchor jumps on the same page
        if (url.pathname === location.pathname && url.hash) return false;
        // Phase 14: exercise route → need full head assets; full reload
        // se non abbiamo già MathJax (es. provenendo da home).
        if (targetNeedsExerciseAssets(url.pathname) && !assetsReady()) return false;
        return true;
    }

    async function navigate(url, push = true) {
        const target = document.getElementById(CONTENT_ID);
        if (!target) { location.href = url; return; }

        // Phase 15 — se la destinazione richiede asset exercise (MathJax,
        // Quill) e non sono ancora caricati, full reload così
        // head.php include _exercise_assets.php. Evita partial con body
        // renderizzato ma LaTeX raw + collapsible broken.
        try {
            const parsed = new URL(url, location.href);
            if (targetNeedsExerciseAssets(parsed.pathname) && !assetsReady()) {
                location.href = url;
                return;
            }
        } catch (_) { /* url malformata → prosegue con partial */ }

        target.setAttribute('aria-busy', 'true');
        let res;
        try {
            res = await fetch(url, { headers: PARTIAL_HDR, credentials: 'same-origin' });
        } catch (err) {
            console.warn('[fm-router] fetch failed, falling back to full reload', err);
            location.href = url;
            return;
        }

        // If server redirected us (login/403), follow it full-reload
        if (res.redirected && new URL(res.url).origin === location.origin
            && res.url !== url && !res.url.includes('X-Partial')) {
            location.href = res.url;
            return;
        }

        const html = await res.text();
        // Backend può richiedere full reload (es. home con pageContent vuoto)
        // inviando un marker invece di contenuto. Evita # fm-content bianco.
        if (html.indexOf('data-fm-full-reload="1"') !== -1 || html.trim() === '') {
            location.href = url;
            return;
        }
        // Phase 20 — replace semplice: multi-argomento è ora gestito dal
        // backend via ?ids=N,M,... (topicPage carica e filtra rows su
        // quel subset, rendering unico). Niente più append DOM lato client.
        target.innerHTML = html;
        target.removeAttribute('aria-busy');

        if (push) history.pushState({ fmRouter: true }, '', url);
        window.scrollTo(0, 0);
        rewireInjectedScripts(target);
        retriggerTypesetters(target);
        window.dispatchEvent(new CustomEvent('fm:navigated', { detail: { url } }));
    }

    /** Re-run MathJax su un nodo dopo partial swap (elementi
     *  appena iniettati non sono tipografati automaticamente). Emette
     *  'fm:mathjax-ready' a typeset completato perché altri moduli
     *  (es. collapsible) possano ricalcolare altezze.
     *
     *  TikZJax deprecato G22.S15.bis: tikz-render-client.js gestisce
     *  automaticamente i <script type="text/tikz"> via MutationObserver
     *  + listener su 'fm:navigated', niente trigger manuale qui. */
    function retriggerTypesetters(root) {
        const fireReady = () => window.dispatchEvent(new CustomEvent('fm:mathjax-ready', { detail: { root } }));
        const waitAndTypeset = () => {
            if (!window.MathJax) return;
            // MathJax v4: startup.promise se disponibile, altrimenti typesetPromise
            const mj = window.MathJax;
            const startupReady = mj.startup?.promise ?? Promise.resolve();
            startupReady
                .then(() => {
                    if (typeof mj.typesetPromise === 'function') return mj.typesetPromise([root]);
                    if (mj.Hub && typeof mj.Hub.Queue === 'function') {
                        return new Promise(resolve => mj.Hub.Queue(['Typeset', mj.Hub, root], resolve));
                    }
                })
                .then(fireReady)
                .catch(() => fireReady());
        };
        // Se MathJax non è ancora caricato, attendi lo script (event load)
        if (!window.MathJax) {
            const mjScript = document.getElementById('MathJax-script');
            if (mjScript && !mjScript.dataset.fmLoaded) {
                mjScript.dataset.fmLoaded = '1';
                mjScript.addEventListener('load', waitAndTypeset, { once: true });
            }
        } else {
            waitAndTypeset();
        }
        // TikZJax deprecato G22.S15.bis: tikz-render-client gestisce auto via MutationObserver.
    }

    /** Node.cloneNode preserves <script> but the browser does NOT
     *  execute them once injected via innerHTML. Re-create them. */
    function rewireInjectedScripts(root) {
        root.querySelectorAll('script').forEach(orig => {
            const s = document.createElement('script');
            for (const attr of orig.attributes) s.setAttribute(attr.name, attr.value);
            if (!orig.src) s.textContent = orig.textContent;
            orig.replaceWith(s);
        });
    }

    document.addEventListener('click', e => {
        const a = e.target.closest('a[href]');
        if (!a || !isInternalClick(e, a)) return;
        e.preventDefault();
        navigate(new URL(a.href, location.href).href);
    });

    window.addEventListener('popstate', e => {
        // Only handle entries that we pushed (avoid stealing hash-only back)
        if (!e.state || !e.state.fmRouter) return;
        navigate(location.href, false);
    });

    // Expose for manual navigation from legacy code
    window.fmRouter = { navigate };

    // Mark first load so popstate knows the initial entry
    if (!history.state || !history.state.fmRouter) {
        history.replaceState({ fmRouter: true }, '', location.href);
    }
})();
