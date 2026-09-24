<?php
/** G20.1 — Editor template docente (verifiche + esercizi unificati). */
/** @var string $tab — 'verifiche' (default) | 'esercizi' */
/** @var array|null $user */
$pageTitle    = 'PANTEDU — I miei modelli';
$bodyClass    = 'fm-area-docente-templates';
$currentRoute = '/area-docente/templates';
$tab          = $tab ?? 'verifiche';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>📝 I miei modelli <button type="button" class="fm-infotip" aria-label="Info modelli"><span class="fm-infotip__body" hidden>Personalizza i tuoi modelli per le <strong>verifiche</strong> (file LaTeX del preambolo, intestazione, griglie) e gli <strong>esercizi</strong> (Collezione, Risposta multipla, Vero/Falso).</span></button></h1>
    </header>

    <!-- G22.S15.bis Fase 5 — sub-tabs con classi CSS dark-aware (vedi
         layout.css `.fm-subtabs` / `.fm-subtab`). Niente inline-style
         hardcoded che rompono il dark theme. -->
    <nav class="fm-subtabs" role="tablist">
        <a href="/area-docente/templates?tab=verifiche"
           class="fm-subtab<?= $tab === 'verifiche' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'verifiche' ? 'true' : 'false' ?>">
            🧪 Verifiche LaTeX
        </a>
        <a href="/area-docente/templates?tab=esercizi"
           class="fm-subtab<?= $tab === 'esercizi' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'esercizi' ? 'true' : 'false' ?>">
            📐 Esercizi (VF/RM/Collezione)
        </a>
        <a href="/area-docente/templates?tab=risdoc"
           class="fm-subtab<?= $tab === 'risdoc' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'risdoc' ? 'true' : 'false' ?>">
            📝 Modelli risdoc
        </a>
        <a href="/area-docente/templates?tab=drawio"
           class="fm-subtab<?= $tab === 'drawio' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'drawio' ? 'true' : 'false' ?>">
            🗺️ Librerie drawio (mappe)
        </a>
        <a href="/area-docente/templates?tab=tikz"
           class="fm-subtab<?= $tab === 'tikz' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'tikz' ? 'true' : 'false' ?>">
            ✒️ TikZ (modelli)
        </a>
        <a href="/area-docente/templates?tab=scorciatoie"
           class="fm-subtab<?= $tab === 'scorciatoie' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'scorciatoie' ? 'true' : 'false' ?>">
            ⌨️ Scorciatoie LaTeX
        </a>
        <a href="/area-docente/templates?tab=pdf-import"
           class="fm-subtab<?= $tab === 'pdf-import' ? ' fm-subtab--active' : '' ?>"
           role="tab" aria-selected="<?= $tab === 'pdf-import' ? 'true' : 'false' ?>">
            📄 Estrazione PDF
        </a>
    </nav>

<?php if ($tab === 'drawio'): ?>
    <!-- G22.S15.bis Fase 5 — Librerie shape drawio del docente. -->
    <div class="fm-card fm-p-4 fm-d-flex fm-flex-col fm-gap-3" >
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap">
            <span class="fm-text-18">🗺️</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow" >
                Librerie shape drawio personalizzate (es. piani cartesiani, figure geometriche).
                Incluse nel sync su Drive/Locale/GitHub sotto
                <code>{istituto}/modelli/drawio/</code>.
            </span>
            <label class="fm-btn fm-btn--primary fm-btn--sm fm-cursor-pointer">
                📤 Carica nuova libreria (.xml)
                <input id="fm-drawio-upload" type="file" accept=".xml,application/xml,text/xml" class="fm-d-none">
            </label>
            <button id="fm-drawio-refresh" class="fm-btn fm-btn--ghost fm-btn--sm">↻ Aggiorna</button>
        </div>
        <p class="fm-muted fm-text-13 fm-m-0" >
            Per usarle nell'editor drawio: menu <strong>File → Apri Libreria</strong>,
            seleziona il file XML dalla tua cartella locale (sync l'avrà posizionato
            sotto <code>modelli/drawio/</code>).
        </p>
        <div id="fm-drawio-list" class="fm-drawio-list fm-muted">
            Caricamento…
        </div>
    </div>
</main>
<?php elseif ($tab === 'esercizi'): ?>
    <?php include __DIR__ . '/../teacher/templates.php'; ?>
</main>
<?php elseif ($tab === 'risdoc'): ?>
    <!-- G22.S13 — Tab Modelli risdoc: editor dei 3 file texCommon comuni
         a tutti i template risdoc (main.tex, risdoc.sty, intestaLAteX_IIS.tex).
         Cascade default → institute (super-admin) → teacher (per-utente).
         Modal full-screen con CodeMirror; PDF preview wrappa il file singolo. -->
    <div class="fm-card fm-p-4 fm-d-flex fm-flex-col fm-gap-3 fm-items-start" >
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap fm-w-full">
            <span class="fm-text-18">📝</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow" id="fm-trd-status" >Caricamento…</span>
            <button type="button" class="fm-btn fm-btn--primary" id="fm-trd-open"
                    title="Apri editor full-screen: tree 3 file texCommon + CodeMirror">
                ✏️ Apri editor modelli risdoc
            </button>
        </div>
        <p class="fm-muted fm-text-13 fm-m-0" >
            File texCommon condivisi da tutti i template risdoc:
            <strong>main.tex</strong> (root LaTeX), <strong>risdoc.sty</strong> (preambolo + colori),
            <strong>intestaLAteX_IIS.tex</strong> (header istituzionale).
            Salva la tua personalizzazione (🟢) sopra il modello istituto (🏫) o comune (·).
            <?= !empty($user['is_super_admin']) ? ' <span class="fm-text-purple fm-fw-600">[ADMIN: salvataggi vanno a scope istituto]</span>' : '' ?>
        </p>
    </div>
</main>
<?php elseif ($tab === 'tikz'): ?>
    <!-- G22.S15.bis Fase 5+ — Tab TikZ: gestione workspace blocchi TikZ del
         docente (rispecchia fm-tex-dropdown menu della toolbar). Endpoints
         backend riutilizzati da TeacherWorkspaceController:
           GET /tikz/workspace          → lista gruppi+elementi
           GET /tikz/admin-library      → defaults admin (per import)
           POST /tikz/workspace/element/save|delete
           POST /tikz/workspace/group/rename|delete
           POST /tikz/workspace/reset-all
           POST /tikz/workspace/import  → importa singolo elemento da admin
         I modal openTexElementEditor / openTikzBlocksManager sono caricati
         lazy al primo click (vedi /build/manifest.json + dynamic import). -->
    <div class="fm-card fm-p-4 fm-d-flex fm-flex-col fm-gap-3" >
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap">
            <span class="fm-text-18">✒️</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow" >
                I tuoi modelli TikZ personali. Puoi creare nuovi elementi, importare dalla
                libreria admin, modificarli con CodeMirror o resettare tutto al default.
                Gli stessi modelli compaiono nel dropdown <strong>TeX ▾</strong> dell'editor esercizi.
            </span>
        </div>
        <div class="fm-d-flex fm-gap-2 fm-flex-wrap">
            <button type="button" class="fm-btn fm-btn--primary fm-btn--sm" id="fm-tikz-new">
                ➕ Nuovo / Importa
            </button>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-tikz-manage">
                ⚙️ Gestione avanzata (modal)
            </button>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-ml-auto" id="fm-tikz-refresh"
                    >
                ↻ Aggiorna
            </button>
            <button type="button" class="fm-btn fm-btn--danger fm-btn--sm" id="fm-tikz-reset"
                    title="Sostituisci TUTTO il tuo workspace con i defaults admin (perdita modifiche)">
                🔄 Reset workspace
            </button>
        </div>
        <div id="fm-tikz-groups" class="fm-tikz-groups">
            <p class="fm-muted fm-text-center fm-p-5" >Caricamento workspace…</p>
        </div>
    </div>
</main>
<?php elseif ($tab === 'scorciatoie'): ?>
    <!-- Phase 25 — Scorciatoie LaTeX: riferimento super-admin forkabile. -->
    <div class="fm-card fm-p-4 fm-d-flex fm-flex-col fm-gap-3">
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap">
            <span class="fm-text-18">⌨️</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow">
                Le scorciatoie LaTeX (digitazioni rapide e combinazioni di tasti) si applicano
                in <strong>ogni campo di scrittura</strong> del sito. Parti dal riferimento
                istituzionale e personalizza ciò che vuoi: le modifiche restano <strong>tue</strong>.
                Usa <code>${SEL}</code> per il testo selezionato e <code>${CUR}</code> per la
                posizione finale del cursore.
            </span>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-sc-resetall">↺ Ripristina tutto</button>
        </div>
        <div id="fm-sc-editor" data-admin="0">
            <p class="fm-muted fm-text-center fm-p-5">Caricamento scorciatoie…</p>
        </div>
    </div>
</main>
<?php elseif ($tab === 'pdf-import'): ?>
    <!-- Estrazione PDF — impostazioni PERSONALI del docente (modelli/prompt override
         sopra il preset condiviso, cache). La chiave API si imposta dal popup
         nella pagina di import. Riusa la pagina /models via iframe (?scope=personal). -->
    <div class="fm-card fm-p-3 fm-d-flex fm-flex-col fm-gap-2">
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap">
            <span class="fm-text-18">📄</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow">
                Le TUE impostazioni per l'estrazione esercizi da PDF (modelli per operazione,
                prompt, cache). Partono dal preset condiviso dell'istituto: modifica solo ciò che vuoi.
                Per estrarre un PDF vai su <a href="/area-docente/pdf-import" class="fm-link">Estrai esercizi da PDF</a>.
            </span>
        </div>
        <iframe src="/area-docente/pdf-import/models?scope=personal"
                title="Estrazione PDF — impostazioni personali"
                style="width:100%;height:1500px;border:0;display:block;border-radius:8px"></iframe>
    </div>
</main>
<?php else: /* tab === verifiche */ ?>
    <!-- G22.S10c — modal-based UI: l'editor full-screen (CodeMirror + tree
         + PDF) sostituisce la vecchia layout fm-vfiles-layout-area-docente.
         La pagina è solo un launcher: open-button + auto-open al load. -->
    <div class="fm-card fm-p-4 fm-d-flex fm-flex-col fm-gap-3 fm-items-start" >
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap fm-w-full">
            <span class="fm-text-18">🏫</span>
            <span class="fm-muted fm-text-13 fm-flex-1-grow" id="fm-tvf-status" >Caricamento…</span>
            <button type="button" class="fm-btn fm-btn--primary" id="fm-tvf-open"
                    title="Apri l'editor full-screen: tree file + CodeMirror + anteprima PDF">
                ✏️ Apri editor modelli
            </button>
        </div>
        <p class="fm-muted fm-text-13 fm-m-0" >
            Editor full-screen: scegli un file dal tree (📁 Elementi comuni / Modelli verifica /
            Griglie), modifica con syntax highlighting, anteprima PDF inline.
            Salva la tua personalizzazione (🟢) sopra il modello istituto (🏫) o comune (·).
        </p>
    </div>
</main>
<?php endif; ?>

<?php if ($tab === 'tikz'): ?>
<style>
    /* TikZ workspace tab — light/dark aware. */
    .fm-tikz-groups { display: flex; flex-direction: column; gap: 10px; }
    .fm-tikz-group { border: 1px solid #e5e7eb; border-radius: 6px; background: #fafafa; }
    .fm-tikz-group__hdr { display: flex; align-items: center; gap: 6px; padding: 8px 12px; background: #f1f5f9; border-bottom: 1px solid #e5e7eb; cursor: pointer; user-select: none; }
    .fm-tikz-group__title { flex: 1; font-weight: 600; font-size: 0.875rem; color: #1e293b; }
    .fm-tikz-group__count { font-size: 0.75rem; color: #64748b; font-weight: normal; }
    .fm-tikz-group__items { padding: 8px 12px; display: none; }
    .fm-tikz-group--open .fm-tikz-group__items { display: block; }
    .fm-tikz-item { display: flex; align-items: center; gap: 6px; padding: 6px 8px; border-bottom: 1px solid rgba(0,0,0,0.04); }
    .fm-tikz-item:last-child { border-bottom: none; }
    .fm-tikz-item__label { flex: 1; font-size: 0.8125rem; color: #1f2937; }
    .fm-tikz-item__type { font-size: 0.625rem; padding: 2px 6px; border-radius: 3px; background: #e0e7ff; color: #3730a3; font-weight: 600; }
    .fm-tikz-item__btn { padding: 3px 7px; background: #fff; border: 1px solid #cbd5e1; border-radius: 3px; cursor: pointer; font-size: 0.75rem; color: #475569; }
    .fm-tikz-item__btn:hover { background: #f1f5f9; }
    .fm-tikz-empty { padding: 24px; text-align: center; color: #94a3b8; font-style: italic; }
    /* Dialog "Nuovo / Importa" */
    .fm-tikz-newdlg { position: fixed; inset: 0; background: rgba(0,0,0,0.55); z-index: 10040; display: flex; align-items: center; justify-content: center; font: 13px/1.4 system-ui; }
    .fm-tikz-newdlg__panel { background: #fff; color: #1f2937; border-radius: 8px; box-shadow: 0 12px 48px rgba(0,0,0,0.3); width: 760px; max-width: 96vw; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; }
    .fm-tikz-newdlg__hdr { padding: 12px 16px; background: #f1f5f9; border-bottom: 1px solid #e5e7eb; display: flex; align-items: center; }
    .fm-tikz-newdlg__x { padding: 4px 10px; background: #fff; border: 1px solid #cbd5e1; border-radius: 4px; cursor: pointer; }
    .fm-tikz-newdlg__tabs { display: flex; border-bottom: 1px solid #e5e7eb; }
    .fm-tikz-newdlg__tab { flex: 1; padding: 10px 14px; background: transparent; border: none; border-bottom: 2px solid transparent; cursor: pointer; font-size: 0.8125rem; color: #475569; }
    .fm-tikz-newdlg__tab--active { color: #1e293b; border-bottom-color: #0b5fd1; font-weight: 600; }
    .fm-tikz-newdlg__body { flex: 1; padding: 16px; overflow-y: auto; display: flex; flex-direction: column; min-height: 280px; }
    .fm-tikz-newdlg__filters { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
    .fm-tikz-newdlg__filters label { display: flex; align-items: center; gap: 4px; font-size: 0.75rem; color: #475569; cursor: pointer; }
    .fm-tikz-newdlg__search { flex: 1; min-width: 200px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8125rem; }
    .fm-tikz-newdlg__list { flex: 1; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 4px; min-height: 200px; }
    .fm-tikz-newdlg__group { border-bottom: 1px solid #f1f5f9; }
    .fm-tikz-newdlg__ghdr { padding: 6px 12px; background: #f8fafc; font-weight: 600; font-size: 0.8125rem; color: #1e293b; }
    .fm-tikz-newdlg__row { display: flex; align-items: center; gap: 8px; padding: 6px 12px 6px 24px; border-bottom: 1px solid rgba(0,0,0,0.04); cursor: pointer; }
    .fm-tikz-newdlg__row:last-child { border-bottom: none; }
    .fm-tikz-newdlg__row:hover { background: #f8fafc; }
    .fm-tikz-newdlg__type { font-size: 0.5625rem; padding: 2px 5px; }
    .fm-tikz-newdlg__type--tikz   { background: #dcfce7; color: #166534; }
    .fm-tikz-newdlg__type--schema { background: #fef3c7; color: #92400e; }
    .fm-tikz-newdlg__type--latex  { background: #e0e7ff; color: #3730a3; }
    .fm-tikz-newdlg__footer { display: flex; gap: 8px; align-items: center; margin-top: 12px; padding-top: 10px; border-top: 1px solid #e5e7eb; }
    /* Dark theme */
    body.fm-dark .fm-tikz-newdlg__panel { background: #1e293b; color: #cbd5e1; }
    body.fm-dark .fm-tikz-newdlg__hdr   { background: #0f172a; border-color: #334155; }
    body.fm-dark .fm-tikz-newdlg__x     { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    body.fm-dark .fm-tikz-newdlg__tabs  { border-color: #334155; }
    body.fm-dark .fm-tikz-newdlg__tab   { color: #94a3b8; }
    body.fm-dark .fm-tikz-newdlg__tab--active { color: #f1f5f9; border-bottom-color: #60a5fa; }
    body.fm-dark .fm-tikz-newdlg__filters label { color: #cbd5e1; }
    body.fm-dark .fm-tikz-newdlg__search { background: #0f172a; border-color: #334155; color: #e2e8f0; }
    body.fm-dark .fm-tikz-newdlg__list  { background: #0f172a; border-color: #334155; }
    body.fm-dark .fm-tikz-newdlg__group { border-color: #334155; }
    body.fm-dark .fm-tikz-newdlg__ghdr  { background: #1e293b; color: #f1f5f9; }
    body.fm-dark .fm-tikz-newdlg__row   { border-color: rgba(255,255,255,0.06); }
    body.fm-dark .fm-tikz-newdlg__row:hover { background: rgba(255,255,255,0.04); }
    body.fm-dark .fm-tikz-newdlg__type--tikz   { background: #064e3b; color: #6ee7b7; }
    body.fm-dark .fm-tikz-newdlg__type--schema { background: #78350f; color: #fcd34d; }
    body.fm-dark .fm-tikz-newdlg__type--latex  { background: #1e1b4b; color: #a5b4fc; }
    body.fm-dark .fm-tikz-newdlg__footer { border-color: #334155; }
    /* Dark theme */
    body.fm-dark .fm-tikz-group { background: #0f172a; border-color: #334155; }
    body.fm-dark .fm-tikz-group__hdr { background: #1e293b; border-color: #334155; }
    body.fm-dark .fm-tikz-group__title { color: #f1f5f9; }
    body.fm-dark .fm-tikz-group__count { color: #94a3b8; }
    body.fm-dark .fm-tikz-item { border-color: rgba(255,255,255,0.06); }
    body.fm-dark .fm-tikz-item__label { color: #cbd5e1; }
    body.fm-dark .fm-tikz-item__type { background: #1e1b4b; color: #a5b4fc; }
    body.fm-dark .fm-tikz-item__btn { background: #1e293b; border-color: #334155; color: #cbd5e1; }
    body.fm-dark .fm-tikz-item__btn:hover { background: #243047; }
</style>

<?php endif; ?>

<?php // Il tab attivo lo legge js/entries/area-docente-templates.js: ogni blocco
     // gira solo nel suo tab, come quando era uno <script> inline nel ramo
     // `if ($tab === '…')` (P8, 2026-09-04). ?>
<div id="fm-templates-page" hidden data-tab="<?= htmlspecialchars((string)$tab, ENT_QUOTES) ?>"></div>
<?= \App\Support\ViteManifest::script('js/entries/area-docente-templates.js') ?>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
