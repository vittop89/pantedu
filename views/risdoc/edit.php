<?php
/**
 * Risdoc override editor (U6).
 * @var array   $tmpl
 * @var ?array  $logicSpec   decoded JSON
 * @var string  $title
 * @var string  $category
 * @var string  $numArg
 * @var bool    $hasHtml $hasTex $hasCss
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>✎ <?= $title ?> — Editor Risdoc</title>
    <link rel="stylesheet" href="/css/layout.css">
    <style>
        /* Azzera padding-top:48px aggiunto da layout_es.css quando
           body.exercise-context (il path /risdoc/* matcha isExerciseRoute).
           L'editor non ha upbar → quel padding lascerebbe spazio vuoto. */
        body.fm-studio-risdoc-edit,
        body.fm-studio-risdoc-edit.exercise-context {
            margin: 0 !important;
            padding: 0 !important;
            background: #f5f7fa;
            font-family: system-ui, sans-serif;
            overflow: hidden; /* editor e' full-viewport, niente scroll body */
        }
        .fm-re-editor { display: grid; grid-template-rows: 48px 1fr; height: 100vh; }
        .fm-re-topbar { display: flex; align-items: center; justify-content: space-between;
            padding: 0 16px; background: #1e293b; color: #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .fm-re-title { display: flex; align-items: center; gap: 10px; font-size: 0.8125rem; }
        .fm-re-title strong { font-size: 0.875rem; }
        .fm-re-tabs { display: flex; gap: 2px; }
        .fm-re-tab { background: #334155; color: #fff; border: 0; padding: 6px 14px;
            font-size: 0.75rem; cursor: pointer; border-radius: 4px 4px 0 0; }
        .fm-re-tab[aria-selected="true"] { background: #3b82f6; font-weight: 600; }
        .fm-re-tab:hover:not([aria-selected="true"]) { background: #475569; }
        .fm-re-actions { display: flex; gap: 6px; }
        .fm-re-btn { background: #475569; color: #fff; border: 0; padding: 6px 12px;
            border-radius: 4px; font-size: 0.75rem; cursor: pointer; text-decoration: none; display: inline-block; }
        .fm-re-btn--primary { background: #3b82f6; }
        .fm-re-btn--primary:hover { background: #2563eb; }
        .fm-re-btn--danger { background: #991b1b; }
        .fm-re-btn--danger:hover { background: #7f1d1d; }
        .fm-re-status { font-size: 0.625rem; min-width: 110px; text-align: right; padding: 0 10px; opacity: .8; }
        .fm-re-status[data-status="saving"] { color: #fbbf24; }
        .fm-re-status[data-status="saved"]  { color: #34d399; }
        .fm-re-status[data-status="error"]  { color: #f87171; }

        .fm-re-main { display: grid; grid-template-columns: 1fr 340px; overflow: hidden; }
        .fm-re-editor-pane { display: flex; flex-direction: column; background: #fff;
            border-right: 1px solid #e2e8f0; }
        .fm-re-textarea { flex: 1; border: 0; outline: 0; padding: 16px; font-family:
            'Consolas','Monaco','Courier New',monospace; font-size: 0.8125rem; line-height: 1.55;
            resize: none; background: #fff; }
        .fm-re-textarea[data-kind="tex"] { background: #fffbeb; }
        .fm-re-textarea[data-kind="css"] { background: #f0f9ff; }

        .fm-re-guide { background: #fff; overflow: auto; padding: 12px 16px; font-size: 0.75rem; }
        .fm-re-guide h3 { margin: 12px 0 6px; font-size: 0.8125rem; color: #1e293b; }
        .fm-re-guide-entry { padding: 8px; margin: 4px 0; background: #f8fafc; border-left: 3px solid #3b82f6; border-radius: 3px; }
        .fm-re-guide-entry code { background: #e2e8f0; padding: 1px 5px; border-radius: 3px;
            font-size: 0.6875rem; display: inline-block; margin: 2px 0; }
        .fm-re-guide-entry .fm-arrow { color: #64748b; padding: 0 6px; }
        .fm-re-guide-entry .fm-desc { color: #64748b; margin-top: 4px; font-size: 0.6875rem; }
        .fm-re-badge { background: rgba(255,255,255,.15); padding: 2px 8px; border-radius: 10px; font-size: 0.625rem; }
    </style>
</head>
<body class="fm-studio-risdoc-edit" data-fm-full-reload="1">
<div class="fm-re-editor" data-template-id="<?= (int)$tmpl['id'] ?>">
    <header class="fm-re-topbar">
        <div class="fm-re-title">
            <strong>✎ <?= $category ?> · <?= $numArg ?></strong>
            <span class="fm-opacity-75"><?= $title ?></span>
            <span class="fm-re-badge">Editor override</span>
        </div>
        <div class="fm-re-tabs" role="tablist">
            <!-- Phase 24.30 — solo TexCommon. HTML/TeX/CSS/JSON e Immagini
                 deprecati nell'editor advanced: l'editing HTML/TeX/CSS/JSON
                 ora avviene tramite il PT editor unificato. Le immagini
                 sono gestite dal popup nella pt-toolbar. -->
            <button class="fm-re-tab" data-kind="texCommon" role="tab" aria-selected="true" title="Personalizza main.tex / risdoc.sty / intestaLAteX_IIS.tex">📦 TexCommon</button>
        </div>
        <div class="fm-re-actions">
            <button class="fm-re-btn fm-re-btn--primary" data-action="save">💾 Salva</button>
            <button class="fm-re-btn fm-re-btn--danger"  data-action="revert" title="Ripristina al sorgente master (rimuove l'override personale per il kind corrente)">↺ Ripristina</button>
            <a href="/risdoc/view/<?= (int)$tmpl['id'] ?>" class="fm-re-btn">👁 Anteprima</a>
            <a href="/" class="fm-re-btn">↩ Home</a>
        </div>
        <div class="fm-re-status" data-status="idle"></div>
    </header>

    <div class="fm-re-drift-banner fm-banner-warn" >
        <strong>⚠ Template aggiornato:</strong> il contenuto sorgente è cambiato dopo il tuo fork.
        <span class="fm-re-drift-list"></span>
        <button type="button" class="fm-pill-warn-action">Chiudi</button>
        <script>document.currentScript.previousElementSibling.addEventListener("click",function(){this.parentElement.style.display="none"})</script>
    </div>
    <div class="fm-re-main">
        <div class="fm-re-editor-pane">
            <div class="fm-re-jsonpicker fm-banner-info" >
                <label class="fm-text-11 fm-text-2 fm-mr-1">File JSON:</label>
                <select class="fm-re-json-select fm-text-xs fm-py-1 fm-px-1 fm-min-w-80" ></select>
                <span class="fm-re-json-validity fm-ml-3 fm-text-11" ></span>
            </div>
            <textarea class="fm-re-textarea" data-kind="html"
                placeholder="Caricamento HTML…" spellcheck="false"></textarea>
        </div>
        <aside class="fm-re-guide" aria-label="Guida mapping HTML ↔ TeX">
            <h3>📖 Mapping HTML ↔ TeX</h3>
            <?php if (!empty($logicSpec['mappings'])): foreach ($logicSpec['mappings'] as $m): ?>
                <div class="fm-re-guide-entry">
                    <code><?= htmlspecialchars($m['html'] ?? '', ENT_QUOTES) ?></code>
                    <span class="fm-arrow">→</span>
                    <code><?= htmlspecialchars($m['tex'] ?? '', ENT_QUOTES) ?></code>
                    <?php if (!empty($m['desc'])): ?>
                        <div class="fm-desc"><?= htmlspecialchars($m['desc'], ENT_QUOTES) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($logicSpec['conditional_blocks'])): ?>
            <h3>🎚 Blocchi condizionali (solo TeX)</h3>
            <?php foreach ($logicSpec['conditional_blocks'] as $c): ?>
                <div class="fm-re-guide-entry">
                    <code><?= htmlspecialchars($c['tex'] ?? '', ENT_QUOTES) ?></code>
                    <div class="fm-desc"><?= htmlspecialchars($c['desc'] ?? '', ENT_QUOTES) ?></div>
                </div>
            <?php endforeach; endif; ?>

            <?php if (!empty($logicSpec['placeholders'])): ?>
            <h3>🧩 Placeholder TeX</h3>
            <?php foreach ($logicSpec['placeholders'] as $p): ?>
                <div class="fm-re-guide-entry">
                    <code><?= htmlspecialchars($p['tex'] ?? '', ENT_QUOTES) ?></code>
                    <div class="fm-desc"><?= htmlspecialchars($p['desc'] ?? '', ENT_QUOTES) ?></div>
                </div>
            <?php endforeach; endif; ?>
        </aside>
    </div>
</div>
<?php if (class_exists(\App\Support\ViteManifest::class)
    && is_file(__DIR__ . '/../../public/build/manifest.json')):
    echo \App\Support\ViteManifest::script('js/modules/bootstrap.js');
else: ?>
<script type="module" src="/js/modules/bootstrap.js"></script>
<?php endif; ?>
</body>
</html>
