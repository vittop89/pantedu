// Entry Vite di views/admin/institutes_index.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { auditReason } from "../modules/core/audit-reason.js";

// ── blocco 1 ──
{
async function miurUpdate(e) {
    e.preventDefault();
    const form = e.target;
    const hasStatali = form.statali_file.files.length > 0;
    const hasParitarie = form.paritarie_file.files.length > 0;
    const status = document.getElementById('fm-miur-status');
    if (!hasStatali && !hasParitarie) {
        status.textContent = '✗ Seleziona almeno un file JSON';
        status.className = 'fm-inline-status fm-inline-status--error';
        return false;
    }
    const btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    status.textContent = '⏳ Caricamento e indicizzazione…';
    status.className = 'fm-inline-status';
    try {
        const fd = new FormData(form);
        const res = await fetch('/admin/institutes/miur/update', {
            method: 'POST',
            body: fd,
            // 23/9/2026 (A-69) — la motivazione, come ogni mutazione del pannello.
            headers: {
                'X-CSRF-Token': form._csrf.value, 'X-Requested-With': 'XMLHttpRequest',
                'X-Audit-Reason': auditReason("Aggiornamento dell'anagrafe MIUR delle scuole dal pannello Istituti"),
            },
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (res.ok && json.ok) {
            status.textContent = `✓ Aggiornato: ${  json.records.toLocaleString('it-IT')  } scuole indicizzate`;
            status.className = 'fm-inline-status fm-inline-status--ok';
            setTimeout(() => location.reload(), 1500);
        } else {
            status.textContent = `✗ Errore: ${  json.error || res.status 
                }${json.detail ? ` — ${  json.detail}` : ''  }${json.field ? ` [${  json.field  }]` : ''}`;
            status.className = 'fm-inline-status fm-inline-status--error';
            btn.disabled = false;
        }
    } catch (err) {
        status.textContent = `✗ Errore: ${  err.message}`;
        status.className = 'fm-inline-status fm-inline-status--error';
        btn.disabled = false;
    }
    return false;
}
// ── azioni dichiarate nella vista con data-fm-action (core/declarative.js) ──
window.FM = window.FM || {};
window.FM.pageActions = Object.assign(window.FM.pageActions || {}, {
    miurUpdate: (e, el) => miurUpdate(e, el),
});

}
