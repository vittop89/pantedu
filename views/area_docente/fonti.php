<?php
/** G22.S15.bis — Manager fonti/citazioni del docente.
 *  Backend canonico: GET/PUT /api/teacher/sources.registry.json
 *  (formato `{sources: [{key, book, volume, authors}, ...]}`).
 *  L'editor inline (`<select.origin>` + popover) usa /api/teacher/sources.json
 *  che è ora una vista runtime sul medesimo registry (deprecato come file). */
$pageTitle    = 'PANTEDU — Fonti / citazioni';
$bodyClass    = 'fm-area-docente-fonti';
$currentRoute = '/area-docente/fonti';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>📚 Fonti / citazioni <button type="button" class="fm-infotip" aria-label="Info fonti"><span class="fm-infotip__body" hidden>Le fonti dei tuoi esercizi (libro, edizione, volume, autori) appaiono come citazione nel badge. Modificale qui — vengono salvate in <code>institutes/{istituto}/private/{te}/sources.registry.json</code>.</span></button></h1>
    </header>

    <section class="fm-card">
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap fm-mb-3">
            <span class="fm-text-sm fm-text-muted" id="fm-fonti-status">Caricamento…</span>
            <button type="button" class="fm-btn fm-btn--primary fm-btn--sm" id="fm-fonti-add">➕ Aggiungi fonte</button>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm fm-ml-auto" id="fm-fonti-refresh" >↻ Aggiorna</button>
        </div>
        <table class="fm-data-table" id="fm-fonti-table">
            <thead>
                <tr>
                    <th scope="col">Key</th>
                    <th scope="col">Libro</th>
                    <th scope="col">Volume / Edizione</th>
                    <th scope="col">Autori</th>
                    <th scope="col" class="fm-w-25">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="5" class="fm-text-center fm-p-5 fm-text-muted fm-fst-italic">Caricamento…</td></tr>
            </tbody>
        </table>
        <p class="fm-muted fm-text-xs fm-mt-3" >
            La <code>key</code> identifica univocamente la fonte (es. <code>matematica_multimediale_blu_vol_2_ed_3_zanichelli</code>).
            Una volta usata negli esercizi, modificare la key richiede di ri-assegnarla a tutti gli items.
        </p>
    </section>

    <?php /* ADR-036 — il catalogo delle adozioni dell'istituto: i libri che le
             classi del docente hanno adottato, secondo il dataset MIUR caricato
             dall'amministratore. Un clic li porta fra le fonti, che poi si
             personalizzano come le altre. Logica in
             js/entries/area-docente-fonti.js, dati da /api/teacher/adozioni. */ ?>
    <section class="fm-card fm-mt-4" id="fm-adozioni">
        <h2 class="fm-m-0 fm-mb-2 fm-text-18">📖 Dal catalogo delle adozioni <button type="button" class="fm-infotip" aria-label="Info catalogo delle adozioni"><span class="fm-infotip__body" hidden>I libri in adozione della tua scuola, per le classi e le materie che hai spuntato nel tuo profilo. Vengono dal dataset MIUR delle adozioni caricato dall'amministratore. «Aggiungi» ne fa una tua fonte, con titolo, volume, editore e autori già scritti: poi la modifichi come tutte le altre.</span></button></h2>
        <div class="fm-d-flex fm-items-center fm-gap-3 fm-flex-wrap fm-mb-3">
            <label class="fm-text-sm">Classe
                <select id="fm-adozioni-classe" class="fm-input" aria-label="Classe">
                    <option value="">le mie classi</option>
                </select>
            </label>
            <label class="fm-text-sm">Materia
                <select id="fm-adozioni-materia" class="fm-input" aria-label="Materia">
                    <option value="">le mie materie</option>
                </select>
            </label>
            <label class="fm-text-sm fm-d-flex fm-items-center fm-gap-1">
                <input type="checkbox" id="fm-adozioni-tutte"> tutto l'istituto
            </label>
            <span class="fm-text-sm fm-text-muted" id="fm-adozioni-status" aria-live="polite">Caricamento…</span>
        </div>
        <table class="fm-data-table" id="fm-adozioni-table">
            <thead>
                <tr>
                    <th scope="col">Classe</th>
                    <th scope="col">Disciplina</th>
                    <th scope="col">Libro</th>
                    <th scope="col">Volume / editore</th>
                    <th scope="col">Autori</th>
                    <th scope="col" class="fm-w-25">Azioni</th>
                </tr>
            </thead>
            <tbody>
                <tr><td colspan="6" class="fm-text-center fm-p-4 fm-text-muted fm-fst-italic">Caricamento…</td></tr>
            </tbody>
        </table>
    </section>

    <!-- ─── G27.badge.style — Stile badge teacher (preset+overrides) ────── -->
    <style>
      /* G27.badge.style — base (light + structure). Layout tabellare:
         label-sinistra + input-destra in griglia 2-colonne, righe compatte. */
      #fm-badge-style-section h2 { color:#1f2937; margin:0 0 6px; font-size:1.125rem; }
      #fm-badge-style-section .fm-bs-intro { color:#334155; font-size:0.8125rem; margin-bottom:14px; }
      #fm-badge-style-section .fm-bs-grid {
        display:grid; grid-template-columns:1fr 1fr; gap:14px;
      }
      #fm-badge-style-section .fm-bs-fieldset {
        border:1px solid #e5e7eb; border-radius:6px;
        padding:8px 12px 10px;
      }
      #fm-badge-style-section .fm-bs-legend {
        font-size:0.75rem; font-weight:700; padding:0 6px; color:#1e293b;
      }
      /* Riga tabellare: label inline a sinistra, input a destra. */
      #fm-badge-style-section .fm-bs-label {
        display:grid;
        grid-template-columns:minmax(0,11em) minmax(0,1fr);
        align-items:center;
        column-gap:8px;
        font-size:0.75rem; font-weight:600; color:#334155;
        margin:0;
        padding:3px 0;
        min-height:30px;
      }
      #fm-badge-style-section .fm-bs-label + .fm-bs-label { border-top:1px dashed transparent; }
      #fm-badge-style-section .fm-bs-label--strong { font-size:0.8125rem; padding:4px 0 8px; }
      #fm-badge-style-section .fm-bs-label--last { padding-bottom:0; }
      #fm-badge-style-section .fm-bs-label small {
        font-weight:400; font-size:0.6875rem; opacity:0.7; margin-left:2px;
      }
      #fm-badge-style-section .fm-bs-input {
        padding:4px 8px; border:1px solid #cbd5e1; border-radius:3px;
        font-size:0.75rem; line-height:1.4; background:#fff; color:#1f2937;
        width:100%; min-width:0; box-sizing:border-box;
      }
      #fm-badge-style-section .fm-bs-input::placeholder { color:#94a3b8; }
      #fm-badge-style-section .fm-bs-actions {
        display:flex; align-items:center; gap:10px;
        margin-top:14px; padding-top:10px; border-top:1px solid #e5e7eb;
      }
      #fm-badge-style-section .fm-bs-status { font-size:0.75rem; color:#64748b; }
      #fm-badge-style-section .fm-bs-summary {
        cursor:pointer; font-size:0.75rem; color:#475569;
      }
      /* Su viewport stretti il grid 2-col diventa 1-col stack. */
      @media (max-width:780px) {
        #fm-badge-style-section .fm-bs-grid { grid-template-columns:1fr; }
        #fm-badge-style-section .fm-bs-label {
          grid-template-columns:minmax(0,9em) minmax(0,1fr);
        }
      }

      /* G27.badge.style — dark mode override (body.fm-dark dal tema globale).
         Contrast WCAG AA su bg card scura (#2a2a3a). */
      body.fm-dark #fm-badge-style-section h2          { color:#f1f5f9; }
      body.fm-dark #fm-badge-style-section .fm-bs-intro { color:#e2e8f0; }
      body.fm-dark #fm-badge-style-section .fm-bs-fieldset {
        border-color:#475569; background:rgba(255,255,255,0.04);
      }
      body.fm-dark #fm-badge-style-section .fm-bs-legend { color:#f8fafc; }
      body.fm-dark #fm-badge-style-section .fm-bs-label  { color:#e2e8f0; }
      body.fm-dark #fm-badge-style-section .fm-bs-input  {
        background:#1e293b; color:#f8fafc; border-color:#64748b;
      }
      body.fm-dark #fm-badge-style-section .fm-bs-input::placeholder { color:#94a3b8; font-style:italic; }
      body.fm-dark #fm-badge-style-section .fm-bs-actions { border-top-color:#475569; }
      body.fm-dark #fm-badge-style-section .fm-bs-status  { color:#cbd5e1; }
      body.fm-dark #fm-badge-style-section .fm-bs-summary { color:#cbd5e1; }

      /* G27.fonti — dialog Aggiungi/Modifica fonte (light) */
      .fm-fd-overlay {
        position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10050;
        display:flex; align-items:center; justify-content:center;
        font:13px/1.4 system-ui;
      }
      .fm-fd-card {
        background:#fff; color:#1f2937; border-radius:8px;
        width:520px; max-width:96vw; overflow:hidden;
        box-shadow:0 12px 48px rgba(0,0,0,0.3);
      }
      .fm-fd-header {
        padding:12px 16px; background:#f1f5f9; border-bottom:1px solid #e5e7eb;
        font-weight:600; color:#0f172a;
      }
      .fm-fd-body {
        padding:16px; display:flex; flex-direction:column; gap:10px;
      }
      .fm-fd-label {
        display:flex; flex-direction:column; gap:4px;
        font-size:0.75rem; font-weight:600; color:#334155;
      }
      .fm-fd-input {
        padding:6px 10px; border:1px solid #cbd5e1; border-radius:4px;
        background:#fff; color:#1f2937; font-size:0.8125rem;
      }
      .fm-fd-input--mono { font-family:ui-monospace,Consolas,monospace; font-size:0.6875rem; }
      .fm-fd-input::placeholder { color:#94a3b8; }
      .fm-fd-footer {
        padding:10px 12px; background:#f8fafc; border-top:1px solid #e5e7eb;
        display:flex; gap:8px; justify-content:flex-end;
      }

      /* G27.fonti — dialog dark mode */
      body.fm-dark .fm-fd-card    { background:#1e293b; color:#e2e8f0; box-shadow:0 12px 48px rgba(0,0,0,0.6); }
      body.fm-dark .fm-fd-header  { background:#0f172a; border-bottom-color:#334155; color:#f8fafc; }
      body.fm-dark .fm-fd-body    { background:#1e293b; }
      body.fm-dark .fm-fd-label   { color:#e2e8f0; }
      body.fm-dark .fm-fd-input   { background:#0f172a; color:#f8fafc; border-color:#475569; }
      body.fm-dark .fm-fd-input::placeholder { color:#64748b; font-style:italic; }
      body.fm-dark .fm-fd-footer  { background:#0f172a; border-top-color:#334155; }
    </style>
    <section class="fm-card fm-mt-4"  id="fm-badge-style-section">
        <h2 class="fm-m-0 fm-mb-3 fm-text-18">🎨 Stile badge esercizi <button type="button" class="fm-infotip" aria-label="Info stile badge"><span class="fm-infotip__body" hidden>Il badge che appare a fianco di ogni esercizio nelle verifiche SOL (riquadro fonte + numero) usa un <strong>preset di stile</strong> definito dall'amministratore. Puoi scegliere un preset diverso o sovrascrivere singoli campi solo per le tue verifiche. Le modifiche si applicano al prossimo salvataggio di una verifica.</span></button></h2>
        <div class="fm-bs-grid">
            <div>
                <label class="fm-bs-label fm-bs-label--strong">
                    <span>Preset</span>
                    <select id="fm-bs-preset" class="fm-bs-input">
                        <option value="_default">_default (caricamento…)</option>
                    </select>
                </label>

                <fieldset class="fm-bs-fieldset fm-mt-3" >
                    <legend class="fm-bs-legend">Riquadro fonte</legend>
                    <label class="fm-bs-label">
                        <span>Dimensione titolo</span>
                        <select class="fm-bs-override fm-bs-input" data-section="fonte" data-field="title_size"></select>
                    </label>
                    <label class="fm-bs-label">
                        <span>Dimensione volume/autori</span>
                        <select class="fm-bs-override fm-bs-input" data-section="fonte" data-field="meta_size"></select>
                    </label>
                    <label class="fm-bs-label">
                        <span>Spaziatura righe <small>(es. -3pt)</small></span>
                        <input type="text" class="fm-bs-override fm-bs-input" data-section="fonte" data-field="row_sep" placeholder="(eredita dal preset)">
                    </label>
                    <label class="fm-bs-label">
                        <span>Larghezza riquadro</span>
                        <select class="fm-bs-override fm-bs-input" data-section="fonte" data-field="col_spec">
                            <option value="">(eredita dal preset)</option>
                            <option value="|c|">Auto (centrato)</option>
                            <option value="|p{4cm}|">Fissa 4cm</option>
                            <option value="|p{5cm}|">Fissa 5cm</option>
                            <option value="|p{6cm}|">Fissa 6cm</option>
                        </select>
                    </label>
                    <label class="fm-bs-label fm-bs-label--last">
                        <span>Padding sopra/sotto <small>(es. 0pt, 3pt, 5pt)</small></span>
                        <input type="text" class="fm-bs-override fm-bs-input" data-section="fonte" data-field="vpad" placeholder="(eredita dal preset)">
                    </label>
                </fieldset>
            </div>

            <div>
                <fieldset class="fm-bs-fieldset">
                    <legend class="fm-bs-legend">Box numero esercizio</legend>
                    <label class="fm-bs-label">
                        <span>Colore sfondo <small>(xcolor)</small></span>
                        <input type="text" class="fm-bs-override fm-bs-input" data-section="badge" data-field="bg" placeholder="(eredita dal preset)">
                    </label>
                    <label class="fm-bs-label">
                        <span>Colore testo</span>
                        <input type="text" class="fm-bs-override fm-bs-input" data-section="badge" data-field="txt" placeholder="(eredita dal preset)">
                    </label>
                    <label class="fm-bs-label">
                        <span>Dimensione numero</span>
                        <select class="fm-bs-override fm-bs-input" data-section="badge" data-field="ex_size"></select>
                    </label>
                    <label class="fm-bs-label">
                        <span>Larghezza box <small>(1cm, 8mm…)</small></span>
                        <input type="text" class="fm-bs-override fm-bs-input" data-section="badge" data-field="min_width" placeholder="(eredita dal preset)">
                    </label>
                    <label class="fm-bs-label">
                        <span>Totale pallini difficolta'</span>
                        <input type="number" class="fm-bs-override fm-bs-input" data-section="badge" data-field="diff_max" min="1" max="10" placeholder="(eredita dal preset)">
                    </label>
                    <label class="fm-bs-label fm-bs-label--last">
                        <span>Dimensione pallini</span>
                        <select class="fm-bs-override fm-bs-input" data-section="badge" data-field="diff_size"></select>
                    </label>
                </fieldset>
            </div>
        </div>

        <div class="fm-bs-actions">
            <button type="button" class="fm-btn fm-btn--primary fm-btn--sm" id="fm-bs-save">💾 Salva stile</button>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-bs-reset">↺ Resetta override</button>
            <span class="fm-bs-status" id="fm-bs-status"></span>
        </div>

        <details class="fm-mt-3">
            <summary class="fm-bs-summary">📋 Anteprima preambolo LaTeX risolto (preset + override)</summary>
            <pre id="fm-bs-preview" class="fm-codebox-dark">caricamento…</pre>
        </details>
    </section>
</main>

<?= \App\Support\ViteManifest::script('js/entries/area-docente-fonti.js') ?>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
