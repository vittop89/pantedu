<?php
/** G20.0 — Profilo docente: gestione istituti collegati. */
$pageTitle    = 'PANTEDU — Profilo docente';
$pageContent  = ob_get_clean();
$bodyClass    = 'fm-area-docente-profilo';
$currentRoute = '/area-docente/profilo';
ob_start();
?>
<?php include __DIR__ . '/../partials/_area_docente_nav.php'; ?>

<main class="fm-area-docente-page">
    <header>
        <h1>👤 Profilo del docente <button type="button" class="fm-infotip" aria-label="Info profilo"><span class="fm-infotip__body" hidden>Gestisci i tuoi istituti di lavoro, le materie/classi/indirizzi che insegni e il curriculum dell'istituto attivo.</span></button></h1>
    </header>

    <section class="fm-card">
        <h2>📌 Istituti collegati <button type="button" class="fm-infotip" aria-label="Info istituti collegati"><span class="fm-infotip__body" hidden>L'istituto attivo si seleziona dalla sidebar a sinistra (dropdown <em>Istituto</em>). Le risorse (verifiche, mappe) vengono filtrate per quello.</span></button></h2>
        <div id="fm-profile-current">
            <p class="fm-muted">Caricamento…</p>
        </div>

        <h3 class="fm-mt-4 fm-mb-1">➕ Aggiungi un istituto</h3>
        <p class="fm-muted fm-m-0 fm-mb-3 fm-text-13" >Cerca per nome, codice meccanografico o città:</p>
        <div class="fm-d-flex fm-gap-2 fm-items-center fm-flex-wrap">
            <input type="text" id="fm-profile-search" placeholder="Es. Esempio Comune Esempio…" autocomplete="off"
                   class="fm-input-pill">
            <button type="button" class="fm-btn fm-btn--primary" id="fm-profile-add-btn">Collega istituto</button>
        </div>
        <div id="fm-profile-add-feedback" class="fm-muted fm-text-13 fm-mt-2" ></div>
    </section>

    <section class="fm-card">
        <h2>🎓 Curriculum dell'istituto attivo <button type="button" class="fm-infotip" aria-label="Info curriculum istituto"><span class="fm-infotip__body" hidden><p>Tutte le voci (<strong>indirizzi, classi, materie</strong>) sono <strong>per-docente</strong>: qui gestisci le <em>tue</em> nell'istituto attivo. I colleghi non vedono né possono modificare ciò che aggiungi.</p><p><strong>Istituto</strong>: serve come boundary di condivisione (pool materiali con colleghi dello stesso istituto) e come identità anagrafica (codice MIUR, nome scuola). Per condividere i tuoi contenuti, attiva il toggle "Condivisa" sulla riga della materia (oppure il toggle "🤝 Condividi con colleghi" nella scheda del singolo contenuto).</p></span></button></h2>
        <p class="fm-muted fm-text-13 fm-mt-1 fm-mb-2" id="fm-curr-active-inst">Caricamento…</p>
        <div class="fm-subtabs" id="fm-curr-tabs" role="tablist" aria-label="Tipo di voce del curriculum">
            <button class="fm-subtab fm-subtab--active" id="fm-tab-indirizzi" role="tab" aria-selected="true" aria-controls="fm-panel-indirizzi" data-kind="indirizzi" type="button">🎯 Indirizzi</button>
            <button class="fm-subtab" id="fm-tab-classi" role="tab" aria-selected="false" aria-controls="fm-panel-classi" tabindex="-1" data-kind="classi" type="button">🏫 Classi</button>
            <button class="fm-subtab" id="fm-tab-materie" role="tab" aria-selected="false" aria-controls="fm-panel-materie" tabindex="-1" data-kind="materie" type="button">📚 Materie</button>
        </div>
        <div id="fm-curr-panels">
            <section class="fm-curr-panel" id="fm-panel-indirizzi" role="tabpanel" aria-labelledby="fm-tab-indirizzi" tabindex="0" data-panel="indirizzi"></section>
            <section class="fm-curr-panel fm-d-none" id="fm-panel-classi" role="tabpanel" aria-labelledby="fm-tab-classi" tabindex="0" data-panel="classi" ></section>
            <section class="fm-curr-panel fm-d-none" id="fm-panel-materie" role="tabpanel" aria-labelledby="fm-tab-materie" tabindex="0" data-panel="materie" ></section>
        </div>
    </section>
    <?php /* G22.S25 — "Gruppi di condivisione" vive solo in dashboard pool tab. */ ?>
</main>

<style>
    .fm-curr-table { width: 100%; border-collapse: collapse; margin-top: 10px; font-size: 0.8125rem; }
    .fm-curr-table th, .fm-curr-table td { padding: 6px 8px; border-bottom: 1px solid rgba(0,0,0,0.08); text-align: left; }
    .fm-curr-table th { background: rgba(0,0,0,0.04); font-weight: 600; }
    .fm-curr-table input[type="text"] { width: 100%; box-sizing: border-box; padding: 4px 6px; }
    .fm-curr-form { display: grid; grid-template-columns: 1fr 2fr 1fr 60px 100px; gap: 6px; align-items: center; margin: 8px 0; padding: 8px; background: rgba(0,0,0,0.03); border-radius: 4px; }
    body.fm-dark .fm-curr-table th { background: rgba(255,255,255,0.05); }
    body.fm-dark .fm-curr-form    { background: rgba(255,255,255,0.05); }

    /* Autocomplete istituti — dark-aware via tokens, WCAG AA. */
    .fm-ac { position: relative; flex: 1 1 320px; min-width: 220px; }
    .fm-ac > input { width: 100%; box-sizing: border-box; }
    .fm-ac__list {
        position: absolute; z-index: 50; left: 0; right: 0; top: calc(100% + 4px);
        margin: 0; padding: 4px; list-style: none; max-height: 280px; overflow-y: auto;
        background: var(--fm-c-surface, #fff); color: var(--fm-c-text, #1f2937);
        border: 1px solid var(--fm-c-border, #d9dee6); border-radius: 10px;
        box-shadow: 0 12px 32px rgba(0,0,0,.18);
    }
    .fm-ac__item {
        display: flex; flex-direction: column; gap: 2px; padding: 8px 10px;
        border-radius: 7px; cursor: pointer; line-height: 1.25;
    }
    .fm-ac__item:hover,
    .fm-ac__item--active { background: var(--fm-c-primary-light, #e0ecf9); }
    .fm-ac__item--active { outline: 2px solid var(--fm-c-primary, #0b5fd1); outline-offset: -2px; }
    .fm-ac__label { font-weight: 600; font-size: .875rem; }
    .fm-ac__meta  { font-size: .75rem; color: var(--fm-c-text-2, #4b5563); font-variant-numeric: tabular-nums; }
    .fm-ac__note  { color: var(--fm-c-accent, #2a9d8f); font-weight: 600; }
</style>

<script type="module">
    import { notify } from "/js/modules/ui/sync-panel.js";
    // Centralizzazione (anti-duplicazione + gestione WAF challenge unica):
    // fetchJson/fetchCsrf/FetchJsonError vivono in dom-utils. assertJson
    // riconosce il waf_challenge (JSON o HTML) e auto-ricarica per rinnovare
    // il cookie waf_session; qui ci limitiamo a notificare l'errore.
    import { fetchJson, fetchCsrf, FetchJsonError } from "/js/modules/core/dom-utils.js";
    import { attachAutocomplete } from "/js/modules/ui/autocomplete.js";

    let allInstitutes = [];
    let activeInstituteId = null; // settato da loadCurrent
    let linkedCodes = new Set();  // codici istituto già collegati (annotati nei suggerimenti)
    let selectedInstitute = null; // ultima voce scelta dall'autocomplete
    let instituteAC = null;       // handle autocomplete (refresh on data change)

    const getCsrf = fetchCsrf;

    /** Notifica leggibile per gli errori di rete/JSON (incl. WAF challenge). */
    function notifyFetchError(scope, e) {
        const msg = (e instanceof FetchJsonError)
            ? e.message
            : (e && e.message) ? e.message : "Errore di rete";
        notify(scope, "error", msg, e?.code === "waf_challenge" ? 4000 : 0);
    }

    /**
     * Conferma inline 2-step (memory rule: no browser confirm()).
     * Bottone passa a stato "⚠ Conferma?" per `timeoutMs`, secondo click esegue.
     */
    function confirmInline(btn, action, timeoutMs = 4000) {
        if (btn.dataset.confirmPending === "1") return;
        const orig = btn.innerHTML;
        const cls = btn.className;
        btn.dataset.confirmPending = "1";
        btn.innerHTML = "⚠ Conferma?";
        btn.classList.add("fm-btn--danger");
        const handler = (e) => {
            e.preventDefault();
            cleanup();
            action();
        };
        const cleanup = () => {
            btn.removeEventListener("click", handler);
            btn.innerHTML = orig;
            btn.className = cls;
            delete btn.dataset.confirmPending;
        };
        btn.addEventListener("click", handler, { once: true });
        setTimeout(cleanup, timeoutMs);
    }

    async function loadCurrent() {
        let j;
        try { j = await fetchJson("/api/teacher/institutes"); }
        catch (e) { notifyFetchError("Istituti", e); return; }
        const ul = document.getElementById("fm-profile-current");
        const list = j.institutes || [];
        // Codici già collegati → annotati nel datalist di ricerca (evita di
        // "ricollegare" la stessa scuola che appare sotto un'altra etichetta).
        linkedCodes = new Set(list.map(i => i.code));
        renderSuggestions();
        if (!list.length) {
            ul.innerHTML = '<p class="fm-muted">Nessun istituto collegato. Aggiungine uno qui sotto.</p>';
            activeInstituteId = null;
            return;
        }
        // Istituto attivo: STESSA fonte della sidebar, così profilo ed editor
        // mostrano sempre lo stesso istituto.
        //  1. sessionStorage.activeInstituteCode (settato da wireIstitutoSelector
        //     quando l'utente cambia istituto dal dropdown);
        //  2. valore corrente del dropdown #sel-istituto (reso server-side con
        //     l'opzione `selected` = currentInstituteId di sessione) — copre il
        //     caso sessionStorage vuoto, in cui prima si cadeva su list[0]
        //     (primo istituto collegato), divergendo da ciò che la sidebar mostra;
        //  3. primo istituto collegato come ultimo fallback.
        const selIst = document.getElementById("sel-istituto");
        const activeCode = sessionStorage.getItem("activeInstituteCode")
            || (selIst ? selIst.value : "")
            || "";
        const activeRow = list.find(i => i.code === activeCode) || list[0];
        activeInstituteId = activeRow ? activeRow.id : null;
        // Mostra ESPLICITAMENTE quale istituto è attivo (la causa principale
        // della confusione era che il pannello non lo diceva).
        const instNameEl = document.getElementById("fm-curr-active-inst");
        if (instNameEl) {
            instNameEl.innerHTML = activeRow
                ? `📍 Stai gestendo: <strong>${escapeHtml(activeRow.name || activeRow.header_label || "")}</strong> <code class="fm-mono-muted fm-text-11">${escapeHtml(activeRow.code || "")}</code> — per un altro istituto, cambialo dal menu <em>Istituto</em> nella sidebar.`
                : "Nessun istituto attivo.";
        }
        ul.innerHTML = `
            <table class="fm-data-table">
                <thead>
                    <tr>
                        <th scope="col">Codice</th>
                        <th scope="col">Nome</th>
                        <th scope="col">Citta'</th>
                        <th scope="col" class="fm-text-center">Stato</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                ${list.map(i => `
                    <tr>
                        <td class="fm-mono-muted fm-font-mono fm-text-xs" >${escapeHtml(i.code)}</td>
                        <td><strong>${escapeHtml(i.name || i.header_label)}</strong></td>
                        <td>${escapeHtml(i.city || '—')}</td>
                        <td class="fm-text-center">
                            ${i.id === activeInstituteId ? '<span class="fm-badge--active">● attivo</span>' : ''}
                        </td>
                        <td class="fm-text-right">
                            <button class="fm-btn fm-btn--xs fm-btn--danger" data-unlink-id="${i.id}" data-unlink-name="${escapeHtml(i.name)}">🗑 Rimuovi</button>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table>
        `;
        ul.querySelectorAll("[data-unlink-id]").forEach(b => {
            b.addEventListener("click", () => {
                confirmInline(b, () =>
                    unlinkInstitute(parseInt(b.dataset.unlinkId, 10), b.dataset.unlinkName));
            });
        });
    }

    async function loadAllInstitutes() {
        let j;
        try { j = await fetchJson("/api/institutes"); }
        catch (e) { notifyFetchError("Istituti", e); return; }
        allInstitutes = j.institutes || [];
        renderSuggestions();
    }

    /**
     * Autocomplete custom (sostituisce il <datalist> nativo: non stilabile né
     * a tema dark). Mostra il NOME ufficiale MIUR (più chiaro e disambiguante
     * dell'header_label, che è un'etichetta per i documenti), col codice
     * meccanografico + città come meta e il marcatore "✓ già collegato".
     */
    function renderSuggestions() {
        const input = document.getElementById("fm-profile-search");
        if (!input) return;
        if (!instituteAC) {
            instituteAC = attachAutocomplete(input, {
                items: () => allInstitutes,
                getLabel: (i) => i.name,
                getMeta:  (i) => [i.code, i.city].filter(Boolean).join(" · "),
                getNote:  (i) => linkedCodes.has(i.code) ? "✓ già collegato" : "",
                onSelect: (i) => { selectedInstitute = i; },
                minChars: 2,
            });
        } else {
            instituteAC.refresh();
        }
    }

    async function addInstitute() {
        const input = document.getElementById("fm-profile-search");
        const fb = document.getElementById("fm-profile-add-feedback");
        const v = input.value.trim();
        const vl = v.toLowerCase();
        // Preferisci la voce scelta dall'autocomplete; altrimenti risolvi per
        // nome esatto, poi codice meccanografico, poi header_label.
        let inst = (selectedInstitute && selectedInstitute.name === v) ? selectedInstitute : null;
        if (!inst) {
            inst = allInstitutes.find(i => (i.name || "").toLowerCase() === vl)
                || allInstitutes.find(i => (i.code || "").toLowerCase() === vl)
                || allInstitutes.find(i => (i.header_label || "").toLowerCase() === vl);
        }
        if (!inst) {
            fb.style.color = "#b91c1c";
            fb.textContent = "Istituto non trovato. Scegli da elenco autocomplete.";
            return;
        }
        const instLabel = inst.name || inst.header_label;
        // Già collegato (anche se appare sotto altra etichetta): niente POST inutile.
        if (linkedCodes.has(inst.code)) {
            fb.style.color = "#b45309";
            fb.textContent = `Già collegato: ${instLabel}`;
            return;
        }
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("institute_id", String(inst.id));
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson("/api/teacher/institutes/link", {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) {
            fb.style.color = "#b91c1c";
            fb.textContent = (e instanceof FetchJsonError) ? e.message : "Errore di rete";
            return;
        }
        if (j.ok && j.already_linked) {
            // Il server conferma: nessuna nuova riga (stessa scuola già presente).
            fb.style.color = "#b45309";
            fb.textContent = `Già collegato: ${instLabel}`;
            await loadCurrent();
        } else if (j.ok) {
            fb.style.color = "#15803d";
            fb.textContent = `✓ Collegato: ${instLabel}`;
            input.value = "";
            selectedInstitute = null;
            await Promise.all([loadCurrent(), loadCurriculumAndPivot()]);
        } else {
            fb.style.color = "#b91c1c";
            const human = j.error === "institute_inactive"
                ? "Istituto non attivo (probabile duplicato unito a un altro). Non collegabile."
                : (j.error || "richiesta non riuscita");
            fb.textContent = `Errore: ${human}`;
        }
    }

    async function unlinkInstitute(id, name) {
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson(`/api/teacher/institutes/${id}/unlink`, {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) { notifyFetchError("Istituti", e); return; }
        if (j.ok) {
            notify("Istituti", "ok", `🔓 Scollegato: ${name}`, 3000);
            await Promise.all([loadCurrent(), loadCurriculumAndPivot()]);
        } else {
            notify("Istituti", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        }
    }

    /* ──────────────── Curriculum dell'istituto attivo (editor) ──────────────── */

    // skipEditor=true → NON ri-renderizza la tabella-editor: usato dopo il save
    // di UNA riga, così le modifiche non salvate digitate nelle ALTRE righe non
    // vengono scartate (in quel caso aggiorniamo solo i selettori sidebar).
    async function loadCurriculumAndPivot({ skipEditor = false } = {}) {
        const panels = Object.fromEntries(
            Array.from(document.querySelectorAll(".fm-curr-panel"))
                .map((p) => [p.dataset.panel, p])
        );
        const setAllPanels = (html) => {
            for (const k of ["indirizzi", "classi", "materie"]) {
                if (panels[k]) panels[k].innerHTML = html;
            }
        };
        if (!activeInstituteId) {
            setAllPanels('<p class="fm-muted">Collega prima un istituto per vedere il curriculum.</p>');
            return;
        }

        // Catalog dell'istituto attivo, filtrato per owner=teacher.
        // include_inactive=1: l'editor deve mostrare anche le voci con "Attiva"
        // off (altrimenti deselezionandola la riga sparisce e non è riattivabile).
        const qs = `?institute_id=${encodeURIComponent(activeInstituteId)}&include_inactive=1`;
        let cat;
        try {
            cat = await fetchJson("/api/teacher/curriculum" + qs);
        } catch (e) {
            const m = (e instanceof FetchJsonError) ? e.message : "errore di rete";
            setAllPanels(`<p class="fm-muted">Errore catalog: ${escapeHtml(m)}</p>`);
            return;
        }
        if (!cat.ok) { setAllPanels(`<p class="fm-muted">Errore catalog: ${escapeHtml(cat.error || "?")}</p>`); return; }

        // Render catalog editor (for current visible kind). Saltato dopo il save
        // di una singola riga per non azzerare le modifiche in corso nelle altre.
        if (!skipEditor) {
            for (const kind of ["indirizzi", "classi", "materie"]) {
                renderCatalogPanel(kind, cat.curriculum[kind] || [], panels[kind]);
            }
        }
    }

    /**
     * G22.S22 — Re-popola #sel-iis / #sel-cls / #sel-mater. Tutti i kind
     * sono per-docente (owner-based): il server scope direttamente le
     * entries del docente nell'istituto attivo, niente filtro pivot.
     * Chiamato dopo add/update/remove entries e toggle share.
     */
    async function refreshSidebarSelects() {
        if (!activeInstituteId) return;
        const qs = `?institute_id=${encodeURIComponent(activeInstituteId)}`;
        let cat;
        try { cat = await fetchJson("/api/teacher/curriculum" + qs); }
        catch (e) { console.warn("[sidebar] refresh:", e); return; }
        if (!cat.ok) return;

        const filtered = {};
        for (const k of ["indirizzi", "classi", "materie"]) {
            filtered[k] = (cat.curriculum[k] || []).filter(e => e.active);
        }

        const renderGroupedSelect = (selectId, entries, placeholder) => {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const currentVal = sel.value;
            const groups = {};
            for (const e of entries) {
                const g = (e.group || "").trim() || "Altri";
                (groups[g] ??= []).push(e);
            }
            const opts = [`<option disabled>${escapeHtml(placeholder)}</option>`];
            for (const [gname, rows] of Object.entries(groups)) {
                opts.push(`<optgroup label="${escapeHtml(gname)}">`);
                for (const r of rows) {
                    opts.push(`<option value="${escapeHtml(r.code)}">${escapeHtml(r.label)}</option>`);
                }
                opts.push(`</optgroup>`);
            }
            sel.innerHTML = opts.join("");
            // Restore selection se il code esiste ancora.
            if (currentVal && Array.from(sel.options).some(o => o.value === currentVal)) {
                sel.value = currentVal;
            }
        };
        const renderFlatSelect = (selectId, entries, placeholder) => {
            const sel = document.getElementById(selectId);
            if (!sel) return;
            const currentVal = sel.value;
            const opts = [`<option disabled>${escapeHtml(placeholder)}</option>`];
            for (const e of entries) {
                opts.push(`<option value="${escapeHtml(e.code)}">${escapeHtml(e.label)}</option>`);
            }
            sel.innerHTML = opts.join("");
            if (currentVal && Array.from(sel.options).some(o => o.value === currentVal)) {
                sel.value = currentVal;
            }
        };

        renderGroupedSelect("sel-iis", filtered.indirizzi, "Scegli l'indirizzo:");
        renderGroupedSelect("sel-cls", filtered.classi,    "Scegli la classe:");
        renderFlatSelect("sel-mater",  filtered.materie,   "Materia:");
    }

    function renderCatalogPanel(kind, entries, panelEl) {
        if (!panelEl) return;
        const ownEntries = entries.filter(e => !e.is_legacy);
        const legacyEntries = entries.filter(e => e.is_legacy);
        const codeHint = {
            indirizzi: "es. SCI, ART, LIN, AFM (3-6 lettere uppercase)",
            classi:    "es. 1, 2, 3, 4, 5 oppure 1B, 2B (numero + suffix opzionale)",
            materie:   "es. MAT, FIS, ITA, STO (3-6 lettere uppercase)",
        }[kind] || "Codice";
        const codePattern = kind === "classi" ? "[1-9][A-Z0-9]{0,3}" : "[A-Z]{3,6}";
        panelEl.innerHTML = `
            <div class="fm-curr-form" data-add-form data-kind="${kind}">
                <input type="text" name="code" placeholder="${codeHint}" maxlength="16" pattern="${codePattern}" required>
                <input type="text" name="label" placeholder="Etichetta visibile" maxlength="120" required>
                <input type="text" name="group" placeholder="${kind === "materie" ? "—" : "Gruppo (opzionale)"}" maxlength="60" ${kind === "materie" ? "disabled" : ""}>
                <label class="fm-d-flex fm-gap-1 fm-items-center fm-text-xs"><input type="checkbox" name="active" checked> attivo</label>
                <button type="button" class="fm-btn fm-btn--primary fm-btn--sm" data-add-btn>➕ Aggiungi</button>
            </div>
            <table class="fm-curr-table">
                <thead><tr>
                    <th scope="col" class="fm-w-20">Codice</th>
                    <th scope="col">Etichetta</th>
                    <th scope="col" class="fm-w-30">Gruppo</th>
                    <th scope="col" class="fm-w-19 fm-text-center" title="Se disattivata, non appare nei select del sito (verifiche, mappe, esercizi).">Attiva</th>
                    ${kind === "materie" ? '<th scope="col" class="fm-w-23 fm-text-center" title="Se attiva, i tuoi contenuti di questa materia compaiono nella sezione \\"Recupera materiali\\" della dashboard degli altri docenti del tuo istituto.">Condivisa</th>' : ""}
                    <th scope="col" class="fm-w-20"></th>
                </tr></thead>
                <tbody>
                ${ownEntries.map(e => entryRow(e, false, kind)).join("")}
                ${legacyEntries.length ? `
                    <tr><td colspan="${kind === "materie" ? 6 : 5}" class="fm-muted fm-text-11 fm-pt-3" >— Entries legacy globali (read-only, condivise tra istituti) —</td></tr>
                    ${legacyEntries.map(e => entryRow(e, true, kind)).join("")}` : ""}
                </tbody>
            </table>
        `;
        panelEl.querySelector("[data-add-btn]").addEventListener("click", () => addEntry(kind, panelEl));
        panelEl.querySelectorAll("[data-update-id]").forEach(b => {
            b.addEventListener("click", () => updateEntry(parseInt(b.dataset.updateId, 10), b));
        });
        panelEl.querySelectorAll("[data-remove-id]").forEach(b => {
            b.addEventListener("click", () => confirmInline(b, () => removeEntry(parseInt(b.dataset.removeId, 10))));
        });
        // G22.S21 — toggle shared_with_pool per-materia (autosave on change).
        panelEl.querySelectorAll("[data-shared-id]").forEach(cb => {
            cb.addEventListener("change", () => toggleShare(parseInt(cb.dataset.sharedId, 10), cb.checked, cb));
        });
    }

    function entryRow(e, readonly = false, kind = null) {
        const lockAttr = readonly ? "disabled title=\"Entry legacy: solo admin\"" : "";
        const shareCell = kind === "materie"
            ? `<td class="fm-text-center">
                   <input type="checkbox" data-shared-id="${e.id}"
                          ${e.shared_with_pool ? "checked" : ""} ${lockAttr}
                          title="Condividi nel pool dell'istituto">
               </td>`
            : "";
        return `
            <tr data-entry-row="${e.id}">
                <td><code>${escapeHtml(e.code)}</code></td>
                <td><input type="text" name="label" value="${escapeHtml(e.label)}" maxlength="120" ${lockAttr}></td>
                <td><input type="text" name="group" value="${escapeHtml(e.group || "")}" maxlength="60" ${lockAttr}></td>
                <td class="fm-text-center"><input type="checkbox" name="active" ${e.active ? "checked" : ""} ${lockAttr}></td>
                ${shareCell}
                <td class="fm-text-right">
                    ${readonly ? "" : `
                        <div style="display:flex;gap:6px;flex-wrap:nowrap;justify-content:flex-end;align-items:center">
                            <button class="fm-btn fm-btn--xs" data-update-id="${e.id}" title="Salva" aria-label="Salva">💾</button>
                            <button class="fm-btn fm-btn--xs fm-btn--danger" data-remove-id="${e.id}" title="Rimuovi" aria-label="Rimuovi">🗑</button>
                        </div>
                    `}
                </td>
            </tr>`;
    }

    async function toggleShare(entryId, enabled, cb) {
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("shared_with_pool", enabled ? "true" : "false");
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson(`/api/teacher/curriculum/${entryId}/update`, {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) { cb.checked = !enabled; notifyFetchError("Curriculum", e); return; }
        if (j.ok) {
            notify("Curriculum", "ok", enabled ? "✓ Materia condivisa nel pool" : "✓ Condivisione disattivata", 2500);
            // Autosave di UNA riga: aggiorna pannello "Le mie..." (badge 🤝 pool)
            // + sidebar senza ri-renderizzare l'editor (no perdita modifiche altrove).
            await loadCurriculumAndPivot({ skipEditor: true });
            await refreshSidebarSelects();
        } else {
            cb.checked = !enabled; // rollback UI
            notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        }
    }

    async function addEntry(kind, panelEl) {
        const form = panelEl.querySelector("[data-add-form]");
        const code  = form.querySelector("[name=code]").value.trim();
        const label = form.querySelector("[name=label]").value.trim();
        const group = form.querySelector("[name=group]").value.trim();
        const active = form.querySelector("[name=active]").checked;
        if (!code || !label) {
            notify("Curriculum", "error", "Code e Label sono obbligatori", 3000);
            return;
        }
        if (!activeInstituteId) {
            notify("Curriculum", "error", "Nessun istituto attivo", 3000);
            return;
        }
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("code", code);
        fd.set("label", label);
        fd.set("group", group);
        fd.set("active", active ? "true" : "false");
        fd.set("institute_id", String(activeInstituteId));
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson(`/api/teacher/curriculum/${kind}`, {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) { notifyFetchError("Curriculum", e); return; }
        if (j.ok) {
            notify("Curriculum", "ok", `✓ Aggiunta: ${label}`, 2500);
            form.querySelector("[name=code]").value = "";
            form.querySelector("[name=label]").value = "";
            form.querySelector("[name=group]").value = "";
            // G22.S22 — refresh primo pannello + sel-wrapper sidebar.
            await loadCurriculumAndPivot();
            await refreshSidebarSelects();
        } else {
            notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        }
    }

    async function updateEntry(entryId, btn) {
        const tr = btn.closest("tr");
        const label = tr.querySelector("[name=label]").value.trim();
        const group = tr.querySelector("[name=group]").value.trim();
        const active = tr.querySelector("[name=active]").checked;
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("label", label);
        fd.set("group", group);
        fd.set("active", active ? "true" : "false");
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson(`/api/teacher/curriculum/${entryId}/update`, {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) { notifyFetchError("Curriculum", e); return; }
        if (j.ok) {
            notify("Curriculum", "ok", `✓ Aggiornata`, 2000);
            // Save di UNA riga: aggiorna pannello "Le mie..." + sidebar, MA non
            // ri-renderizza la tabella editor (skipEditor) → non scarta le
            // modifiche non salvate nelle altre righe.
            await loadCurriculumAndPivot({ skipEditor: true });
            await refreshSidebarSelects();
        } else {
            notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        }
    }

    async function removeEntry(entryId) {
        const csrf = await getCsrf();
        const fd = new FormData();
        fd.set("_csrf", csrf);
        let j;
        try {
            j = await fetchJson(`/api/teacher/curriculum/${entryId}/remove`, {
                method: "POST", headers: { "X-CSRF-Token": csrf }, body: fd,
            });
        } catch (e) { notifyFetchError("Curriculum", e); return; }
        if (j.ok) {
            notify("Curriculum", "ok", "🗑 Rimossa", 2000);
            // G22.S22 — refresh primo pannello + sel-wrapper sidebar.
            await loadCurriculumAndPivot();
            await refreshSidebarSelects();
        } else {
            notify("Curriculum", "error", `Errore: ${j.error || "richiesta non riuscita"}`, 0);
        }
    }

    /* Tab switching curriculum — pattern ARIA tabs (WCAG 4.1.2): aria-selected,
       roving tabindex, attivazione click + frecce ←/→/Home/Fine. */
    const _currTablist = document.getElementById("fm-curr-tabs");
    function activateCurrTab(btn, focus = false) {
        if (!btn) return;
        const kind = btn.dataset.kind;
        _currTablist.querySelectorAll(".fm-subtab").forEach(b => {
            const on = b === btn;
            b.classList.toggle("fm-subtab--active", on);
            b.setAttribute("aria-selected", on ? "true" : "false");
            b.tabIndex = on ? 0 : -1;   // roving tabindex
        });
        document.querySelectorAll(".fm-curr-panel").forEach(p => {
            // fm-d-none vive in @utilities (display:none, no !important):
            // impostare style.display="" NON basta perché la classe resta e
            // continua a nascondere il pannello. Toggle della classe.
            p.classList.toggle("fm-d-none", p.dataset.panel !== kind);
            p.style.display = "";
        });
        if (focus) btn.focus();
    }
    _currTablist.addEventListener("click", (e) => {
        const btn = e.target.closest(".fm-subtab");
        if (btn) activateCurrTab(btn);
    });
    _currTablist.addEventListener("keydown", (e) => {
        const tabs = Array.from(_currTablist.querySelectorAll(".fm-subtab"));
        const i = tabs.indexOf(document.activeElement);
        if (i < 0) return;
        let j = null;
        if (e.key === "ArrowRight" || e.key === "ArrowDown") j = (i + 1) % tabs.length;
        else if (e.key === "ArrowLeft" || e.key === "ArrowUp") j = (i - 1 + tabs.length) % tabs.length;
        else if (e.key === "Home") j = 0;
        else if (e.key === "End") j = tabs.length - 1;
        if (j !== null) { e.preventDefault(); activateCurrTab(tabs[j], true); }
    });

    function escapeHtml(s) {
        return String(s ?? "").replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
    }

    document.getElementById("fm-profile-add-btn").addEventListener("click", addInstitute);
    document.getElementById("fm-profile-search").addEventListener("keydown", e => {
        if (e.key === "Enter") { e.preventDefault(); addInstitute(); }
    });

    await Promise.all([loadCurrent(), loadAllInstitutes()]);
    await loadCurriculumAndPivot();

    // G22.S20 v2.C2 — Refresh tabella + pivot quando l'utente cambia
    // istituto attivo dalla sidebar (event globale da AppState wireIstitutoSelector).
    document.addEventListener("fm:active-institute-changed", async (ev) => {
        // 1) Feedback ISTANTANEO della label "istituto attivo" dal dettaglio
        //    evento (code) + l'elenco istituti già caricato, senza attendere la
        //    rete: l'utente vede subito riflesso il cambio di selettore.
        const code = ev?.detail?.code || "";
        const row = allInstitutes.find(i => i.code === code);
        const el = document.getElementById("fm-curr-active-inst");
        if (el && row) {
            el.innerHTML = `📍 Stai gestendo: <strong>${escapeHtml(row.name || row.header_label || "")}</strong> <code class="fm-mono-muted fm-text-11">${escapeHtml(row.code || "")}</code> — per un altro istituto, cambialo dal menu <em>Istituto</em> nella sidebar.`;
        }
        // 2) Ricarica SEQUENZIALE: loadCurrent aggiorna activeInstituteId (+ label
        //    definitiva), POI loadCurriculumAndPivot ricarica i pannelli col NUOVO
        //    istituto. In parallelo (Promise.all) loadCurriculumAndPivot leggeva
        //    activeInstituteId ancora vecchio → race, pannelli stantii.
        await loadCurrent();
        await loadCurriculumAndPivot();
    });

    // G22.S25 — Gestione gruppi spostata in Dashboard pool tab (vedi dashboard.php).
</script>

<?php
$pageContent = ob_get_clean();
$_pantedu_base = $_pantedu_base ?? dirname(__DIR__, 2);
include $_pantedu_base . '/views/layout/app.php';
