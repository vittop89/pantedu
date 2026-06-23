<?php /* Super-admin panel per risdoc per-teacher (U8). */ ?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <title>Admin · Risdoc per-teacher</title>
    <link rel="stylesheet" href="/css/layout.css">
    <style>
        body.fm-admin-risdoc { margin: 0; background: #f5f7fa; font-family: system-ui, sans-serif; }
        .fm-ar-wrap { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .fm-ar-topbar { display: flex; justify-content: space-between; align-items: center;
            background: #1e293b; color: #fff; padding: 12px 16px; border-radius: 6px; margin-bottom: 12px; }
        .fm-ar-tabs { display: flex; gap: 4px; margin-bottom: 12px; }
        .fm-ar-tab { padding: 8px 16px; background: #e2e8f0; border: 0; cursor: pointer;
            border-radius: 4px 4px 0 0; font-size: 0.8125rem; }
        .fm-ar-tab[aria-selected="true"] { background: #3b82f6; color: #fff; font-weight: 600; }
        .fm-ar-panel { background: #fff; border-radius: 4px; padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.08); min-height: 400px; }
        table.fm-ar-tbl { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
        table.fm-ar-tbl th, table.fm-ar-tbl td { padding: 6px 10px; border-bottom: 1px solid #e2e8f0; text-align: left; }
        table.fm-ar-tbl th { background: #f8fafc; font-weight: 600; color: #334155; font-size: 0.75rem; }
        .fm-ar-pill { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.625rem;
            background: #e2e8f0; color: #334155; }
        .fm-ar-pill--warn   { background: #fef3c7; color: #92400e; }
        .fm-ar-pill--ok     { background: #d1fae5; color: #065f46; }
        .fm-ar-pill--danger { background: #fee2e2; color: #991b1b; }
        .fm-ar-actions button { margin: 0 2px; padding: 4px 10px; font-size: 0.6875rem;
            background: #3b82f6; color: #fff; border: 0; border-radius: 3px; cursor: pointer; }
        .fm-ar-actions button:hover { background: #2563eb; }
        .fm-ar-inline-list { display: flex; flex-wrap: wrap; gap: 4px; }
        .fm-ar-inline-list span { background: #dbeafe; padding: 2px 6px; border-radius: 3px;
            font-size: 0.6875rem; color: #1e40af; }
        /* Phase 24.54 — sezioni categoria + bottone link view */
        .fm-ar-cat-head {
            font-size: 0.8125rem; font-weight: 600; color: #475569;
            margin: 18px 0 6px; padding: 4px 8px;
            background: rgba(59, 130, 246, 0.08); border-left: 3px solid #3b82f6;
            border-radius: 0 4px 4px 0;
        }
        .fm-ar-cat-head:first-child { margin-top: 0; }
        .fm-numarg {
            display: inline-block; padding: 1px 6px; min-width: 32px; text-align: center;
            background: #fef3c7; color: #92400e; font-size: 0.625rem; font-weight: 600;
            border-radius: 3px;
        }
        .fm-ar-link-btn {
            display: inline-block; padding: 4px 8px; margin: 0 2px;
            background: #475569; color: #fff !important; text-decoration: none;
            border-radius: 3px; font-size: 0.6875rem;
        }
        .fm-ar-link-btn:hover { background: #334155; }
        body.fm-dark .fm-ar-cat-head {
            color: #cbd5e1; background: rgba(59, 130, 246, 0.15);
            border-left-color: #60a5fa;
        }
        body.fm-dark .fm-numarg { background: #78350f; color: #fef3c7; }
        /* Phase 24.51 — overlay editor PT seed */
        .fm-ar-pt-backdrop {
            position: fixed; inset: 0; z-index: 10000;
            background: rgba(15, 23, 42, 0.7);
            display: flex; align-items: center; justify-content: center;
            padding: 24px;
        }
        .fm-ar-pt-modal {
            background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.4);
            width: 100%; max-width: 880px; max-height: 90vh;
            display: flex; flex-direction: column; overflow: hidden;
        }
        .fm-ar-pt-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 18px; background: #1e293b; color: #fff;
            font-weight: 600; font-size: 0.875rem;
        }
        .fm-ar-pt-header code { background: rgba(255,255,255,.15); padding: 2px 8px;
            border-radius: 3px; font-family: monospace; }
        .fm-ar-pt-close {
            background: transparent; border: 0; color: #cbd5e1; font-size: 1.375rem;
            line-height: 1; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 4px;
        }
        .fm-ar-pt-close:hover { color: #fff; background: rgba(255,255,255,.1); }
        .fm-ar-pt-body { padding: 16px 18px; flex: 1; overflow: auto; }
        .fm-ar-pt-actions {
            display: flex; gap: 8px; padding: 10px 18px;
            background: #f1f5f9; border-top: 1px solid #cbd5e1;
        }
        .fm-ar-pt-actions button {
            padding: 6px 14px; border: 0; border-radius: 4px; cursor: pointer; font-size: 0.8125rem;
        }
        .fm-ar-pt-clear  { background: #fee2e2; color: #991b1b; }
        .fm-ar-pt-clear:hover { background: #fecaca; }
        .fm-ar-pt-cancel { background: #e2e8f0; color: #334155; }
        .fm-ar-pt-cancel:hover { background: #cbd5e1; }
        .fm-ar-pt-save   { background: #059669; color: #fff; font-weight: 600; }
        .fm-ar-pt-save:hover { background: #047857; }

        /* Phase 24.56 — Schema editor: preview + JSON editor side-by-side */
        .fm-ar-schema-backdrop {
            position: fixed; inset: 0; z-index: 10000;
            background: rgba(15, 23, 42, 0.7);
            display: flex; align-items: center; justify-content: center;
            padding: 16px;
        }
        .fm-ar-schema-modal {
            background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,.4);
            width: 100%; max-width: 1400px; height: 90vh;
            display: flex; flex-direction: column; overflow: hidden;
        }
        .fm-ar-schema-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 18px; background: #1e293b; color: #fff;
            font-weight: 600; font-size: 0.875rem;
        }
        .fm-ar-schema-header code { background: rgba(255,255,255,.15); padding: 2px 8px;
            border-radius: 3px; font-family: monospace; }
        .fm-ar-schema-close {
            background: transparent; border: 0; color: #cbd5e1; font-size: 1.375rem;
            line-height: 1; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 4px;
        }
        .fm-ar-schema-close:hover { color: #fff; background: rgba(255,255,255,.1); }
        .fm-ar-schema-body { display: grid; grid-template-columns: 1fr 1fr; gap: 1px; flex: 1; min-height: 0; background: #cbd5e1; }
        .fm-ar-schema-pane { display: flex; flex-direction: column; background: #fff; min-height: 0; overflow: hidden; }
        .fm-ar-schema-pane__title {
            padding: 6px 12px; background: #f1f5f9; color: #475569;
            font-size: 0.75rem; font-weight: 600; border-bottom: 1px solid #e2e8f0;
            flex-shrink: 0;
        }
        .fm-ar-schema-preview {
            flex: 1; width: 100%; border: 0;
            background: var(--fm-risdoc-bg, powderblue);
        }
        .fm-ar-schema-pane--editor { padding: 8px 12px; gap: 6px; }
        .fm-ar-schema-text {
            flex: 1; resize: none;
            font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
            font-size: 0.75rem; line-height: 1.5;
            padding: 8px; border: 1px solid #cbd5e1; border-radius: 4px;
            background: #f8fafc; color: #1e293b;
        }
        .fm-ar-schema-meta { font-size: 0.6875rem; color: #64748b; min-height: 14px; }
        .fm-ar-schema-error { font-size: 0.75rem; color: #b91c1c; min-height: 14px; }
        .fm-ar-schema-actions {
            display: flex; gap: 8px; padding: 10px 18px;
            background: #f1f5f9; border-top: 1px solid #cbd5e1;
            flex-shrink: 0;
        }
        .fm-ar-schema-actions button {
            padding: 6px 14px; border: 0; border-radius: 4px; cursor: pointer; font-size: 0.8125rem;
        }
        .fm-ar-schema-revert   { background: #fef3c7; color: #92400e; }
        .fm-ar-schema-revert:hover { background: #fde68a; }
        .fm-ar-schema-validate { background: #e0e7ff; color: #3730a3; }
        .fm-ar-schema-validate:hover { background: #c7d2fe; }
        .fm-ar-schema-cancel   { background: #e2e8f0; color: #334155; }
        .fm-ar-schema-cancel:hover { background: #cbd5e1; }
        .fm-ar-schema-save     { background: #059669; color: #fff; font-weight: 600; }
        .fm-ar-schema-save:hover { background: #047857; }

        /* ────────── Dark mode ────────── */
        body.fm-dark.fm-admin-risdoc { background: #0f172a; color: #e2e8f0; }
        body.fm-dark .fm-ar-topbar { background: #0b1220; }
        body.fm-dark .fm-ar-tab { background: #1e293b; color: #cbd5e1; }
        body.fm-dark .fm-ar-tab[aria-selected="true"] { background: #2563eb; color: #fff; }
        body.fm-dark .fm-ar-panel {
            background: #1e293b; color: #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,.4);
        }
        body.fm-dark table.fm-ar-tbl th { background: #0f172a; color: #94a3b8; }
        body.fm-dark table.fm-ar-tbl th,
        body.fm-dark table.fm-ar-tbl td { border-bottom-color: #334155; }
        body.fm-dark table.fm-ar-tbl td code { background: rgba(255,255,255,0.06); color: #e2e8f0; padding: 1px 5px; border-radius: 3px; }
        body.fm-dark .fm-ar-pill { background: #334155; color: #cbd5e1; }
        body.fm-dark .fm-ar-pill--warn   { background: #78350f; color: #fef3c7; }
        body.fm-dark .fm-ar-pill--ok     { background: #064e3b; color: #d1fae5; }
        body.fm-dark .fm-ar-pill--danger { background: #7f1d1d; color: #fecaca; }
        body.fm-dark .fm-ar-inline-list span { background: #1e3a8a; color: #dbeafe; }
        body.fm-dark .fm-ar-actions button { background: #2563eb; }
        body.fm-dark .fm-ar-actions button:hover { background: #1d4ed8; }
        /* Form/inputs nei detail (manage) */
        body.fm-dark .fm-ar-panel select,
        body.fm-dark .fm-ar-panel input[type="checkbox"] {
            background: #0f172a; color: #e2e8f0; border: 1px solid #334155;
        }
        /* Overlay PT editor */
        body.fm-dark .fm-ar-pt-modal {
            background: #1e293b; color: #e2e8f0;
            box-shadow: 0 10px 40px rgba(0,0,0,.7);
        }
        body.fm-dark .fm-ar-pt-header { background: #0b1220; }
        body.fm-dark .fm-ar-pt-body { color: #e2e8f0; }
        body.fm-dark .fm-ar-pt-body p { color: #cbd5e1; }
        body.fm-dark .fm-ar-pt-editor-host {
            background: #0f172a !important; border-color: #334155 !important;
        }
        body.fm-dark .fm-ar-pt-actions {
            background: #0f172a; border-top-color: #334155;
        }
        body.fm-dark .fm-ar-pt-cancel { background: #334155; color: #e2e8f0; }
        body.fm-dark .fm-ar-pt-cancel:hover { background: #475569; }
        body.fm-dark .fm-ar-pt-clear { background: #7f1d1d; color: #fecaca; }
        body.fm-dark .fm-ar-pt-clear:hover { background: #991b1b; }
        /* Phase 24.55 — dark mode overlay source */
        body.fm-dark .fm-ar-src-modal { background: #1e293b; color: #e2e8f0; box-shadow: 0 10px 40px rgba(0,0,0,.7); }
        body.fm-dark .fm-ar-src-header { background: #0b1220; }
        body.fm-dark .fm-ar-src-body p { color: #cbd5e1; }
        body.fm-dark .fm-ar-src-tab { background: #334155; color: #cbd5e1; }
        body.fm-dark .fm-ar-src-tab.is-active { background: #2563eb; color: #fff; }
        body.fm-dark .fm-ar-src-text {
            background: #0f172a; color: #e2e8f0; border-color: #334155;
        }
        body.fm-dark .fm-ar-src-actions { background: #0f172a; border-top-color: #334155; }
        body.fm-dark .fm-ar-src-revert { background: #78350f; color: #fef3c7; }
        body.fm-dark .fm-ar-src-revert:hover { background: #92400e; }
        body.fm-dark .fm-ar-src-cancel { background: #334155; color: #e2e8f0; }
        body.fm-dark .fm-ar-src-cancel:hover { background: #475569; }
        /* Phase 24.56 — dark mode schema editor */
        body.fm-dark .fm-ar-schema-modal { background: #1e293b; color: #e2e8f0; box-shadow: 0 10px 40px rgba(0,0,0,.7); }
        body.fm-dark .fm-ar-schema-header { background: #0b1220; }
        body.fm-dark .fm-ar-schema-body { background: #334155; }
        body.fm-dark .fm-ar-schema-pane { background: #1e293b; }
        body.fm-dark .fm-ar-schema-pane__title { background: #0f172a; color: #cbd5e1; border-bottom-color: #334155; }
        body.fm-dark .fm-ar-schema-text { background: #0f172a; color: #e2e8f0; border-color: #334155; }
        body.fm-dark .fm-ar-schema-actions { background: #0f172a; border-top-color: #334155; }
        body.fm-dark .fm-ar-schema-revert { background: #78350f; color: #fef3c7; }
        body.fm-dark .fm-ar-schema-revert:hover { background: #92400e; }
        body.fm-dark .fm-ar-schema-validate { background: #312e81; color: #e0e7ff; }
        body.fm-dark .fm-ar-schema-validate:hover { background: #3730a3; }
        body.fm-dark .fm-ar-schema-cancel { background: #334155; color: #e2e8f0; }
        body.fm-dark .fm-ar-schema-cancel:hover { background: #475569; }
    </style>
</head>
<body class="fm-admin-risdoc">
<div class="fm-ar-wrap" id="fm-ar-root">
    <header class="fm-ar-topbar">
        <h1 class="fm-m-0 fm-text-base">⚙ Admin · Risdoc per-teacher</h1>
        <div>
            <a href="/admin/tools" class="fm-text-muted fm-text-xs fm-no-underline">← Tools</a>
            &nbsp;·&nbsp;
            <a href="/" class="fm-text-muted fm-text-xs fm-no-underline">Home</a>
        </div>
    </header>

    <div class="fm-ar-tabs" role="tablist">
        <button class="fm-ar-tab" data-tab="templates" aria-selected="true">📋 Template + Visibility</button>
        <button class="fm-ar-tab" data-tab="pending"   aria-selected="false">🛡 Modifiche in revisione <span id="fm-ar-pending-badge" class="fm-ar-pill fm-d-none" ></span></button>
        <button class="fm-ar-tab" data-tab="drift" aria-selected="false">⚠ Source drift</button>
    </div>

    <div class="fm-ar-panel" id="fm-ar-panel">
        <div class="fm-ar-loading">Caricamento…</div>
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
