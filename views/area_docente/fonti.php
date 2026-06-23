<?php
/** G22.S15.bis — Manager fonti/citazioni del docente.
 *  Backend canonico: GET/PUT /api/teacher/sources.registry.json
 *  (formato `{sources: [{key, book, volume, authors}, ...]}`).
 *  L'editor inline (`<select.origin>` + popover) usa /api/teacher/sources.json
 *  che è ora una vista runtime sul medesimo registry (deprecato come file). */
$pageTitle    = 'PANTEDU — Fonti / citazioni';
$pageContent  = ob_get_clean();
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

<script type="module">
    import { notify } from "/js/modules/ui/sync-panel.js";
    import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";

    let registry = { sources: [] };

    async function loadRegistry() {
        const tbody = document.querySelector("#fm-fonti-table tbody");
        tbody.innerHTML = '<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-muted">Caricamento…</td></tr>';
        try {
            const j = await fetchJson("/api/teacher/sources.registry.json", { cache: "no-store" });
            registry = j;
            const list = Array.isArray(j.sources) ? j.sources : [];
            document.getElementById("fm-fonti-status").textContent = `${list.length} fonti registrate`;
            renderTable(list);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-danger">Errore: ${e.message}</td></tr>`;
        }
    }

    function renderTable(list) {
        const tbody = document.querySelector("#fm-fonti-table tbody");
        tbody.innerHTML = "";
        if (!list.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="fm-text-center fm-p-4 fm-text-muted fm-fst-italic">Nessuna fonte registrata. Clicca "Aggiungi fonte".</td></tr>';
            return;
        }
        list.forEach((s, idx) => {
            const tr = document.createElement("tr");
            tr.innerHTML = `
                <td><code class="fm-mono-muted fm-text-11" >${escapeHtml(s.key || "")}</code></td>
                <td>${escapeHtml(s.book || "")}</td>
                <td>${escapeHtml(s.volume || "")}</td>
                <td>${escapeHtml(s.authors || "")}</td>
                <td>
                    <div style="display:flex;gap:6px;flex-wrap:nowrap;align-items:center">
                        <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-act="edit" data-idx="${idx}" aria-label="Modifica">✏️</button>
                        <button type="button" class="fm-btn fm-btn--danger fm-btn--sm" data-act="del" data-idx="${idx}" aria-label="Elimina">🗑️</button>
                    </div>
                </td>
            `;
            tbody.appendChild(tr);
        });
        tbody.querySelectorAll('button[data-act]').forEach(b => b.addEventListener("click", onAction));
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c]));
    }

    async function onAction(e) {
        const idx = parseInt(e.target.dataset.idx, 10);
        const act = e.target.dataset.act;
        const list = Array.isArray(registry.sources) ? registry.sources : [];
        const item = list[idx];
        if (act === "edit") openDialog(item, idx);
        else if (act === "del") {
            if (!(await window.FM.Dialog.confirm(`Eliminare la fonte "${item.book || item.key}"?`))) return;
            list.splice(idx, 1);
            await saveRegistry();
        }
    }

    async function saveRegistry() {
        // G22.S15.bis — il registry è la sola source-of-truth.
        // PUT /api/teacher/sources.registry.json scrive nativamente; non
        // serve più fallback verso /sources.json (deprecato).
        const csrf = await fetchCsrf();
        try {
            const j = await fetchJson("/api/teacher/sources.registry.json", {
                method: "PUT",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify(registry),
            });
            if (!j.ok) throw new Error(j.error || "salvataggio non riuscito");
            notify("Fonti", "ok", "Salvato", 2500);
            await loadRegistry();
        } catch (e) {
            notify("Fonti", "error", `Salvataggio fallito: ${e.message}`, 5000);
        }
    }

    function openDialog(existing, editIdx) {
        document.getElementById("fm-fonti-dialog")?.remove();
        const isEdit = editIdx !== undefined;
        const dlg = document.createElement("div");
        dlg.id = "fm-fonti-dialog";
        dlg.className = "fm-fd-overlay";
        const slug = (s) => String(s).toLowerCase().normalize("NFKD").replace(/[̀-ͯ]/g, "").replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
        dlg.innerHTML = `
            <div class="fm-fd-card">
                <div class="fm-fd-header">📚 ${isEdit ? 'Modifica' : 'Aggiungi'} fonte</div>
                <div class="fm-fd-body">
                    <label class="fm-fd-label">
                        <span>Libro (titolo)</span>
                        <input data-role="book" type="text" class="fm-fd-input" value="${escapeHtml(existing?.book || '')}">
                    </label>
                    <label class="fm-fd-label">
                        <span>Volume / edizione (es. "Vol.2 Ed.3 - ZANICHELLI")</span>
                        <input data-role="volume" type="text" class="fm-fd-input" value="${escapeHtml(existing?.volume || '')}">
                    </label>
                    <label class="fm-fd-label">
                        <span>Autori</span>
                        <input data-role="authors" type="text" class="fm-fd-input" value="${escapeHtml(existing?.authors || '')}">
                    </label>
                    <label class="fm-fd-label">
                        <span>Key (auto-generata da libro+volume — non cambiare se già usata)</span>
                        <input data-role="key" type="text" class="fm-fd-input fm-fd-input--mono" value="${escapeHtml(existing?.key || '')}">
                    </label>
                </div>
                <div class="fm-fd-footer">
                    <button data-act="cancel" class="fm-btn fm-btn--ghost fm-btn--sm">Annulla</button>
                    <button data-act="ok" class="fm-btn fm-btn--primary fm-btn--sm">${isEdit ? 'Salva' : 'Aggiungi'}</button>
                </div>
            </div>`;
        document.body.appendChild(dlg);
        const $ = (sel) => dlg.querySelector(sel);
        const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
        const esc = (e) => { if (e.key === "Escape") close(); };
        document.addEventListener("keydown", esc);
        // Auto-key se vuota
        const refreshKey = () => {
            const k = $('[data-role="key"]');
            if (k.value.trim()) return;
            const book = $('[data-role="book"]').value;
            const vol = $('[data-role="volume"]').value;
            k.value = slug(book + ' ' + vol);
        };
        ['book','volume'].forEach(r => $(`[data-role="${r}"]`).addEventListener('blur', refreshKey));
        dlg.addEventListener("click", (e) => {
            const act = e.target?.dataset?.act;
            if (act === "cancel" || e.target === dlg) close();
            if (act === "ok") {
                const item = {
                    key: $('[data-role="key"]').value.trim(),
                    book: $('[data-role="book"]').value.trim(),
                    volume: $('[data-role="volume"]').value.trim(),
                    authors: $('[data-role="authors"]').value.trim(),
                };
                if (!item.book || !item.key) {
                    notify("Fonti", "warn", "Libro e key obbligatori", 4000);
                    return;
                }
                if (!Array.isArray(registry.sources)) registry.sources = [];
                if (isEdit) registry.sources[editIdx] = item;
                else registry.sources.push(item);
                close();
                saveRegistry();
            }
        });
        setTimeout(() => $('[data-role="book"]').focus(), 50);
    }

    document.getElementById("fm-fonti-add").addEventListener("click", () => openDialog(null));
    document.getElementById("fm-fonti-refresh").addEventListener("click", loadRegistry);
    loadRegistry();
</script>

<script type="module">
    // G27.badge.style — UI sezione "Stile badge" (preset+overrides).
    import { notify } from "/js/modules/ui/sync-panel.js";
    import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";

    let bsState = {
        preset: "_default",
        overrides: { fonte: {}, badge: {} },
        presets: [],
        defaults: null,
        sizes: [],
    };

    async function bsLoad() {
        try {
            const j = await fetchJson("/api/teacher/badge-style", { cache: "no-store" });
            if (j.error) throw new Error(j.error);
            bsState = {
                preset:    j.preset    || "_default",
                overrides: j.overrides || { fonte: {}, badge: {} },
                presets:   Array.isArray(j.presets) ? j.presets : [],
                defaults:  j.defaults  || null,
                sizes:     Array.isArray(j.sizes) ? j.sizes : [],
                resolved:  j.resolved  || null,
            };
            bsRender();
            bsRenderPreview();
        } catch (e) {
            document.getElementById("fm-bs-status").textContent = "Errore caricamento: " + e.message;
        }
    }

    function bsPopulateSizeSelect(sel) {
        sel.innerHTML = '<option value="">(eredita dal preset)</option>'
            + bsState.sizes.map(s => `<option value="${s}">${s}</option>`).join("");
    }

    function bsRender() {
        // Preset dropdown
        const pSel = document.getElementById("fm-bs-preset");
        pSel.innerHTML = bsState.presets.map(p => `<option value="${p}">${p}</option>`).join("");
        pSel.value = bsState.preset;

        // Override fields: popola size selects + valori correnti
        document.querySelectorAll(".fm-bs-override").forEach(el => {
            const section = el.dataset.section;
            const field   = el.dataset.field;
            // size selects
            const sizeFields = ["title_size", "meta_size", "ex_size", "diff_size"];
            if (el.tagName === "SELECT" && sizeFields.includes(field)) {
                bsPopulateSizeSelect(el);
            }
            const cur = (bsState.overrides[section] || {})[field];
            if (cur !== undefined && cur !== null) {
                el.value = String(cur);
            } else {
                el.value = "";
            }
        });
    }

    function bsCollectOverrides() {
        const ov = { fonte: {}, badge: {} };
        document.querySelectorAll(".fm-bs-override").forEach(el => {
            const v = el.value.trim();
            if (v === "") return; // empty = inherit
            const section = el.dataset.section;
            const field   = el.dataset.field;
            if (field === "diff_max") {
                const n = parseInt(v, 10);
                if (!Number.isNaN(n)) ov[section][field] = n;
            } else {
                ov[section][field] = v;
            }
        });
        if (!Object.keys(ov.fonte).length) delete ov.fonte;
        if (!Object.keys(ov.badge).length) delete ov.badge;
        return ov;
    }

    async function bsSave() {
        const preset = document.getElementById("fm-bs-preset").value || "_default";
        const overrides = bsCollectOverrides();
        const csrf = await fetchCsrf();
        document.getElementById("fm-bs-status").textContent = "Salvataggio…";
        try {
            const j = await fetchJson("/api/teacher/badge-style", {
                method: "PUT",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify({ preset, overrides }),
            });
            if (!j.ok) throw new Error(j.error || j.message || "salvataggio non riuscito");
            bsState.preset    = j.preset;
            bsState.overrides = j.overrides;
            bsState.resolved  = j.resolved;
            document.getElementById("fm-bs-status").textContent = "Salvato.";
            notify("Stile badge", "ok", "Preferenze salvate", 2500);
            bsRenderPreview();
        } catch (e) {
            document.getElementById("fm-bs-status").textContent = "Errore: " + e.message;
            notify("Stile badge", "error", "Salvataggio fallito: " + e.message, 5000);
        }
    }

    function bsReset() {
        document.querySelectorAll(".fm-bs-override").forEach(el => { el.value = ""; });
        document.getElementById("fm-bs-status").textContent = "Override resettati (clicca Salva per persistere).";
    }

    function bsRenderPreview() {
        // Mock client-side della stringa preamble (NON la verita': il server fa il merge).
        // Se l'utente cambia il preset nel dropdown senza salvare, l'anteprima usa
        // bsState.resolved come baseline + override correnti dal form.
        const r = bsState.resolved || {};
        const f = (r.fonte || {});
        const b = (r.badge || {});
        const ov = bsCollectOverrides();
        const merged = {
            fonte: { ...f, ...(ov.fonte || {}) },
            badge: { ...b, ...(ov.badge || {}) },
        };
        const fontePart = `\\fmsetfonte{titlesize=${merged.fonte.title_size||"\\small"},metasize=${merged.fonte.meta_size||"\\tiny"},rowsep=${merged.fonte.row_sep||"-5pt"},colspec=${merged.fonte.col_spec||"|c|"}}`;
        const badgePart = `\\fmsetbadge{bg=${merged.badge.bg||"gray"},txt=${merged.badge.txt||"white"},exsize=${merged.badge.ex_size||"\\large"},minw=${merged.badge.min_width||"1cm"},diffmax=${merged.badge.diff_max||4},diffsize=${merged.badge.diff_size||"\\huge"}}`;
        document.getElementById("fm-bs-preview").textContent =
            "% Anteprima (il server applicherà il merge definitivo al prossimo salvataggio verifica)\n"
            + fontePart + "\n" + badgePart;
    }

    document.getElementById("fm-bs-save").addEventListener("click", bsSave);
    document.getElementById("fm-bs-reset").addEventListener("click", bsReset);
    document.getElementById("fm-bs-preset").addEventListener("change", bsRenderPreview);
    document.querySelectorAll(".fm-bs-override").forEach(el => el.addEventListener("input", bsRenderPreview));
    bsLoad();
</script>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
