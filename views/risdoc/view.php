<?php
/**
 * View template risdoc (U5).
 *
 * @var array  $tmpl
 * @var string $htmlBody   — body HTML risolto (override o source)
 * @var ?string $cssBody   — CSS associato
 * @var string $title
 * @var string $category
 * @var string $numArg
 * @var string $origin
 * @var string $sourceBadge
 * @var bool   $canEdit
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?> — Risdoc</title>

    <?php if ($origin === 'risdoc'): ?>
    <!-- Phase 21 — riscrivo URL a forma legacy-compatibile PRIMA di risdoc.js
         (che legge location.pathname per determinare currentPage). -->
    <script>
        if (location.pathname.startsWith('/risdoc/view/')) {
            try { history.replaceState(null, '', <?= json_encode($legacyUrl, JSON_UNESCAPED_SLASHES) ?>); } catch (_) {}
        }
    </script>
    <!-- Sprint B (2026-06-02): jQuery rimosso (view non più renderizzata da
         alcun controller; legacy templates → renderWebComponent vanilla). MathJax + jszip restano. -->
    <script type="text/javascript" async
        src="https://cdnjs.cloudflare.com/ajax/libs/mathjax/2.7.7/MathJax.js?config=TeX-MML-AM_CHTML"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <?php endif; ?>

    <link rel="stylesheet" href="/css/layout.css">
    <?php if (!empty($cssBody)): ?>
    <style><?= $cssBody ?></style>
    <?php endif; ?>

    <?php if (!empty($headExtra)): ?>
    <!-- Head content ereditato dal template legacy (<style>, <script> inline, ecc.) -->
    <?= $headExtra /* trusted: resolver output */ ?>
    <?php endif; ?>
    <style>
        /* Phase 21 — barra modifiche minimale SOPRA l'header legacy del template.
         * Non usa position:sticky per non coprire l'header. Il template legacy
         * conserva completamente il suo look (titolo + btn APRI/DOWNLOAD + selettori). */
        .fm-risdoc-toolbar {
            background: #1e293b; color: #fff; padding: 6px 12px;
            display: flex; align-items: center; gap: 8px;
            border-bottom: 1px solid #0f172a; font-size: 0.75rem;
        }
        .fm-risdoc-toolbar__meta { flex: 1; opacity: .8; font-size: 0.6875rem; }
        .fm-risdoc-toolbar__meta code { background: rgba(255,255,255,.12); padding: 1px 6px; border-radius: 3px; font-size: 0.625rem; }
        .fm-risdoc-btn {
            background: #475569; color: #fff; border: 0; padding: 5px 12px;
            border-radius: 3px; font-size: 0.75rem; cursor: pointer;
            text-decoration: none; display: inline-block;
        }
        .fm-risdoc-btn:hover  { background: #64748b; }
        .fm-risdoc-btn--primary { background: #3b82f6; }
        .fm-risdoc-btn--primary:hover { background: #2563eb; }
        .fm-risdoc-badge { font-size: 0.625rem; padding: 1px 8px; border-radius: 10px; background: rgba(255,255,255,.15); margin-left: 6px; }
        .fm-risdoc-save-status { font-size: 0.625rem; padding: 0 8px; opacity: .7; min-width: 80px; text-align: right; }
        .fm-risdoc-save-status[data-status="saving"] { color: #fbbf24; }
        .fm-risdoc-save-status[data-status="saved"]  { color: #34d399; }
        .fm-risdoc-save-status[data-status="error"]  { color: #f87171; }
    </style>
</head>
<body class="fm-studio-risdoc">
<div class="fm-risdoc-view" data-template-id="<?= (int)$tmpl['id'] ?>" data-origin="<?= $origin ?>" data-category="<?= $category ?>">
    <!-- Barra modifiche (minimale) sopra l'header legacy del template -->
    <div class="fm-risdoc-toolbar">
        <span class="fm-risdoc-toolbar__meta">
            <code><?= $category ?> · <?= $numArg ?></code>
            <?= $title ?>
            <span class="fm-risdoc-badge"><?= $sourceBadge ?></span>
        </span>
        <?php if ($canEdit): ?>
        <a href="/risdoc/edit/<?= (int)$tmpl['id'] ?>" class="fm-risdoc-btn fm-risdoc-btn--primary" title="Editor avanzato HTML/TeX/CSS/JSON">✎ Editor avanzato</a>
        <?php endif; ?>
        <a href="/" class="fm-risdoc-btn">↩ Home</a>
        <div class="fm-risdoc-save-status" data-status="idle"></div>
    </div>

    <!-- Header + body legacy del template PHP (inalterato) -->
    <main id="fm-risdoc-content">
        <?= $htmlBody /* trusted: risolto dal resolver server-side */ ?>
    </main>
</div>

<?php if (class_exists(\App\Support\ViteManifest::class)
    && is_file(__DIR__ . '/../../public/build/manifest.json')):
    echo \App\Support\ViteManifest::script('js/modules/bootstrap.js');
else: ?>
<script type="module" src="/js/modules/bootstrap.js"></script>
<?php endif; ?>
</body>
</html>
