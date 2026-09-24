<?php
/**
 * MathJax v4 — la configurazione, in un posto solo.
 *
 * La includono `_exercise_assets.php` (pagine di esercizi e verifiche, che
 * poi aggiunge l'aggancio `startup.ready` e il caricamento condizionato) e le
 * pagine che caricano MathJax per conto loro (PdfImportPageController: lo
 * carica js/entries/pdf-import.js al primo anteprima). Lo script di MathJax
 * NON è qui: la configurazione DEVE precedere il loader, che la legge al boot.
 * `window.FM_MATHJAX_SRC` è l'indirizzo del loader, per chi lo carica.
 *
 * 23/9/2026 (revisione architetturale A-19, R-3 passo 3) — la stessa
 * configurazione era scritta due volte, qui e in `_exercise_assets.php`, con
 * un «Allineata a …» come unica garanzia; e l'indirizzo del loader si
 * calcolava due volte. Adesso `_exercise_assets.php` include questo file.
 * Prova: tests/Unit/Views/MathJaxConfiguratoUnaVoltaTest.php.
 *
 * 2026-09-04 — MathJax e il font non arrivano più da un CDN: li copia da
 * node_modules `tools/build/vendor-assets.mjs` (prebuild) in public/vendor/.
 * `loader.paths.fonts` punta al vendor locale, così anche il font
 * (`[fonts]/%%FONT%%-font` → /vendor/@mathjax/mathjax-stix2-font) resta
 * sull'istanza. Cache-bust con la versione letta da
 * public/vendor/vendor-manifest.json.
 */
$__mjManifest = @json_decode((string)@file_get_contents(dirname(__DIR__, 2) . '/public/vendor/vendor-manifest.json'), true);
$__mjVersion  = is_array($__mjManifest) ? (string)($__mjManifest['mathjax'] ?? '') : '';
$__mjSrc      = '/vendor/mathjax/tex-mml-chtml.js' . ($__mjVersion !== '' ? '?v=' . rawurlencode($__mjVersion) : '');
?>
<script<?= \App\Support\Csp::attributo() ?>>
    window.FM_MATHJAX_SRC = <?= json_encode($__mjSrc, JSON_UNESCAPED_SLASHES) ?>;
    MathJax = {
        output: { font: 'mathjax-stix2' },
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
            renderActions: { addMenu: [], checkLoading: [] }
        },
        loader: {
            paths: { fonts: '/vendor/@mathjax' },
            load: ['a11y/assistive-mml', '[tex]/enclose', '[tex]/cancel', '[tex]/physics', '[tex]/mathtools', '[tex]/color']
        },
        tex: { packages: { '[+]': ['enclose', 'cancel', 'physics', 'mathtools', 'color'] } }
    };
</script>
