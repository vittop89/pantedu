<?php
/** Phase G13.5 — Admin Templates (RisDoc + Verifiche).
 *  Phase 25.H — migrato a layout/shell.php + _partials/page_head.php
 *  per uniformazione con dashboard / tools / analytics / WAF.
 */
$page_title    = '📋 Templates';
$page_subtitle = 'Gestisci system defaults per RisDoc (programmazioni, BES/DSA, modelli) e Verifiche (intestazione, griglie, criteri, footer).';
$breadcrumb    = [['label' => 'Templates']];
include __DIR__ . '/_partials/page_head.php';
?>

<nav class="fm-admin-tabs" role="tablist">
    <button type="button" class="fm-admin-tab fm-admin-tab--active" data-tab="risdoc" role="tab" aria-selected="true">📚 RisDoc</button>
    <button type="button" class="fm-admin-tab" data-tab="verifiche" role="tab" aria-selected="false">📝 Verifiche</button>
    <button type="button" class="fm-admin-tab" data-tab="tikz" role="tab" aria-selected="false">📐 TikZ/LaTeX</button>
    <button type="button" class="fm-admin-tab" data-tab="badge-styles" role="tab" aria-selected="false">🎨 Stile badge</button>
    <button type="button" class="fm-admin-tab" data-tab="curriculum" role="tab" aria-selected="false">📊 Dati curriculari</button>
    <button type="button" class="fm-admin-tab" data-tab="shortcuts" role="tab" aria-selected="false">⌨️ Scorciatoie LaTeX</button>
    <button type="button" class="fm-admin-tab" data-tab="pdf-import" role="tab" aria-selected="false">📄 Estrazione PDF</button>
    <button type="button" class="fm-admin-tab" data-tab="json-sources" role="tab" aria-selected="false">📚 Contenuti curricolari (file)</button>
</nav>

<section class="fm-admin-tabpanel fm-admin-tabpanel--active" data-panel="risdoc" role="tabpanel">

    <?php /* Phase 24.57 — UNA SOLA TABELLA: il pannello unico (_risdoc_admin_panel.php,
             admin-risdoc.js) ora fonde l'editor nome/posizione/categoria + rinomina
             partizione con le statistiche e le azioni (Gestisci/Immagini/Schema).
             Il vecchio #fm-tpl-editor è stato rimosso: si fa tutto da qui. */ ?>
    <?php include __DIR__ . '/_risdoc_admin_panel.php'; ?>
</section>

<section class="fm-admin-tabpanel" data-panel="verifiche" role="tabpanel" hidden>
    <!-- G20.0 Phase 9 — File-tree editor con selezione istituto -->
    <div class="fm-vfiles-toolbar fm-d-flex fm-gap-3 fm-items-center fm-mb-2 fm-flex-wrap" >
        <label>
            <strong>Modello per:</strong>
            <select id="fm-vfiles-scope" class="fm-bordered-box">
                <option value="_default">Caricamento…</option>
            </select>
        </label>
        <span id="fm-vfiles-status" class="fm-muted fm-text-13" ></span>
        <button type="button" class="fm-infotip" aria-label="Info modello per istituto"><span class="fm-infotip__body" hidden>Scegli <em>Tutti gli istituti</em> per modificare il modello comune valido ovunque, oppure un istituto specifico per creare una versione personalizzata che vale solo per quello. Le modifiche al singolo istituto sostituiscono quelle del modello comune; tornando al <em>predefinito</em> il file ricomincia a usare il modello comune.</span></button>
    </div>

    <div class="fm-vfiles-layout fm-grid fm-grid--sidebar" >
        <!-- File tree sidebar -->
        <aside id="fm-vfiles-tree" class="fm-vfiles-tree fm-json-preview" >
            <p class="fm-muted">Caricamento…</p>
        </aside>
        <!-- Editor -->
        <main class="fm-vfiles-editor fm-d-flex fm-flex-col fm-gap-2" >
            <header class="fm-d-flex fm-justify-between fm-items-center fm-gap-2">
                <strong id="fm-vfiles-current-path">Scegli un file dall'elenco a sinistra</strong>
                <span id="fm-vfiles-current-status" class="fm-muted fm-text-xs" ></span>
            </header>
            <textarea id="fm-vfiles-textarea"
                      spellcheck="false"
                      placeholder="Scegli un file dall'elenco a sinistra per modificarlo…"
                      class="fm-codeditor fm-flex-1-grow fm-min-h-125"></textarea>
            <div class="fm-vfiles-actions fm-d-flex fm-gap-2 fm-flex-wrap" >
                <button type="button" class="fm-btn fm-btn--primary" id="fm-vfiles-save" disabled>💾 Salva</button>
                <button type="button" class="fm-btn" id="fm-vfiles-copy-from-default" disabled title="Crea una copia del modello predefinito da personalizzare per questo istituto">📋 Parti dal predefinito</button>
                <button type="button" class="fm-btn fm-btn--danger" id="fm-vfiles-delete" disabled title="Cancella la personalizzazione di questo istituto e ripristina il modello comune">🗑 Torna al predefinito</button>
                <span id="fm-vfiles-feedback" class="fm-muted fm-ml-auto fm-text-13" ></span>
            </div>
        </main>
    </div>
</section>

<?php /* Phase 25.D — CSS estratto in /css/admin.css (fm-bsa-panel block). */ ?>
<section class="fm-admin-tabpanel" data-panel="badge-styles" role="tabpanel" hidden>
    <div id="fm-bsa-panel" class="fm-d-flex fm-flex-col fm-gap-3 fm-text-13 fm-font-sans">
        <p>
            Preset di stile per il <strong>badge esercizi</strong> (riquadro fonte + box numero) nelle verifiche SOL.
            I docenti scelgono uno di questi preset come base, eventualmente sovrascrivendo singoli campi.
            I preset risiedono in <code>storage/templates/verifiche/{scope}/badge_styles/{name}.json</code> con cascade scope → <code>_default</code>.
        </p>
        <div class="fm-d-flex fm-gap-3 fm-items-end fm-flex-wrap">
            <label class="fm-d-flex fm-flex-col fm-gap-1 fm-text-xs">
                Scope
                <input type="text" id="fm-bsa-scope" value="_default" class="fm-select-inline fm-w-40">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1 fm-text-xs">
                Preset
                <select id="fm-bsa-preset" class="fm-select-inline fm-min-w-50">
                    <option value="">Caricamento…</option>
                </select>
            </label>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-bsa-refresh">↻ Lista</button>
            <button type="button" class="fm-btn fm-btn--sm" id="fm-bsa-new">➕ Nuovo preset</button>
            <button type="button" class="fm-btn fm-btn--danger fm-btn--sm" id="fm-bsa-delete" disabled>🗑 Elimina</button>
            <span id="fm-bsa-status" class="fm-muted fm-ml-auto fm-text-xs" ></span>
        </div>
        <p class="fm-muted fm-text-11 fm-m-0" >
            Edita il JSON del preset selezionato. Lo schema è validato dal backend (allowlist xcolor / LaTeX size / dimension).
            Valori non validi vengono silenziosamente sostituiti dai default.
        </p>
        <textarea id="fm-bsa-editor" spellcheck="false"
                  placeholder="Seleziona un preset…"
                  class="fm-console-dark"></textarea>
        <div class="fm-d-flex fm-gap-2">
            <button type="button" class="fm-btn fm-btn--primary fm-btn--sm" id="fm-bsa-save" disabled>💾 Salva preset</button>
            <button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" id="fm-bsa-format" disabled>{ } Formatta JSON</button>
        </div>
    </div>
</section>

<section class="fm-admin-tabpanel" data-panel="tikz" role="tabpanel" hidden>
    <div class="fm-tikz-admin fm-d-flex fm-flex-col fm-gap-3 fm-text-13 fm-font-sans" >
        <p class="fm-muted">
            Modelli TikZ/LaTeX <strong>predefiniti globali</strong>. Ogni docente vedrà
            questi nei suoi editor; può salvare un proprio override (✱ MIO) o reimpostare
            al default da qui visualizzato. Modifica/elimina/aggiungi nuovi gruppi qui.
        </p>
        <div class="fm-tikz-admin-toolbar fm-d-flex fm-gap-2 fm-items-center" >
            <button type="button" id="fm-tikz-admin-add" class="fm-btn fm-btn--primary">➕ Nuovo elemento</button>
            <button type="button" id="fm-tikz-admin-refresh" class="fm-btn">🔄 Ricarica</button>
            <span id="fm-tikz-admin-status" class="fm-muted fm-ml-auto fm-text-xs" ></span>
        </div>
        <div id="fm-tikz-admin-groups" class="fm-tex-groups fm-shell-box" >
            <div class="fm-p-3 fm-text-muted fm-fst-italic">Caricamento templates…</div>
        </div>
    </div>
</section>

<!-- ADR-025 (B) — editor dati curriculari (obiettivi/competenze/…) come override
     istituzionali. API: GET/POST /api/risdoc/curriculum-options. -->
<section class="fm-admin-tabpanel" data-panel="curriculum" role="tabpanel" hidden>
    <div class="fm-curr-opt fm-d-flex fm-flex-col fm-gap-3 fm-text-13 fm-font-sans">
        <p class="fm-muted fm-m-0">
            Opzioni curriculari (obiettivi disciplinari, competenze, abilità, conoscenze,
            programmi) per <strong>indirizzo / classe / materia</strong> (codici canonici).
            <em>Globale</em> = default per tutti gli istituti; <em>Mio istituto</em> = override
            che vale solo per il tuo istituto. Se non esiste override, vale il file di default.
        </p>
        <div class="fm-curr-opt-toolbar fm-d-flex fm-gap-2 fm-items-end fm-flex-wrap">
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Dataset</span>
                <select id="fm-co-dataset" class="fm-bordered-box">
                    <option value="obiettivi_disciplinari_LG2010/competenze">Obiettivi LG2010 · competenze</option>
                    <option value="obiettivi_disciplinari_LG2010/abilita">Obiettivi LG2010 · abilità</option>
                    <option value="obiettivi_disciplinari_LG2010/conoscenze">Obiettivi LG2010 · conoscenze</option>
                    <option value="obiettivi_disciplinari_dipartimento/competenze">Obiettivi dipartimento · competenze</option>
                    <option value="obiettivi_disciplinari_dipartimento/abilita">Obiettivi dipartimento · abilità</option>
                    <option value="obiettivi_disciplinari_dipartimento/conoscenze">Obiettivi dipartimento · conoscenze</option>
                    <option value="programmi_svolti">Programmi svolti</option>
                </select>
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Indirizzo</span>
                <input id="fm-co-ind" class="fm-bordered-box" placeholder="SCI" size="6">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Classe</span>
                <input id="fm-co-cls" class="fm-bordered-box" placeholder="2" size="4">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Materia</span>
                <input id="fm-co-mat" class="fm-bordered-box" placeholder="MAT" size="6">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Ambito salvataggio</span>
                <select id="fm-co-scope" class="fm-bordered-box">
                    <option value="0">Globale (tutti gli istituti)</option>
                    <option value="inst">Mio istituto</option>
                </select>
            </label>
            <button type="button" id="fm-co-load" class="fm-btn fm-btn--primary">📥 Carica</button>
            <span id="fm-co-status" class="fm-muted fm-ml-auto fm-text-xs" role="status" aria-live="polite"></span>
        </div>
        <textarea id="fm-co-editor" spellcheck="false"
                  placeholder='Carica per modificare. Formato: array JSON di opzioni, es. [{"value":"v1","label":"Etichetta","group":"Gruppo"}]'
                  class="fm-codeditor fm-min-h-125" aria-label="Editor opzioni JSON"></textarea>
        <div class="fm-curr-opt-actions fm-d-flex fm-gap-2 fm-flex-wrap">
            <button type="button" id="fm-co-save" class="fm-btn fm-btn--primary" disabled>💾 Salva override</button>
            <button type="button" id="fm-co-format" class="fm-btn" disabled>{ } Formatta JSON</button>
            <button type="button" id="fm-co-delete" class="fm-btn fm-btn--danger" disabled title="Elimina l'override dell'istituto selezionato (torna al default)">🗑 Elimina override</button>
        </div>
    </div>
</section>

<!-- Phase 25 — Riferimento istituzionale "Scorciatoie LaTeX da tastiera". -->
<section class="fm-admin-tabpanel" data-panel="shortcuts" role="tabpanel" hidden>
    <div class="fm-d-flex fm-flex-col fm-gap-3 fm-text-13 fm-font-sans">
        <p class="fm-muted fm-m-0">
            Scorciatoie LaTeX <strong>di riferimento</strong> (digitazioni rapide + combinazioni
            di tasti) attive in ogni campo di scrittura. Ogni docente parte da qui e può
            personalizzare le proprie (✱). Usa <code>${SEL}</code> = testo selezionato,
            <code>${CUR}</code> = posizione del cursore. Modifica le righe e premi
            <strong>«Salva riferimento»</strong> per applicare a tutti.
        </p>
        <div class="fm-d-flex fm-gap-2 fm-items-center">
            <button type="button" id="fm-sc-admin-refresh" class="fm-btn fm-btn--ghost fm-btn--sm">🔄 Ricarica</button>
            <span id="fm-sc-admin-status" class="fm-muted fm-ml-auto fm-text-xs"></span>
        </div>
        <div id="fm-sc-admin-editor">
            <p class="fm-muted fm-text-center fm-p-5">Apri il tab per caricare le scorciatoie…</p>
        </div>
    </div>
</section>

<!-- Estrazione PDF — PRESET GLOBALE (default per tutti i docenti): modelli per
     operazione + prompt. Riusa la pagina /models via iframe (?scope=global). Il
     src è lazy (data-src → src al primo click sul tab, vedi JS in fondo). -->
<section class="fm-admin-tabpanel" data-panel="pdf-import" role="tabpanel" hidden>
    <p class="fm-muted fm-m-0 fm-mb-3 fm-text-13">
        Preset condiviso per l'estrazione esercizi da PDF: i modelli LLM per ogni operazione
        e i prompt di sistema qui impostati sono il <strong>default per tutti i docenti</strong>
        (ognuno può poi personalizzarli per sé). Le chiavi API restano personali di ciascuno.
    </p>
    <iframe data-src="/area-docente/pdf-import/models?scope=global"
            title="Estrazione PDF — preset globale" data-fm-pdfimport-frame
            style="width:100%;height:1500px;border:0;display:block;border-radius:8px"></iframe>
</section>

<!-- Editor dei file options-source JSON (catalogo "Da JSON" / SORGENTE OPZIONI
     delle celle/Gruppi checkbox). Modifica i file su disco; ogni salvataggio crea
     un backup .bak. API: GET/POST /api/admin/risdoc/options-source(s). -->
<section class="fm-admin-tabpanel" data-panel="json-sources" role="tabpanel" hidden>
    <div class="fm-osa">
        <p class="fm-muted fm-m-0 fm-text-13">
            <strong>Contenuti curricolari</strong> (competenze, abilità, conoscenze, programmi, competenze
            trasversali…) usati dalla tendina «Da JSON» nei <em>Gruppi di checkbox</em> e nelle <em>celle Checkbox</em>.
            Filtra per dataset / indirizzo / classe / materia, modifica e salva: a ogni salvataggio viene creato
            un backup <code>.bak</code>. Per gli override <em>per-istituto</em> (a DB) usa il tab «Dati curriculari».
        </p>

        <!-- FILTRO stile "Dati curriculari": dataset + indirizzo/classe/materia + cerca -->
        <div class="fm-osa__filter fm-d-flex fm-gap-2 fm-items-end fm-flex-wrap">
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Dataset</span>
                <select id="fm-osa-dataset" class="fm-bordered-box"><option value="">(tutti)</option></select>
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Indirizzo</span>
                <input id="fm-osa-ind" class="fm-bordered-box" placeholder="SCI" size="6">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Classe</span>
                <input id="fm-osa-cls" class="fm-bordered-box" placeholder="3" size="4">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1">
                <span class="fm-text-xs fm-muted">Materia</span>
                <input id="fm-osa-mat" class="fm-bordered-box" placeholder="MAT" size="6">
            </label>
            <label class="fm-d-flex fm-flex-col fm-gap-1 fm-osa__grow">
                <span class="fm-text-xs fm-muted">Cerca nel nome file</span>
                <input id="fm-osa-search" class="fm-bordered-box" placeholder="testo…">
            </label>
            <button type="button" id="fm-osa-filter-reset" class="fm-btn" title="Azzera i filtri">✕ Filtri</button>
        </div>

        <div class="fm-osa__toolbar fm-d-flex fm-gap-2 fm-items-end fm-flex-wrap">
            <label class="fm-d-flex fm-flex-col fm-gap-1 fm-osa__grow">
                <span class="fm-text-xs fm-muted">File (<span id="fm-osa-count">0</span>)</span>
                <select id="fm-osa-file" class="fm-bordered-box fm-osa__file"><option value="">(caricamento…)</option></select>
            </label>
            <button type="button" id="fm-osa-load" class="fm-btn fm-btn--primary">📥 Carica</button>
            <button type="button" id="fm-osa-raw-toggle" class="fm-btn" disabled>{ } Raw JSON</button>
            <span id="fm-osa-status" class="fm-muted fm-ml-auto fm-text-xs" role="status" aria-live="polite"></span>
        </div>

        <!-- editor strutturato (gruppi → voci) -->
        <div id="fm-osa-structured" class="fm-osa__structured" hidden></div>

        <!-- editor raw (toggle) -->
        <textarea id="fm-osa-raw" class="fm-osa__raw" spellcheck="false"
                  aria-label="Editor JSON grezzo" hidden></textarea>

        <!-- azioni: pulsanti AFFIANCATI (sticky in basso) -->
        <div class="fm-osa__actions fm-d-flex fm-gap-2 fm-flex-wrap fm-items-center">
            <button type="button" id="fm-osa-add-group" class="fm-btn" hidden>➕ Aggiungi gruppo</button>
            <button type="button" id="fm-osa-save" class="fm-btn fm-btn--primary" disabled>💾 Salva file</button>
        </div>
    </div>
    <style>
        .fm-osa { display: flex; flex-direction: column; gap: 12px; width: 100%; }
        .fm-osa__filter, .fm-osa__toolbar { padding: 10px 12px; border-radius: 10px; background: var(--fm-bg-soft, #f8fafc); border: 1px solid var(--fm-border, #e2e8f0); }
        .fm-osa__grow { flex: 1 1 320px; min-width: 220px; }
        body.fm-dark .fm-osa__filter, body.fm-dark .fm-osa__toolbar { background: rgba(255,255,255,.03); border-color: #334155; }
        .fm-osa__file { width: 100%; }
        .fm-osa__structured { width: 100%; }
        .fm-osa__raw {
            width: 100%; box-sizing: border-box; min-height: 440px;
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; font-size: 12.5px; line-height: 1.5;
            padding: 12px 14px; border-radius: 10px; border: 1px solid var(--fm-border, #cbd5e1);
            background: var(--fm-bg-soft, #f8fafc); color: inherit; resize: vertical; tab-size: 2;
        }
        .fm-osa__raw:focus { outline: 2px solid var(--fm-accent, #2a5ac7); outline-offset: -1px; }
        .fm-osa__actions {
            position: sticky; bottom: 0; z-index: 2; padding: 10px 0; margin-top: 2px;
            border-top: 1px solid var(--fm-border, #e2e8f0); background: var(--fm-bg, #fff);
        }
        .fm-osa__group {
            border: 1px solid var(--fm-border, #cbd5e1);
            border-radius: 10px; padding: 12px 14px; margin: 0 0 10px; background: var(--fm-bg-soft, #f8fafc);
        }
        .fm-osa__group-head { display: flex; gap: 8px; align-items: center; margin-bottom: 10px; }
        .fm-osa__group-title { flex: 1; font-weight: 600; font-size: 14px; }
        .fm-osa__item { display: flex; gap: 8px; align-items: flex-start; margin: 5px 0; }
        .fm-osa__item-check { margin-top: 9px; flex: 0 0 auto; width: 16px; height: 16px; }
        .fm-osa__item-label { flex: 1; min-height: 40px; resize: vertical; line-height: 1.4; }
        .fm-osa__btnrow { display: flex; gap: 4px; flex: 0 0 auto; }
        .fm-osa__mini {
            width: 30px; height: 30px; border-radius: 7px; cursor: pointer; font-size: 14px;
            border: 1px solid var(--fm-border, #cbd5e1); background: #fff; line-height: 1;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .fm-osa__mini:hover { background: #f1f5f9; }
        .fm-osa__mini--danger { border-color: #fecaca; color: #b91c1c; }
        .fm-osa__mini--danger:hover { background: #fee2e2; }
        .fm-osa__additem { margin-top: 6px; }
        .fm-osa__extra { font-size: 11px; color: var(--fm-muted, #64748b); margin-left: 4px; }
        body.fm-dark .fm-osa__actions { background: #0b1220; border-color: #334155; }
        body.fm-dark .fm-osa__raw { background: #0f172a; border-color: #334155; }
        body.fm-dark .fm-osa__group { background: rgba(255,255,255,.03); border-color: #334155; }
        body.fm-dark .fm-osa__mini { background: #1e293b; color: #e2e8f0; border-color: #334155; }
    </style>
</section>

</div><!-- /.fm-card -->

<script type="module" src="/js/modules/features/admin-verifica-templates.js?v=g20.0"></script>
<script type="module" src="/js/modules/features/admin-tikz-templates.js?v=g22.s15.bis"></script>
<script type="module" src="/js/modules/features/admin-options-sources.js?v=adr029.3"></script>
<script type="module">
    // G27.badge.style — admin preset CRUD
    import { notify } from "/js/modules/ui/sync-panel.js";
    import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";

    let bsaPresets = [];
    let bsaCurrentName = "";

    const bsaCsrf = fetchCsrf;
    const $ = (id) => document.getElementById(id);
    const setStatus = (s) => { $("fm-bsa-status").textContent = s; };

    async function bsaList() {
        const scope = $("fm-bsa-scope").value.trim() || "_default";
        try {
            const j = await fetchJson(`/api/admin/badge-style-presets?scope=${encodeURIComponent(scope)}`, { cache: "no-store" });
            if (j.error) throw new Error(j.error);
            bsaPresets = Array.isArray(j.presets) ? j.presets : [];
            const sel = $("fm-bsa-preset");
            sel.innerHTML = bsaPresets.length
                ? bsaPresets.map(p => `<option value="${p}">${p}</option>`).join("")
                : '<option value="">(nessun preset)</option>';
            setStatus(`${bsaPresets.length} preset trovati`);
            if (bsaPresets.length) bsaLoadPreset(bsaPresets[0]);
            else { $("fm-bsa-editor").value = ""; $("fm-bsa-save").disabled = true; $("fm-bsa-delete").disabled = true; }
        } catch (e) {
            setStatus("Errore: " + e.message);
        }
    }

    async function bsaLoadPreset(name) {
        const scope = $("fm-bsa-scope").value.trim() || "_default";
        try {
            const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, { cache: "no-store" });
            if (j.error) throw new Error(j.error);
            bsaCurrentName = j.name;
            $("fm-bsa-editor").value = JSON.stringify(j.style, null, 2);
            $("fm-bsa-save").disabled = false;
            $("fm-bsa-format").disabled = false;
            $("fm-bsa-delete").disabled = (name === "_default" && scope === "_default");
            setStatus(`preset "${j.name}" caricato (scope ${j.scope})`);
        } catch (e) {
            setStatus("Errore: " + e.message);
        }
    }

    async function bsaSave() {
        const scope = $("fm-bsa-scope").value.trim() || "_default";
        const name  = bsaCurrentName;
        if (!name) return;
        let payload;
        try { payload = JSON.parse($("fm-bsa-editor").value); }
        catch (e) { setStatus("JSON non valido: " + e.message); return; }
        const csrf = await bsaCsrf();
        try {
            const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, {
                method: "PUT",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify(payload),
            });
            if (!j.ok) throw new Error(j.error || j.message || "richiesta non riuscita");
            $("fm-bsa-editor").value = JSON.stringify(j.style, null, 2);
            notify("Preset stile", "ok", "Salvato (con sanitizzazione)", 2500);
            setStatus("Salvato.");
        } catch (e) {
            setStatus("Errore: " + e.message);
        }
    }

    async function bsaDelete() {
        const scope = $("fm-bsa-scope").value.trim() || "_default";
        const name  = bsaCurrentName;
        if (!name || !confirm(`Eliminare il preset "${name}" dallo scope "${scope}"?`)) return;
        const csrf = await bsaCsrf();
        try {
            const j = await fetchJson(`/api/admin/badge-style-presets/${encodeURIComponent(name)}?scope=${encodeURIComponent(scope)}`, {
                method: "DELETE",
                headers: { "X-CSRF-Token": csrf },
            });
            if (!j.ok) throw new Error(j.error || "richiesta non riuscita");
            notify("Preset stile", "ok", "Eliminato", 2500);
            await bsaList();
        } catch (e) { setStatus("Errore: " + e.message); }
    }

    function bsaNew() {
        const name = (prompt("Nome del nuovo preset (alphanumerico, _ -, max 64):") || "").trim();
        if (!/^[a-zA-Z0-9_-]{1,64}$/.test(name)) { alert("Nome non valido."); return; }
        bsaCurrentName = name;
        $("fm-bsa-editor").value = JSON.stringify({
            "$schema": "pantedu.badge_style.v1",
            fonte: { title_size: "\\small", meta_size: "\\tiny", row_sep: "-5pt", col_spec: "|c|" },
            badge: { bg: "gray", txt: "white", ex_size: "\\large", min_width: "1cm", diff_max: 4, diff_size: "\\huge" }
        }, null, 2);
        $("fm-bsa-save").disabled = false;
        $("fm-bsa-format").disabled = false;
        setStatus(`nuovo preset "${name}" — clicca Salva per persistere`);
    }

    function bsaFormat() {
        try {
            const obj = JSON.parse($("fm-bsa-editor").value);
            $("fm-bsa-editor").value = JSON.stringify(obj, null, 2);
        } catch { /* ignore */ }
    }

    $("fm-bsa-refresh").addEventListener("click", bsaList);
    $("fm-bsa-scope").addEventListener("change", bsaList);
    $("fm-bsa-preset").addEventListener("change", (e) => { if (e.target.value) bsaLoadPreset(e.target.value); });
    $("fm-bsa-new").addEventListener("click", bsaNew);
    $("fm-bsa-save").addEventListener("click", bsaSave);
    $("fm-bsa-delete").addEventListener("click", bsaDelete);
    $("fm-bsa-format").addEventListener("click", bsaFormat);
    // Lazy load: solo quando il tab badge-styles diventa attivo (evita 401 admin per non-admin).
    document.querySelectorAll('.fm-admin-tab').forEach(t => t.addEventListener("click", () => {
        if (t.dataset.tab === "badge-styles" && bsaPresets.length === 0) bsaList();
    }));
</script>

<!-- ADR-025 (B) — editor dati curriculari (override istituzionali) -->
<script>
(function () {
    const $ = (id) => document.getElementById(id);
    const ds = $("fm-co-dataset"), ind = $("fm-co-ind"), cls = $("fm-co-cls"),
          mat = $("fm-co-mat"), scope = $("fm-co-scope"), ed = $("fm-co-editor"),
          status = $("fm-co-status"), bSave = $("fm-co-save"), bFmt = $("fm-co-format"), bDel = $("fm-co-delete");
    if (!ds) return;
    const csrf = (...a) => window.FM.DomUtils.fetchCsrf(...a); // lazy: window.FM pronto a call-time
    const params = () => ({ dataset: ds.value, indirizzo: ind.value.trim().toUpperCase(), classe: cls.value.trim(), materia: mat.value.trim().toUpperCase() });
    const setStatus = (t) => { status.textContent = t; };
    const enable = (on) => { bSave.disabled = bFmt.disabled = bDel.disabled = !on; };
    const valid = (p) => p.indirizzo && p.classe && p.materia;
    async function load() {
        const p = params();
        if (!valid(p)) { setStatus("⚠ Compila indirizzo/classe/materia"); return; }
        setStatus("Carico…");
        try {
            const j = await window.FM.DomUtils.fetchJson("/api/risdoc/curriculum-options?" + new URLSearchParams(p));
            const arr = Array.isArray(j) ? j : [];
            ed.value = JSON.stringify(arr, null, 2);
            enable(true);
            setStatus("✓ Caricato (" + arr.length + " opzioni). Origine: override istituto → globale → file.");
        } catch (e) { setStatus("⚠ Errore: " + e.message); }
    }
    async function save() {
        let opts;
        try { opts = JSON.parse(ed.value); } catch { setStatus("⚠ JSON non valido"); return; }
        if (!Array.isArray(opts)) { setStatus("⚠ Atteso un array JSON"); return; }
        const p = params();
        if (!valid(p)) { setStatus("⚠ Compila indirizzo/classe/materia"); return; }
        const body = { ...p, options: opts };
        if (scope.value === "0") body.institute_id = 0;
        setStatus("Salvo…");
        try {
            const j = await window.FM.DomUtils.fetchJson("/api/risdoc/curriculum-options", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": await csrf() },
                body: JSON.stringify(body),
            });
            setStatus(j.ok ? ("✓ Salvato (istituto " + j.institute_id + ", " + j.count + " opz)") : ("⚠ " + (j.error || "richiesta non riuscita")));
        } catch (e) { setStatus("⚠ Errore: " + e.message); }
    }
    async function del() {
        if (!confirm("Eliminare l'override per questa chiave?")) return;
        const p = params();
        const body = { ...p };
        if (scope.value === "0") body.institute_id = 0;
        setStatus("Elimino…");
        try {
            const j = await window.FM.DomUtils.fetchJson("/api/risdoc/curriculum-options/delete", {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": await csrf() },
                body: JSON.stringify(body),
            });
            setStatus(j.ok ? "✓ Override eliminato (torna al default)" : "Nessun override da eliminare");
        } catch (e) { setStatus("⚠ Errore: " + e.message); }
    }
    $("fm-co-load").addEventListener("click", load);
    bSave.addEventListener("click", save);
    bDel.addEventListener("click", del);
    bFmt.addEventListener("click", () => { try { ed.value = JSON.stringify(JSON.parse(ed.value), null, 2); } catch { /* ignore */ } });
})();
</script>

<!-- Phase 25 — mount editor scorciatoie (admin) lazy al click del tab -->
<script>
(function () {
    let mounted = false;
    function mountSc() {
        const el = document.getElementById("fm-sc-admin-editor");
        if (!el || !window.FM?.ShortcutsEditor) return false;
        window.FM.ShortcutsEditor.mount(el, { admin: true });
        mounted = true;
        return true;
    }
    document.querySelectorAll(".fm-admin-tab").forEach((t) => t.addEventListener("click", () => {
        if (t.dataset.tab !== "shortcuts" || mounted) return;
        if (!mountSc()) { let n = 0; const iv = setInterval(() => { if (mountSc() || ++n > 40) clearInterval(iv); }, 100); }
    }));
    document.getElementById("fm-sc-admin-refresh")?.addEventListener("click", () => { mounted = false; mountSc(); });
})();
</script>

<script>
    // Estrazione PDF: carica l'iframe (preset globale) la PRIMA volta che il tab
    // diventa attivo — sia per click sia se la pagina apre già su #pdf-import.
    (function () {
        const loadFrame = () => {
            const f = document.querySelector('[data-fm-pdfimport-frame]');
            if (f && !f.src && f.dataset.src) f.src = f.dataset.src;
        };
        document.querySelectorAll('.fm-admin-tab').forEach((t) => t.addEventListener('click', () => {
            if (t.dataset.tab === 'pdf-import') loadFrame();
        }));
        const maybeLoad = () => {
            const panel = document.querySelector('[data-panel="pdf-import"]');
            if (location.hash === '#pdf-import' || (panel && !panel.hidden)) loadFrame();
        };
        maybeLoad();
        window.addEventListener('hashchange', maybeLoad);
    })();
</script>
