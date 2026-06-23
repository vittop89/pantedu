<?php
/** G20.1 — Editor template docente (verifiche + esercizi unificati). */
/** @var string $tab — 'verifiche' (default) | 'esercizi' */
/** @var array|null $user */
$pageTitle    = 'PANTEDU — I miei modelli';
$pageContent  = ob_get_clean();
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

<?php if ($tab === 'risdoc'): ?>
<script type="module">
    /** G22.S13 — modal editor per i 3 file texCommon di risdoc.
     *  Usa templateFileAdapter (mode='risdoc-templates'): GET lista + POST save
     *  via /api/teacher/risdoc/templates/files. Backend gestisce cascade
     *  default→institute→teacher; admin salva a scope institute. */

    function activeInstituteCode() {
        try { return sessionStorage.getItem("activeInstituteCode") || ""; }
        catch { return ""; }
    }
    async function refreshStatus() {
        const el = document.getElementById("fm-trd-status");
        if (!el) return;
        const code = activeInstituteCode();
        el.textContent = code
            ? `Istituto attivo: ${code} · 3 file texCommon condivisi (main.tex, risdoc.sty, intestaLAteX_IIS.tex)`
            : "Nessun istituto collegato — usi modello comune";
    }
    async function openRisdocEditor() {
        // 2026-05-28 — lazy-load verifica-preview-modal: il bundle bootstrap
        // lo importa solo se _fmEditorNeeded (pagine editor), NON sulla pagina
        // templates → window.FM.openVerificaPreview era undefined. Import inline
        // qui registra il loader e abilita il modal su click.
        if (typeof window.FM?.openVerificaPreview !== "function") {
            try { await import("/js/modules/features/verifica-preview-modal.js"); }
            catch (e) { console.error("[templates] verifica-preview-modal load failed:", e); }
        }
        const opener = window.FM?.openVerificaPreview;
        if (typeof opener !== "function") {
            alert("Modulo editor non disponibile (ricarica la pagina dopo il build).");
            return;
        }
        try {
            await opener([{
                id: "teacher-risdoc-templates",
                variant: "modelli-risdoc",
                title: "Modelli risdoc",
                institute: activeInstituteCode() || null,
            }], { mode: "risdoc-templates" });
        } catch (e) {
            console.error("[risdoc-templates] open failed:", e);
            alert(`Errore apertura editor: ${e.message}`);
        }
    }
    function bootstrap() {
        const btn = document.getElementById("fm-trd-open");
        if (!btn) return;
        btn.addEventListener("click", openRisdocEditor);
        document.addEventListener("fm:active-institute-changed", refreshStatus);
        refreshStatus();
        // G22.S15.bis Fase 5 — auto-open rimosso: il modal copriva i sub-tab
        // bloccando la navigazione. La pagina è il launcher; l'editor si apre
        // su click esplicito del pulsante "✏️ Apri editor modelli risdoc".
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }
</script>
<?php endif; ?>

<?php if ($tab === 'verifiche'): ?>
<script type="module">
    /** G22.S10c — modal-based templates editor (sostituisce inline UI).
     *  Apre il modal full-screen via window.FM.openVerificaPreview con
     *  mode='template-file'. Il modal usa templateFileAdapter che fetcha
     *  l'intera manifest /api/teacher/verifica/files con paths + sources,
     *  carica content paralleli via /read, gestisce save/compile per file. */

    function activeInstituteCode() {
        try { return sessionStorage.getItem("activeInstituteCode") || ""; }
        catch { return ""; }
    }

    async function refreshStatus() {
        const el = document.getElementById("fm-tvf-status");
        if (!el) return;
        const code = activeInstituteCode();
        el.textContent = code ? `Istituto attivo: ${code}` : "Nessun istituto collegato — usi modello comune";
    }

    async function openTemplatesEditor() {
        // 2026-05-28 — lazy-load verifica-preview-modal: il bundle bootstrap
        // lo importa solo se _fmEditorNeeded (pagine editor), NON sulla pagina
        // templates → window.FM.openVerificaPreview era undefined. Import inline
        // qui registra il loader e abilita il modal su click.
        if (typeof window.FM?.openVerificaPreview !== "function") {
            try { await import("/js/modules/features/verifica-preview-modal.js"); }
            catch (e) { console.error("[templates] verifica-preview-modal load failed:", e); }
        }
        const opener = window.FM?.openVerificaPreview;
        if (typeof opener !== "function") {
            alert("Modulo editor non disponibile (ricarica la pagina dopo il build).");
            return;
        }
        try {
            await opener([{
                id: "teacher-templates",
                variant: "modelli",
                title: "I miei modelli",
                institute: activeInstituteCode() || null,
            }], { mode: "template-file" });
        } catch (e) {
            console.error("[templates] open failed:", e);
            alert(`Errore apertura editor: ${e.message}`);
        }
    }

    function bootstrap() {
        const btn = document.getElementById("fm-tvf-open");
        if (!btn) return;
        btn.addEventListener("click", openTemplatesEditor);
        // G20.7 — riapri editor (forza refresh data) al cambio istituto sidebar.
        document.addEventListener("fm:active-institute-changed", () => {
            refreshStatus();
        });
        refreshStatus();
        // G22.S15.bis Fase 5 — auto-open rimosso: il modal full-screen
        // copriva i sub-tab bloccando la navigazione tra Verifiche/Esercizi/
        // Risdoc. La pagina è il launcher; l'editor si apre su click esplicito
        // del pulsante "✏️ Apri editor modelli".
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bootstrap, { once: true });
    } else {
        bootstrap();
    }
</script>
<?php endif; ?>

<?php if ($tab === 'drawio'): ?>
<script>
/** G22.S15.bis Fase 5 — Drawio shape libraries: list + upload + delete.
 *  Niente browser alert(): notifiche via SyncPanel (sync-panel.js).
 *  Conferma delete via flow inline: pending state + click conferma. */
(function() {
    const list = document.getElementById('fm-drawio-list');
    if (!list) return;
    const uploadInput = document.getElementById('fm-drawio-upload');
    const refreshBtn  = document.getElementById('fm-drawio-refresh');

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    function fmtSize(b) {
        if (b < 1024) return b + ' B';
        if (b < 1024*1024) return Math.round(b/1024) + ' KB';
        return (b/(1024*1024)).toFixed(1) + ' MB';
    }
    function notify(title, kind, msg, ttl = 4000) {
        const sp = window.FM && window.FM.SyncPanel;
        if (sp && typeof sp.notify === 'function') {
            sp.notify(title, kind, msg, ttl);
        } else {
            console.info('[' + title + ']', msg);
        }
    }
    const csrf = (...a) => window.FM.DomUtils.fetchCsrf(...a); // lazy: window.FM pronto a call-time

    async function refresh() {
        try {
            const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries');
            if (!j.ok) {
                list.innerHTML = '<em>Errore: ' + escHtml(j.error || 'unknown') + '</em>';
                return;
            }
            if (!j.libraries || j.libraries.length === 0) {
                list.innerHTML = '<em>Nessuna libreria caricata. Usa "Carica nuova libreria" per aggiungerne.</em>';
                return;
            }
            const html = j.libraries.map(lib => {
                const sourceTag = lib.source === 'teacher'
                    ? '<span class="fm-pill-success-sm">tua</span>'
                    : '<span class="fm-pill-muted-sm">default</span>';
                const delBtn = lib.source === 'teacher'
                    ? `<button class="fm-btn fm-btn--ghost fm-btn--sm" data-del="${escHtml(lib.name)}" title="Click per eliminare (richiede 2° click di conferma)">🗑</button>`
                    : '';
                return `<div class="fm-drawio-list__item">
                    <span>📐 <strong>${escHtml(lib.name)}</strong> ${sourceTag} <span class="fm-opacity-60 fm-ml-2">${fmtSize(lib.size)}</span></span>
                    <span>${delBtn}</span>
                </div>`;
            }).join('');
            list.innerHTML = html;
            // Inline confirm flow: 1° click → bottone diventa "Conferma?";
            // 2° click entro 4s → cancellazione effettiva. Niente confirm() browser.
            list.querySelectorAll('[data-del]').forEach(btn => {
                let pending = false;
                let pendingTimer = null;
                btn.addEventListener('click', async () => {
                    const name = btn.dataset.del;
                    if (!pending) {
                        pending = true;
                        const orig = btn.innerHTML;
                        btn.innerHTML = '⚠ Conferma?';
                        btn.style.background = '#fef2f2';
                        btn.style.color = '#b91c1c';
                        btn.style.borderColor = '#fca5a5';
                        pendingTimer = setTimeout(() => {
                            pending = false;
                            btn.innerHTML = orig;
                            btn.style.cssText = '';
                        }, 4000);
                        return;
                    }
                    clearTimeout(pendingTimer);
                    pending = false;
                    try {
                        const tok = await csrf();
                        const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries/delete', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tok },
                            body: JSON.stringify({ name, _csrf: tok }),
                        });
                        if (!j.ok) {
                            notify('🗑 Drawio', 'error', 'Errore: ' + (j.error || 'richiesta non riuscita'), 5000);
                            return;
                        }
                        notify('🗑 Drawio', 'ok', `Libreria "${name}" eliminata`, 3000);
                        await refresh();
                    } catch (e) {
                        notify('🗑 Drawio', 'error', 'Errore di rete: ' + e.message, 5000);
                    }
                });
            });
        } catch (e) {
            list.innerHTML = '<em>Errore: ' + escHtml(e.message) + '</em>';
        }
    }

    uploadInput?.addEventListener('change', async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        if (file.size > 1024 * 1024) {
            notify('📤 Upload', 'error', 'File troppo grande (max 1 MB).', 4000);
            uploadInput.value = '';
            return;
        }
        if (!/\.xml$/i.test(file.name)) {
            notify('📤 Upload', 'error', 'Solo file .xml accettati.', 4000);
            uploadInput.value = '';
            return;
        }
        try {
            const tok = await csrf();
            const fd = new FormData();
            fd.append('file', file);
            fd.append('_csrf', tok);
            const j = await window.FM.DomUtils.fetchJson('/api/teacher/drawio/libraries/upload', {
                method: 'POST',
                headers: { 'X-CSRF-Token': tok },
                body: fd,
            });
            if (!j.ok) {
                notify('📤 Upload', 'error', 'Errore: ' + (j.error || 'richiesta non riuscita'), 5000);
                return;
            }
            notify('📤 Upload', 'ok', `"${j.name}" caricata (${fmtSize(j.size)})`, 3000);
            await refresh();
        } catch (err) {
            notify('📤 Upload', 'error', 'Errore di rete: ' + err.message, 5000);
        } finally { uploadInput.value = ''; }
    });

    refreshBtn?.addEventListener('click', refresh);
    refresh();
})();
</script>
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
<script type="module">
    /** G22.S15.bis Fase 5+ — TikZ workspace tab.
     *  Riusa endpoint TeacherWorkspaceController + modal lazy
     *  (openTexElementEditor / openTikzBlocksManager) gia' esistenti. */
    import { notify } from "/js/modules/ui/sync-panel.js";
    import { fetchJson, fetchCsrf } from "/js/modules/core/dom-utils.js";

    const groupsEl = document.getElementById("fm-tikz-groups");
    let workspaceCache = null;

    async function apiPost(url, body) {
        const csrf = await fetchCsrf();
        try {
            return await fetchJson(url, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
                body: JSON.stringify(body || {}),
            });
        } catch (e) { return { ok: false, error: e.message }; }
    }

    async function loadWorkspace() {
        groupsEl.innerHTML = '<p class="fm-muted fm-text-center fm-p-5" >Caricamento…</p>';
        try {
            workspaceCache = await fetchJson("/tikz/workspace", { cache: "no-store" });
            renderWorkspace();
        } catch (e) {
            groupsEl.innerHTML = `<p class="fm-tikz-empty">Errore caricamento: ${e.message}</p>`;
        }
    }

    function renderWorkspace() {
        groupsEl.innerHTML = "";
        const data = workspaceCache;
        if (!data || typeof data !== "object" || Object.keys(data).length === 0) {
            groupsEl.innerHTML = '<p class="fm-tikz-empty">Workspace vuoto. Importa dalla libreria admin o crea un nuovo elemento.</p>';
            return;
        }
        for (const [groupKey, items] of Object.entries(data)) {
            if (!Array.isArray(items)) continue;
            groupsEl.appendChild(renderGroup(groupKey, items));
        }
    }

    function renderGroup(groupKey, items) {
        const name = groupKey.replace(/^gruppo-/, "");
        const wrap = document.createElement("div");
        wrap.className = "fm-tikz-group";
        wrap.dataset.group = groupKey;

        const hdr = document.createElement("div");
        hdr.className = "fm-tikz-group__hdr";
        hdr.innerHTML = `
            <span class="fm-tikz-chevron">▶</span>
            <span class="fm-tikz-group__title">${escapeHtml(name)}</span>
            <span class="fm-tikz-group__count">(${items.length})</span>
        `;
        const itemsBox = document.createElement("div");
        itemsBox.className = "fm-tikz-group__items";

        // Toggle expand on header click
        hdr.addEventListener("click", (e) => {
            if (e.target.closest("button")) return;
            wrap.classList.toggle("fm-tikz-group--open");
            const chev = hdr.querySelector(".fm-tikz-chevron");
            if (chev) chev.textContent = wrap.classList.contains("fm-tikz-group--open") ? "▼" : "▶";
        });

        // Header actions: ➕ ✏️ 🗑️
        const actions = document.createElement("div");
        actions.style.cssText = "display:flex;gap:4px;";
        const newBtn = mkBtn("➕", "Nuovo elemento in questo gruppo", () => addElementInGroup(groupKey));
        const renBtn = mkBtn("✏️", "Rinomina gruppo", () => renameGroup(groupKey));
        const delBtn = mkBtn("🗑️", "Elimina gruppo", () => deleteGroup(groupKey, items.length));
        actions.appendChild(newBtn); actions.appendChild(renBtn); actions.appendChild(delBtn);
        hdr.appendChild(actions);

        // Items
        for (const it of items) {
            itemsBox.appendChild(renderItem(groupKey, it));
        }
        wrap.appendChild(hdr);
        wrap.appendChild(itemsBox);
        return wrap;
    }

    function renderItem(groupKey, item) {
        const row = document.createElement("div");
        row.className = "fm-tikz-item";
        const label = item?.label || item?.name || "(senza nome)";
        const type = item?.type || "tikz";
        row.innerHTML = `
            <span class="fm-tikz-item__type">${escapeHtml(type).toUpperCase()}</span>
            <span class="fm-tikz-item__label">${escapeHtml(label)}</span>
        `;
        // Schema-modulare button: visibile solo se l'item HA dati schema
        // (item._data o marker __FM_TPL_DATA__ nel content). Permette modifica
        // dei valori iniziali via form.
        if (isSchemaModulare(item)) {
            const schemaBtn = mkBtn("📋 Schema", "Modifica i valori iniziali del form (schema modulare)", () => editElementSchema(groupKey, item));
            schemaBtn.style.padding = "3px 10px";
            row.appendChild(schemaBtn);
        }
        // Codice TikZ raw: sempre disponibile (editor CodeMirror sul content).
        const codeBtn = mkBtn("✏️ Codice", "Modifica il codice TikZ/LaTeX raw (CodeMirror)", () => editElementCode(groupKey, item));
        codeBtn.style.padding = "3px 10px";
        row.appendChild(codeBtn);
        const delBtn  = mkBtn("🗑️", "Elimina elemento", () => deleteElement(groupKey, item));
        row.appendChild(delBtn);
        return row;
    }

    function mkBtn(label, title, handler) {
        const b = document.createElement("button");
        b.type = "button";
        b.className = "fm-tikz-item__btn";
        b.textContent = label;
        b.title = title;
        b.addEventListener("click", (e) => { e.preventDefault(); e.stopPropagation(); handler(); });
        return b;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
    }

    /* ───── Editor lazy-loader (riuso modali esistenti) ───── */
    const FM_TPL_DATA_RE = /^%\s*__FM_TPL_DATA__:([A-Za-z0-9+/=]+)\s*$/m;
    function extractTemplateData(content) {
        const m = (content || "").match(FM_TPL_DATA_RE);
        if (!m) return null;
        try { return JSON.parse(decodeURIComponent(escape(atob(m[1])))); }
        catch { try { return JSON.parse(atob(m[1])); } catch { return null; } }
    }
    function isSchemaModulare(item) {
        return !!item?._data || extractTemplateData(item?.content) !== null;
    }

    async function lazyLoadEntry(entryName, fmKey) {
        if (window.FM?.[fmKey]) return true;
        try {
            const m = await fetchJson(`/build/manifest.json?t=${Date.now()}`, { cache: "no-store" });
            const entry = m[`js/entries/${entryName}`];
            if (!entry) throw new Error(`entry ${entryName} assente`);
            await import(/* @vite-ignore */ `/build/${entry.file}`);
            return !!window.FM?.[fmKey];
        } catch (e) {
            notify("Editor", "error", `Caricamento ${entryName} fallito: ${e.message}`, 5000);
            return false;
        }
    }
    const ensureElementEditor = () => lazyLoadEntry("tex-element-editor.js", "openTexElementEditor");
    const ensureTemplateFiller = () => lazyLoadEntry("tikz-template-filler.js", "openTemplateFiller");

    async function ensureBlocksManager() {
        if (window.FM?.openTikzBlocksManager) return true;
        try {
            const m = await fetchJson(`/build/manifest.json?t=${Date.now()}`, { cache: "no-store" });
            const entry = m["js/entries/tikz-blocks-manager.js"];
            if (!entry) throw new Error("entry tikz-blocks-manager assente");
            await import(/* @vite-ignore */ `/build/${entry.file}`);
            return !!window.FM?.openTikzBlocksManager;
        } catch (e) {
            notify("Manager", "error", `Caricamento fallito: ${e.message}`, 5000);
            return false;
        }
    }

    /* ───── Actions ───── */
    /** Dialog unificato "Nuovo / Importa": tab "Da zero" → openTexElementEditor,
     *  tab "Importa libreria" → checkbox-list multi-select con filtro tipo. */
    async function addNewElement() {
        // Pre-fetch admin library in parallelo con UI render.
        const libPromise = fetch("/tikz/admin-library", { credentials: "same-origin", cache: "no-store" })
            .then(r => r.ok ? r.json() : Promise.reject(new Error(`HTTP ${r.status}`)))
            .catch(e => { notify("Libreria", "warn", `Libreria non caricata: ${e.message}`, 5000); return {}; });

        document.getElementById("fm-tikz-new-dialog")?.remove();
        const dlg = document.createElement("div");
        dlg.id = "fm-tikz-new-dialog";
        dlg.className = "fm-tikz-newdlg";
        dlg.innerHTML = `
            <div class="fm-tikz-newdlg__panel">
                <div class="fm-tikz-newdlg__hdr">
                    <span class="fm-flex-1-grow fm-fw-600">➕ Nuovo elemento</span>
                    <button data-act="close" class="fm-tikz-newdlg__x">✕</button>
                </div>
                <div class="fm-tikz-newdlg__tabs">
                    <button data-tab="scratch" class="fm-tikz-newdlg__tab fm-tikz-newdlg__tab--active">📝 Da zero</button>
                    <button data-tab="import" class="fm-tikz-newdlg__tab">📚 Importa da libreria admin</button>
                </div>
                <div data-tabbody="scratch" class="fm-tikz-newdlg__body">
                    <p class="fm-muted fm-m-0 fm-mb-3 fm-text-13" >
                        Crea un elemento vuoto in un gruppo nuovo o esistente.
                        L'editor CodeMirror si apre con i campi <strong>Tipo</strong>, <strong>Gruppo</strong>, <strong>Nome</strong>, <strong>Codice</strong>.
                    </p>
                    <button class="fm-btn fm-btn--primary" data-act="open-scratch">Apri editor →</button>
                </div>
                <div data-tabbody="import" class="fm-tikz-newdlg__body fm-d-none" >
                    <div class="fm-tikz-newdlg__filters">
                        <input type="search" data-role="search" class="fm-tikz-newdlg__search" placeholder="Cerca per nome…">
                        <label><input type="checkbox" data-filter="tikz" checked> ✒️ tikz</label>
                        <label><input type="checkbox" data-filter="schema" checked> 📋 schema</label>
                        <label><input type="checkbox" data-filter="latex" checked> ✏️ latex</label>
                    </div>
                    <div data-role="list" class="fm-tikz-newdlg__list">
                        <p class="fm-muted fm-text-center fm-p-5" >Caricamento libreria…</p>
                    </div>
                    <div class="fm-tikz-newdlg__footer">
                        <span data-role="count" class="fm-muted fm-text-xs fm-flex-1-grow" >0 selezionati</span>
                        <button class="fm-btn fm-btn--ghost fm-btn--sm" data-act="select-all">Tutti</button>
                        <button class="fm-btn fm-btn--ghost fm-btn--sm" data-act="select-none">Nessuno</button>
                        <button class="fm-btn fm-btn--primary" data-act="import" disabled>📥 Importa selezionati</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(dlg);

        const $ = (sel) => dlg.querySelector(sel);
        const $$ = (sel) => Array.from(dlg.querySelectorAll(sel));
        const close = () => { dlg.remove(); document.removeEventListener("keydown", esc); };
        const esc = (e) => { if (e.key === "Escape") close(); };
        document.addEventListener("keydown", esc);
        dlg.addEventListener("click", (e) => {
            if (e.target?.dataset?.act === "close" || e.target === dlg) close();
        });

        // Tab switching
        $$(".fm-tikz-newdlg__tab").forEach(t => t.addEventListener("click", () => {
            const k = t.dataset.tab;
            $$(".fm-tikz-newdlg__tab").forEach(x => x.classList.toggle("fm-tikz-newdlg__tab--active", x === t));
            $$("[data-tabbody]").forEach(b => { b.style.display = b.dataset.tabbody === k ? "" : "none"; });
        }));

        // Tab "Da zero"
        $('[data-act="open-scratch"]').addEventListener("click", async () => {
            close();
            await openScratchEditor();
        });

        // Tab "Importa": render checkbox list (lazy: load only after tab switch first time)
        let library = null;
        const renderLibrary = async () => {
            if (library !== null) return;
            library = await libPromise;
            const listEl = $('[data-role="list"]');
            listEl.innerHTML = "";
            if (!library || typeof library !== "object" || Object.keys(library).length === 0) {
                listEl.innerHTML = '<p class="fm-muted fm-text-center fm-p-5" >Libreria admin vuota.</p>';
                return;
            }
            for (const [gKey, items] of Object.entries(library)) {
                if (!Array.isArray(items)) continue;
                const gName = gKey.replace(/^gruppo-/, "");
                const gWrap = document.createElement("div");
                gWrap.className = "fm-tikz-newdlg__group";
                gWrap.dataset.group = gKey;
                gWrap.innerHTML = `<div class="fm-tikz-newdlg__ghdr">📂 ${escapeHtml(gName)} <span class="fm-muted fm-fw-400" >(${items.length})</span></div>`;
                items.forEach(it => {
                    const t = inferType(it);
                    const row = document.createElement("label");
                    row.className = "fm-tikz-newdlg__row";
                    row.dataset.gkey = gKey;
                    row.dataset.label = it.label || "";
                    row.dataset.type = t;
                    row.innerHTML = `
                        <input type="checkbox" data-role="cb">
                        <span class="fm-tikz-item__type fm-tikz-newdlg__type fm-tikz-newdlg__type--${t}">${t.toUpperCase()}</span>
                        <span class="fm-flex-1-grow">${escapeHtml(it.label || "(senza nome)")}</span>
                    `;
                    gWrap.appendChild(row);
                });
                listEl.appendChild(gWrap);
            }
            // bind checkbox count
            listEl.addEventListener("change", updateCount);
            updateCount();
        };

        function inferType(it) {
            if (it?._data || (typeof it.content === "string" && /^%\s*__FM_TPL_DATA__:/m.test(it.content))) return "schema";
            return (it?.type || "tikz").toLowerCase();
        }
        function visibleRows() {
            const q = ($('[data-role="search"]').value || "").trim().toLowerCase();
            const allowTypes = $$('[data-filter]:checked').map(c => c.dataset.filter);
            return $$(".fm-tikz-newdlg__row").filter(r => {
                if (!allowTypes.includes(r.dataset.type)) { r.style.display = "none"; return false; }
                if (q && !r.dataset.label.toLowerCase().includes(q)) { r.style.display = "none"; return false; }
                r.style.display = "";
                return true;
            });
        }
        function updateCount() {
            visibleRows();
            const checked = $$('[data-role="cb"]:checked').filter(c => c.closest(".fm-tikz-newdlg__row").style.display !== "none");
            $('[data-role="count"]').textContent = `${checked.length} selezionati`;
            $('[data-act="import"]').disabled = checked.length === 0;
        }
        $('[data-role="search"]').addEventListener("input", updateCount);
        $$('[data-filter]').forEach(c => c.addEventListener("change", updateCount));
        $('[data-act="select-all"]').addEventListener("click", () => {
            visibleRows().forEach(r => r.querySelector('[data-role="cb"]').checked = true);
            updateCount();
        });
        $('[data-act="select-none"]').addEventListener("click", () => {
            $$('[data-role="cb"]').forEach(c => c.checked = false);
            updateCount();
        });

        // Lazy render della libreria al primo click sul tab Importa
        $$(".fm-tikz-newdlg__tab")[1].addEventListener("click", renderLibrary, { once: true });

        // Import
        $('[data-act="import"]').addEventListener("click", async () => {
            const rows = $$('[data-role="cb"]:checked').map(c => c.closest(".fm-tikz-newdlg__row"))
                .filter(r => r.style.display !== "none");
            if (rows.length === 0) return;
            const btn = $('[data-act="import"]');
            btn.disabled = true;
            const orig = btn.textContent;
            let ok = 0, fail = 0, conflicts = [];
            for (const r of rows) {
                btn.textContent = `Importando ${ok + fail + 1}/${rows.length}…`;
                const res = await apiPost("/tikz/workspace/import", {
                    sourceGroupKey: r.dataset.gkey,
                    sourceLabel: r.dataset.label,
                    targetGroupKey: r.dataset.gkey,
                    conflict: "abort",
                });
                if (res?.action === "created" || res?.success === true || res?.ok === true) {
                    ok++;
                } else if (res?.action === "aborted") {
                    conflicts.push({ gkey: r.dataset.gkey, label: r.dataset.label, row: r });
                } else {
                    fail++;
                }
            }
            btn.textContent = orig;
            // Conflicts: ask once "overwrite all / skip all"
            if (conflicts.length > 0) {
                const ow = confirm(`${conflicts.length} elemento/i hanno conflitti di nome.\n\nOK = sovrascrivere tutti\nAnnulla = saltare tutti`);
                if (ow) {
                    for (const c of conflicts) {
                        const res = await apiPost("/tikz/workspace/import", {
                            sourceGroupKey: c.gkey, sourceLabel: c.label,
                            targetGroupKey: c.gkey, conflict: "overwrite",
                        });
                        if (res?.success === true || res?.ok === true || res?.action === "created" || res?.action === "overwritten") ok++;
                        else fail++;
                    }
                }
            }
            await loadWorkspace();
            close();
            const msg = fail === 0 ? `${ok} elementi importati.` : `${ok} importati, ${fail} falliti.`;
            notify("Importa", fail === 0 ? "ok" : "warn", msg, 4000);
        });

        // Helper per "Da zero" (ex addNewElement)
        async function openScratchEditor(groupKey = "") {
            if (!await ensureElementEditor()) return;
            const existingGroups = Object.keys(workspaceCache || {});
            window.FM.openTexElementEditor({
                mode: "new",
                groupKey,
                existingGroups,
                initialType: "tikz",
                initialLabel: "",
                initialCode: "",
                onSave: async ({ type, label, code, groupName, newGroup }) => {
                    const targetKey = newGroup
                        ? ("gruppo-" + newGroup.toLowerCase().replace(/\s+/g, "-"))
                        : (groupName || groupKey);
                    if (!targetKey || !label) {
                        notify("Salva", "warn", "Manca gruppo o nome", 4000);
                        return { ok: false };
                    }
                    const res = await apiPost("/tikz/workspace/element/save", { groupKey: targetKey, label, type, code });
                    if (res?.success === true || res?.ok === true) {
                        notify("Elemento", "ok", "Creato nel tuo workspace", 3000);
                        await loadWorkspace();
                        return { ok: true };
                    }
                    notify("Errore", "error", res?.error || "?", 5000);
                    return { ok: false, error: res?.error };
                },
                onCancel: () => {},
            });
        }
    }

    async function addElementInGroup(groupKey) {
        if (!await ensureElementEditor()) return;
        const existingGroups = Object.keys(workspaceCache || {});
        window.FM.openTexElementEditor({
            mode: "new",
            groupKey,
            existingGroups,
            initialType: "tikz",
            initialLabel: "",
            initialCode: "",
            onSave: async ({ type, label, code, groupName, newGroup }) => {
                const targetKey = newGroup
                    ? ("gruppo-" + newGroup.toLowerCase().replace(/\s+/g, "-"))
                    : (groupName || groupKey);
                const res = await apiPost("/tikz/workspace/element/save", { groupKey: targetKey, label, type, code });
                if (res?.success === true || res?.ok === true) {
                    notify("Elemento", "ok", "Creato", 3000);
                    await loadWorkspace();
                    return { ok: true };
                }
                notify("Errore", "error", res?.error || "?", 5000);
                return { ok: false, error: res?.error };
            },
            onCancel: () => {},
        });
    }

    /** Modifica i valori iniziali del form schema-modulare. Disponibile solo
     *  per item con _data o marker __FM_TPL_DATA__ nel content. */
    async function editElementSchema(groupKey, item) {
        const originalLabel = item.label || "";
        if (!await ensureTemplateFiller()) return;
        const initialData = (item._data && typeof item._data === "object")
            ? item._data
            : extractTemplateData(item.content);
        window.FM.openTemplateFiller("schema-modulare", initialData, /*onSave legacy*/ null, {
            title: `Schema modulare — ${originalLabel}`,
            isOverride: !!item._override,
            groupKey, label: originalLabel,
            onSavePref: async (tikzString, data) => {
                const res = await apiPost("/tikz/workspace/element/save", {
                    groupKey, label: originalLabel, oldLabel: originalLabel,
                    type: "tikz", code: tikzString, data,
                });
                if (res?.success === true || res?.ok === true) {
                    notify("Schema", "ok", "Valori iniziali salvati", 3000);
                    await loadWorkspace();
                    return true;
                }
                notify("Errore", "error", res?.error || "?", 5000);
                return false;
            },
            onReset: async () => {
                const ok = confirm(`Ripristinare "${originalLabel}" dal default admin?`);
                if (!ok) return false;
                const res = await apiPost("/tikz/workspace/import", {
                    sourceGroupKey: groupKey, sourceLabel: originalLabel,
                    targetGroupKey: groupKey, conflict: "overwrite",
                });
                if (res?.success === true || res?.ok === true) {
                    notify("Schema", "ok", "Ripristinato", 3000);
                    await loadWorkspace();
                    return true;
                }
                notify("Errore", "error", res?.error || "?", 5000);
                return false;
            },
        });
    }

    /** Modifica il codice TikZ/LaTeX raw via CodeMirror editor. Sempre
     *  disponibile, anche per item schema-modulare (in tal caso si edita
     *  il template TeX sottostante che il filler pre-popola). */
    async function editElementCode(groupKey, item) {
        const originalLabel = item.label || "";
        if (!await ensureElementEditor()) return;
        window.FM.openTexElementEditor({
            mode: "insert",  // insert mode espone toolbar (Aggiungi/Salva/Reset/Chiudi)
            initialType: item.type || "tikz",
            initialCode: item.content || "",
            title: `Codice TikZ — ${originalLabel}`,
            actions: {
                isOverride: !!item._override,
                onAdd: null, // no panel context nel tab → Aggiungi disabilitato
                onSavePref: async (api) => {
                    const code = api.getCode();
                    if (!code.trim()) { notify("Salva", "warn", "Codice vuoto", 4000); return; }
                    const data = extractTemplateData(code);
                    const res = await apiPost("/tikz/workspace/element/save", {
                        groupKey, label: originalLabel, oldLabel: originalLabel,
                        type: api.getType(), code,
                        ...(data ? { data } : {}),
                    });
                    if (res?.success === true || res?.ok === true) {
                        notify("Codice", "ok", "Salvato", 3000);
                        await loadWorkspace();
                        api.close();
                    } else {
                        notify("Errore", "error", res?.error || "?", 5000);
                    }
                },
                onReset: async (api) => {
                    const ok = confirm(`Ripristinare "${originalLabel}" dal default admin?`);
                    if (!ok) return;
                    const res = await apiPost("/tikz/workspace/import", {
                        sourceGroupKey: groupKey, sourceLabel: originalLabel,
                        targetGroupKey: groupKey, conflict: "overwrite",
                    });
                    if (res?.success === true || res?.ok === true) {
                        notify("Codice", "ok", "Ripristinato", 3000);
                        await loadWorkspace();
                        api.close();
                    } else {
                        notify("Errore", "error", res?.error || "?", 5000);
                    }
                },
            },
        });
    }

    async function deleteElement(groupKey, item) {
        const ok = confirm(`Eliminare "${item.label}" dal gruppo "${groupKey.replace(/^gruppo-/, "")}"?`);
        if (!ok) return;
        const res = await apiPost("/tikz/workspace/element/delete", { groupKey, label: item.label });
        if (res?.success === true || res?.ok === true) {
            notify("Elemento", "ok", "Eliminato", 2500);
            await loadWorkspace();
        } else {
            notify("Errore", "error", res?.error || "?", 5000);
        }
    }

    async function renameGroup(groupKey) {
        const oldName = groupKey.replace(/^gruppo-/, "");
        const newName = prompt(`Rinomina gruppo "${oldName}" in:`, oldName);
        if (!newName || newName.trim() === oldName) return;
        const res = await apiPost("/tikz/workspace/group/rename", {
            groupKey, newName: newName.trim(),
        });
        if (res?.success === true || res?.ok === true) {
            notify("Gruppo", "ok", "Rinominato", 2500);
            await loadWorkspace();
        } else {
            notify("Errore", "error", res?.error || "?", 5000);
        }
    }

    async function deleteGroup(groupKey, count) {
        const name = groupKey.replace(/^gruppo-/, "");
        const ok = confirm(`Eliminare il gruppo "${name}" con i suoi ${count} elementi DAL TUO WORKSPACE?\nI defaults admin restano intatti.`);
        if (!ok) return;
        const res = await apiPost("/tikz/workspace/group/delete", { groupKey });
        if (res?.success === true || res?.ok === true) {
            notify("Gruppo", "ok", "Eliminato", 2500);
            await loadWorkspace();
        } else {
            notify("Errore", "error", res?.error || "?", 5000);
        }
    }

    async function resetWorkspace() {
        const ok = confirm("RESET WORKSPACE: sostituire TUTTO il tuo workspace con i defaults admin?\nGruppi rinominati, elementi modificati, aggiunte personali — TUTTO andrà perso.\nL'operazione è irreversibile.");
        if (!ok) return;
        const res = await apiPost("/tikz/workspace/reset-all", {});
        if (res?.success === true || res?.ok === true) {
            notify("Workspace", "ok", "Resettato al default admin", 3000);
            await loadWorkspace();
        } else {
            notify("Errore", "error", res?.error || "?", 5000);
        }
    }

    async function openManager() {
        if (!await ensureBlocksManager()) return;
        // openTikzBlocksManager si aspetta un textarea-target. Passiamo un
        // <textarea> dummy (i bottoni Insert/Apply nel modal sono no-op se
        // non c'e' un block context — l'utente puo' comunque CRUD-are.
        const dummy = document.createElement("textarea");
        dummy.value = "";
        dummy._tikzBlocks = [];
        window.FM.openTikzBlocksManager(dummy);
    }

    /* ───── Bind ───── */
    document.getElementById("fm-tikz-new")?.addEventListener("click", addNewElement);
    document.getElementById("fm-tikz-manage")?.addEventListener("click", openManager);
    document.getElementById("fm-tikz-refresh")?.addEventListener("click", loadWorkspace);
    document.getElementById("fm-tikz-reset")?.addEventListener("click", resetWorkspace);
    loadWorkspace();
</script>
<?php endif; ?>

<?php if ($tab === 'scorciatoie'): ?>
<script type="module">
    function _mountSc() {
        const el = document.getElementById("fm-sc-editor");
        if (el && window.FM?.ShortcutsEditor) { window.FM.ShortcutsEditor.mount(el, { admin: false }); return true; }
        return false;
    }
    if (!_mountSc()) {
        let n = 0;
        const t = setInterval(() => { if (_mountSc() || ++n > 40) clearInterval(t); }, 100);
    }
</script>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
