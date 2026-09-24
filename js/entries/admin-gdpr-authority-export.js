// Entry Vite di views/admin/gdpr_authority_export.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { escHtml as fmEscapeHtml } from "../modules/core/dom-utils.js";

// ── blocco 1 ──
{
// Aggrega checkbox event_types_arr[] in CSV event_types prima del submit (compat backend)
document.getElementById('fm-auth-export-form').addEventListener('submit', () => {
    const checked = Array.from(document.querySelectorAll('input[name="event_types_arr[]"]:checked'))
        .map(el => el.value);
    document.getElementById('fm-event-types-csv').value = checked.join(',');
});

// ─── Content search panel (Phase 25.R.24) ──────────────────────
function fmContentSearchOpen() {
    document.getElementById('fm-content-search-panel').style.display = 'block';
    document.getElementById('fm-cs-q').focus();
}
function fmContentSearchClose() {
    document.getElementById('fm-content-search-panel').style.display = 'none';
}
async function fmContentSearchRun() {
    const tidSel = document.querySelector('select[name="teacher_id"], input[name="teacher_id"]');
    const tid = tidSel ? tidSel.value.trim() : '';
    const results = document.getElementById('fm-cs-results');
    if (!tid) {
        results.innerHTML = '<span class="fm-text-danger">⚠️ Seleziona prima un docente nel campo "Teacher ID specifico".</span>';
        return;
    }
    const q = document.getElementById('fm-cs-q').value.trim();
    const type = document.getElementById('fm-cs-type').value;
    const url = `/api/admin/gdpr/teacher-content-search?teacher_id=${  encodeURIComponent(tid)
               }${q !== '' ? `&q=${  encodeURIComponent(q)}` : ''
               }${type !== '' ? `&type=${  encodeURIComponent(type)}` : ''}`;
    results.innerHTML = '⏳ Ricerca in corso…';
    try {
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } });
        const j = await res.json();
        if (!j.ok) {
            results.innerHTML = `<span class="fm-text-danger">✗ Errore: ${  fmEscapeHtml(j.error || '')  }</span>`;
            return;
        }
        if (!j.rows || j.rows.length === 0) {
            results.innerHTML = '<em>Nessun contenuto trovato con questi filtri.</em>';
            return;
        }
        // Render table con checkbox
        let html = `<div class="fm-mb-1">Trovati <strong>${  j.count  }</strong> contenuti `
                 + `(max 200 visualizzati). Spunta quelli da includere:</div>`;
        html += '<div class="fm-scroll-panel">';
        html += '<table class="fm-waf-table fm-w-full fm-text-em-md fm-m-0" >';
        html += '<thead class="fm-sticky-top">'
              + '<tr><th scope="col"></th><th scope="col">ID</th><th scope="col">Tipo</th><th scope="col">Titolo</th><th scope="col">Materia/Classe</th><th scope="col">Data</th></tr>'
              + '</thead><tbody>';
        for (const r of j.rows) {
            const subj = [r.subject_code, r.indirizzo, r.classe].filter(Boolean).join(' · ');
            html += `<tr>`
                  + `<td><input type="checkbox" class="fm-cs-cb" data-id="${  r.id  }"></td>`
                  + `<td><code>${  r.id  }</code></td>`
                  + `<td>${  fmEscapeHtml(r.content_type)  }</td>`
                  + `<td>${  fmEscapeHtml(r.title || r.topic || '(no title)')  }</td>`
                  + `<td><small>${  fmEscapeHtml(subj)  }</small></td>`
                  + `<td><small>${  fmEscapeHtml((r.created_at||'').substring(0,10))  }</small></td>`
                  + `</tr>`;
        }
        html += '</tbody></table></div>';
        html += '<div class="fm-d-flex fm-gap-2 fm-mt-2 fm-items-center">'
              + '<button type="button" class="fm-btn fm-btn--primary fm-btn--sm" data-cs-act="apply">📥 Aggiungi ID selezionati al wizard</button>'
              + '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-cs-act="all">Seleziona tutti</button>'
              + '<button type="button" class="fm-btn fm-btn--ghost fm-btn--sm" data-cs-act="none">Deseleziona tutti</button>'
              + '</div>';
        results.innerHTML = html;
    } catch (e) {
        results.innerHTML = `<span class="fm-text-danger">✗ Errore di rete: ${  fmEscapeHtml(e.message)  }</span>`;
    }
}
function fmContentSearchSelectAll() {
    document.querySelectorAll('.fm-cs-cb').forEach(cb => cb.checked = true);
}
function fmContentSearchSelectNone() {
    document.querySelectorAll('.fm-cs-cb').forEach(cb => cb.checked = false);
}
function fmContentSearchApply() {
    const ids = Array.from(document.querySelectorAll('.fm-cs-cb:checked')).map(cb => cb.dataset.id);
    if (ids.length === 0) {
        alert('Nessun contenuto selezionato. Spunta almeno una riga.');
        return;
    }
    const field = document.getElementById('fm-content-ids');
    const existing = field.value.trim();
    const existingIds = existing ? existing.split(',').map(s => s.trim()).filter(Boolean) : [];
    const merged = Array.from(new Set([...existingIds, ...ids])); // dedup
    field.value = merged.join(', ');
    // Highlight visivo per dare feedback
    field.style.transition = 'background .3s';
    field.style.background = 'rgba(34,197,94,.15)';
    setTimeout(() => field.style.background = '', 600);
}

// CSP strict: delega i click dei bottoni generati in innerHTML (no inline onclick).
document.getElementById('fm-cs-results')?.addEventListener('click', (e) => {
    const b = e.target.closest('[data-cs-act]');
    if (!b) return;
    if (b.dataset.csAct === 'apply') fmContentSearchApply();
    else if (b.dataset.csAct === 'all') fmContentSearchSelectAll();
    else if (b.dataset.csAct === 'none') fmContentSearchSelectNone();
});
// ── azioni dichiarate nella vista con data-fm-action (core/declarative.js) ──
window.FM = window.FM || {};
window.FM.pageActions = Object.assign(window.FM.pageActions || {}, {
    fmContentSearchOpen: (e, el) => fmContentSearchOpen(e, el),
    fmContentSearchRun: (e, el) => fmContentSearchRun(e, el),
    fmContentSearchClose: (e, el) => fmContentSearchClose(e, el),
});

}
