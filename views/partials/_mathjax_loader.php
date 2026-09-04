<?php
/**
 * MathJax v4 — SOLO configurazione (inline), riusabile da pagine che non
 * includono `_exercise_assets.php`. Lo script loader NON è qui: va caricato
 * on-demand dal chiamante (es. js/entries/pdf-import.js al primo preview) con
 *   <script id="MathJax-script" async src="https://cdn.jsdelivr.net/npm/mathjax@4/tex-mml-chtml.js">
 * La config DEVE precedere il loader. Allineata a _exercise_assets.php.
 */
?>
<script>
    MathJax = {
        output: { font: 'mathjax-stix2' },
        options: {
            ignoreHtmlClass: 'fm-mj-lazy|fm-editor-field',
            enableMenu: false,
            enableEnrichment: false,
            enableComplexity: false,
            enableExplorer: false,
            enableSpeech: false,
            enableBraille: false,
            menuOptions: {
                // assistiveMml: true → MathML nascosto per screen reader (WCAG
                // 1.1.1/1.3.1); richiede 'a11y/assistive-mml' nel loader.
                settings: {
                    enrich: false, assistiveMml: true, speech: false,
                    braille: false, collapsible: false, autocollapse: false, help: false
                }
            },
            renderActions: { addMenu: [], checkLoading: [] }
        },
        loader: { load: ['a11y/assistive-mml', '[tex]/enclose', '[tex]/cancel', '[tex]/physics', '[tex]/mathtools', '[tex]/color'] },
        tex: { packages: { '[+]': ['enclose', 'cancel', 'physics', 'mathtools', 'color'] } }
    };
</script>
