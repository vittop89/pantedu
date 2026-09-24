<?php
/**
 * Asset di head per contesto esercizi/verifiche (sostituisce il legacy
 * views/legacy/head-content.html).
 *
 * Include:
 *   - CSS scope esercizi (layout_es, layout_editor) e il foglio di stile di
 *     Quill (solo il CSS: il suo JavaScript non lo istanziava nessuno ed è
 *     stato tolto il 23/9/2026, D-2)
 *   - MathJax v4 config + loader (async)
 *   - bootstrap.js (ES6 modules, espone window.FM)
 *
 * Uso:
 *   - da `views/partials/head.php` (condizionale su body.exercise-context):
 *     è l'unico punto che lo include. I modelli admin con `<head>` proprio
 *     che lo includevano erano serviti da LegacyController, tolto.
 *
 * NB: non emette il wrapper <head> — solo il contenuto interno. Il
 * chiamante decide dove metterlo.
 *
 * G22.S15.bis — TikZJax deprecato, vedi tikz-render-client.js per la
 * pipeline server-side via VPS pdflatex+dvisvgm (endpoint /tikz/render).
 */
?>
<link rel="stylesheet" href="/css/layout_es.css" type="text/css">
<link rel="stylesheet" href="/css/layout_editor.css" type="text/css">
<!-- 2026-09-04 — niente più preconnect ai CDN: MathJax, Lit, PDF.js e pako
     vengono dall'istanza (bundle Vite o public/vendor). -->

<!-- Quill: solo il foglio di stile, self-hosted in /vendor/quill/1.3.6/
     (Phase 25.Q.4, FND-VPS-002 conforme), caricato asincrono. Il JavaScript
     (215 KB per pagina) non lo istanziava nessun file ed è stato tolto il
     23/9/2026; il CSS resta finché non si contano in produzione i contenuti
     salvati con classi `ql-` (revisione architetturale del 23/9/2026, D-2). -->
<link href="/vendor/quill/1.3.6/quill.snow.css" rel="stylesheet"
      media="print" data-fm-css="print"
      integrity="sha384-07UbSXbd8HpaOfxZsiO6Y8H1HTX6v0J96b5qP6PKSpYEuSZSYD4GFFHlLRjvjVrL"
      crossorigin="anonymous">
<script<?= \App\Support\Csp::attributo() ?>>(function(l){l&&l.addEventListener("load",function(){this.media="all"},{once:true})})(document.currentScript.previousElementSibling)</script>
<noscript><link rel="stylesheet" href="/vendor/quill/1.3.6/quill.snow.css"></noscript>

<!-- 2026-05-24 Fase 4 perf — MathJax v4 conditional lazy load.
     Carica MathJax SOLO se il body contiene math syntax ($...$, \(...\),
     \[...\]) o elementi math-related. Esercizi LIN/LET/STO/GEO senza
     formule risparmiano ~280 KB (MathJax + STIX2 fonts).
     Detect al DOMContentLoaded così tutto il content esercizio è già
     parsato. Il loader async non blocca render comunque.

     Config inline DEVE essere prima del <script async> per essere
     valutato da MathJax al boot. Lo lasciamo inline (1 KB, no impact). -->
<!-- MathJax v4 — config (una sola, in _mathjax_loader.php) → loader async (conditional) -->
<?php include __DIR__ . '/_mathjax_loader.php'; ?>
<?php /* 23/9/2026 (A-19) — qui c'era una seconda copia della configurazione,
         identica a quella di _mathjax_loader.php tranne l'aggancio
         `startup.ready`: ora la configurazione si include, e questo script
         aggiunge solo l'aggancio, prima che il loader parta. */ ?>
<script<?= \App\Support\Csp::attributo() ?>>
    MathJax.startup = {
        ready: () => {
            try { localStorage.removeItem('MathJax-Menu-Settings'); } catch (_) {}
            console.log('[mathjax] ready hook, calling defaultReady');
            MathJax.startup.defaultReady();
            // Phase 15 — notifica typeset iniziale completo così
            // moduli (collapsible, ecc.) possono ricalcolare altezze.
            MathJax.startup.promise.then(() => {
                console.log('[mathjax] typeset done → fm:mathjax-ready');
                window.dispatchEvent(new CustomEvent('fm:mathjax-ready', { detail: { root: document } }));
            }).catch(err => console.error('[mathjax] startup.promise rejected:', err));
        }
    };
</script>
<!-- 2026-05-24 Fase 4 perf — MathJax loader conditional. Skip se nessun
     contenuto math nel body (esercizi LIN/LET puramente testuali). -->
<script<?= \App\Support\Csp::attributo() ?>>
(function () {
    function needsMathJax() {
        // 1. Marker espliciti (mjx-container = MathJax v4 output, fm-tex* = legacy)
        if (document.querySelector('mjx-container, .MathJax, [data-math], .math, .fm-tex, .fm-formula')) return true;
        // 2. TeX syntax raw nel testo: $...$, \(...\), \[...\]
        // Limit: testContent root (esclude head/script/style automatici)
        var txt = document.body.textContent || '';
        // Pattern: \( \[ $$ (delimiter aperti) o $X (singolo dollar seguito da non-spazio)
        return /\\\(|\\\[|\$\$|\$[^\s$]/.test(txt);
    }
    function loadMathJax() {
        var s = document.createElement('script');
        s.id = 'MathJax-script';
        s.async = true;
        // 2026-09-04 — servito dall'istanza (public/vendor, copiato da npm al
        // build da tools/build/vendor-assets.mjs), non più da un CDN.
        // L'indirizzo lo scrive _mathjax_loader.php (prima era ricalcolato qui).
        s.src = window.FM_MATHJAX_SRC;
        document.head.appendChild(s);
    }
    function maybeLoad() {
        if (document.getElementById('MathJax-script')) return true; // già caricato
        if (needsMathJax()) { loadMathJax(); return true; }
        return false;
    }
    // BUGFIX: il contenuto esercizi (con math \(...\)) viene spesso iniettato
    // ASYNC dopo DOMContentLoaded → al primo check needsMathJax() il math non
    // c'è ancora → MathJax non veniva mai caricato → formule grezze (anche dopo
    // reload). Ri-controlliamo a intervalli crescenti finché carica (o ci
    // arrendiamo per gli esercizi puramente testuali), + su navigazione SPA.
    function startWatch() {
        if (maybeLoad()) return;
        var delays = [400, 1200, 2500, 5000];
        (function next() {
            if (!delays.length || maybeLoad()) return;
            setTimeout(next, delays.shift());
        })();
        window.addEventListener('fm:navigated', maybeLoad);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startWatch, { once: true });
    } else {
        startWatch();
    }
})();
</script>

<!-- Sprint B (2026-06-02): jQuery CDN RIMOSSO — drag&drop ora via SortableJS
     (event-handler.js import vanilla), consumer con fallback vanilla. -->
<!-- G26.phase7.2 — Chosen rimosso (mai chiamato .chosen() in codice moderno). -->
<!-- G26.phase7.2 — Chosen CSS rimosso (era caricato in head.php). -->

<!-- ES6 modules entry point (window.FM.*). 2026-05-24 Fase 2: Vite manifest. -->
<?php if (class_exists(\App\Support\ViteManifest::class)
    && is_file(__DIR__ . '/../../public/build/manifest.json')):
    echo \App\Support\ViteManifest::script('js/modules/bootstrap.js');
else: ?>
<script<?= \App\Support\Csp::attributo() ?> type="module" src="/js/modules/bootstrap.js"></script>
<?php endif; ?>

<!-- Copilot AI rimosso (2026-08-26): il loader cercava api/copilot-ai.js sotto
     la document root (public/), quindi non si e' mai caricato. Vedi la nota in
     routes/web.php e docs/legal/ai-act-assessment.md § 3.2. -->

<?php
    // Tier 2 (Font Awesome): heavy assets caricati SOLO quando la pagina
    // lo richiede esplicitamente. Default ON per pagine /eser/ legacy.
    // Disabilitato per /studio/ DB-backed (HTML statico) impostando
    // $fmExerciseAssetsTier1 = true.
    //
    // G22.S15.bis — TikZJax DEPRECATO e completamente rimosso. Il render
    // TikZ avviene esclusivamente server-side via VPS (pdflatex+dvisvgm)
    // attraverso `tikz-render-client.js` → endpoint /tikz/render con
    // cache content-addressable (SHA-256). Vedi ADR-013.
    //
    // G26.phase7.1 — jQuery UI 1.12.1 rimosso: .sortable() / .draggable()
    // sostituiti da SortableJS (ESM, bundled via Vite) in event-handler.js.
    if (empty($fmExerciseAssetsTier1)):
?>
<!-- Lighthouse perf — Font Awesome ELIMINATO (75KB CSS + woff2 saved).
     5 icons usate (edit, times, link, search, robot) ora via SVG mask inline
     in css/modules/_fm-icons.css (~3KB CSS, 0 font load, 0 third-party CDN). -->
<?php endif; ?>
