<?php
/**
 * Asset di head per contesto esercizi/verifiche (sostituisce il legacy
 * views/legacy/head-content.html).
 *
 * Include:
 *   - CSS scope esercizi (layout_es, layout_editor), Quill, jQuery UI,
 *     Font Awesome
 *   - JS core: jQuery + jQuery UI, Chosen, Quill
 *   - MathJax v4 config + loader (async)
 *   - Copilot AI (module admin)
 *   - bootstrap.js (ES6 modules, espone window.FM)
 *
 * Uso:
 *   - da `views/partials/head.php` (condizionale su body.exercise-context)
 *   - da template admin serviti via LegacyController (modelli_eser,
 *     modello_pag_esercizi ecc.) che hanno `<head>…</head>` esplicito
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
<!-- Resource hints — preconnect early per ridurre TLS handshake su 3G mobile. -->
<link rel="dns-prefetch" href="//cdn.jsdelivr.net">
<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>

<!-- Quill (rich editor, usato da admin + editor inline) -->
<!-- Phase 25.Q.4 — self-hosted in /vendor/quill/1.3.6/ (FND-VPS-002 conforme).
     Lighthouse perf win — async CSS + defer JS: Quill carica solo dopo
     click su ✎ editor button, non bloccante render iniziale (saving
     ~150KB sync parse + render-block CSS). -->
<link href="/vendor/quill/1.3.6/quill.snow.css" rel="stylesheet"
      media="print" data-fm-css="print"
      integrity="sha384-07UbSXbd8HpaOfxZsiO6Y8H1HTX6v0J96b5qP6PKSpYEuSZSYD4GFFHlLRjvjVrL"
      crossorigin="anonymous">
<script>(function(l){l&&l.addEventListener("load",function(){this.media="all"},{once:true})})(document.currentScript.previousElementSibling)</script>
<noscript><link rel="stylesheet" href="/vendor/quill/1.3.6/quill.snow.css"></noscript>
<script defer src="/vendor/quill/1.3.6/quill.min.js"
        integrity="sha384-AOnYUgW6MQR78EvTqtkbNavz7dVI7dtLm7QdcIMBoy06adLIzNT40hHPYl9Rr5m5"
        crossorigin="anonymous"></script>

<!-- 2026-05-24 Fase 4 perf — MathJax v4 conditional lazy load.
     Carica MathJax SOLO se il body contiene math syntax ($...$, \(...\),
     \[...\]) o elementi math-related. Esercizi LIN/LET/STO/GEO senza
     formule risparmiano ~280 KB (MathJax + STIX2 fonts).
     Detect al DOMContentLoaded così tutto il content esercizio è già
     parsato. Il loader async non blocca render comunque.

     Config inline DEVE essere prima del <script async> per essere
     valutato da MathJax al boot. Lo lasciamo inline (1 KB, no impact). -->
<!-- MathJax v4 — config inline → loader async (conditional) -->
<script>
    MathJax = {
        output: {
            font: 'mathjax-stix2'
        },
        options: {
            // Perf (ADR-023) — lazy typeset: salta le formule dentro i
            // collapsible collassati (class fm-mj-lazy). collapsible.js le
            // impagina on-demand all'espansione (rimuove la classe + typeset).
            // `fm-editor-field` — l'area di EDIT grezza (contenteditable) NON
            // deve essere tipesettata: deve mostrare il sorgente \(...\) per
            // poterlo modificare. La PREVIEW (.fm-editor-preview) e il contenuto
            // esercizio (post-edit, on top) NON hanno questa classe → renderizzano.
            ignoreHtmlClass: 'fm-mj-lazy|fm-editor-field',
            enableMenu: false,
            enableEnrichment: false,
            enableComplexity: false,
            enableExplorer: false,
            // G22.S15 — `enableAssistiveMml` non e' un'option valida in MathJax
            // v4 (rimossa). Va settata dentro `menuOptions.settings.assistiveMml`.
            enableSpeech: false,
            enableBraille: false,
            menuOptions: {
                settings: {
                    enrich: false,
                    // WCAG 1.1.1/1.3.1 — MathML ASSISTIVO: MathJax aggiunge accanto
                    // a ogni formula un <mjx-assistive-mml> (visivamente nascosto)
                    // che gli screen reader leggono come matematica. Senza questo,
                    // l'output CHTML è muto per gli utenti non vedenti. Richiede
                    // il componente 'a11y/assistive-mml' nel loader (sotto).
                    // Scelta leggera (no speech/explorer/menu): adatta al target perf.
                    assistiveMml: true,
                    speech: false,
                    braille: false,
                    collapsible: false,
                    autocollapse: false,
                    help: false
                }
            },
            renderActions: {
                addMenu: [],
                checkLoading: []
            }
        },
        loader: { load: ['a11y/assistive-mml', '[tex]/enclose', '[tex]/cancel', '[tex]/physics', '[tex]/mathtools', '[tex]/color'] },
        tex: {
            packages: { '[+]': ['enclose', 'cancel', 'physics', 'mathtools', 'color'] }
        },
        startup: {
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
        }
    };
</script>
<!-- 2026-05-24 Fase 4 perf — MathJax loader conditional. Skip se nessun
     contenuto math nel body (esercizi LIN/LET puramente testuali). -->
<script>
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
        s.src = 'https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js';
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
<script type="module" src="/js/modules/bootstrap.js"></script>
<?php endif; ?>

<!-- Copilot AI (admin authoring assistant) — opzionale, caricato solo se
     l'endpoint risponde 200. Gli asset sono auth-protetti quindi per
     studenti non loggati il tag <script>/<link> genererebbe 302. Li
     escludo di default; l'admin dashboard può re-iniettarli on demand. -->
<?php
    $_fm_copilot = $_SERVER['DOCUMENT_ROOT'] . '/api/copilot-ai.js';
    $_fm_copilot_enable = is_file($_fm_copilot) && (($_SESSION['user_role'] ?? '') === 'administrator');
    if ($_fm_copilot_enable):
?>
<script src="/api/copilot-ai.js" defer></script>
<link rel="stylesheet" href="/api/copilot-ai.css">
<script src="/api/copilot-ai-init.js" defer></script>
<?php endif; ?>

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
