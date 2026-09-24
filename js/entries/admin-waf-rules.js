// Entry Vite di views/admin/waf/rules.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { auditReason } from "../modules/core/audit-reason.js";

// ── blocco 1 ──
{
const CSRF = document.getElementById("fm-page-config")?.dataset.csrf ?? "";

// `reason` finisce in X-Audit-Reason e da lì in privileged_access_log: le
// mutazioni WAF sono decisioni di sicurezza, e il registro deve dire quale.
async function apiCall(method, url, body, reason) {
    const opts = {
        method,
        headers: {
            'X-CSRF-Token': CSRF,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
            'X-Audit-Reason': reason || 'Modifica regole WAF dal pannello'
        },
        credentials: 'same-origin'
    };
    if (body) {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(url, opts);
    return res.json();
}

async function createRule(e) {
    e.preventDefault();
    const f = e.target;
    const data = Object.fromEntries(new FormData(f));
    try {
        data.conditions = JSON.parse(data.conditions || '{}');
    } catch (err) {
        document.getElementById('fm-rule-status').textContent = '✗ JSON malformato';
        document.getElementById('fm-rule-status').style.color = '#b91c1c';
        return false;
    }
    // auditReason: il nome della regola lo scrive l'amministratore, e un
    // carattere oltre l'ISO-8859-1 farebbe fallire l'intestazione nel browser.
    const r = await apiCall('POST', '/admin/waf/api/rules', data,
        auditReason('Nuova regola WAF', data.name || data.rule_type || 'senza nome'));
    if (r.ok) {
        location.reload();
    } else {
        document.getElementById('fm-rule-status').textContent = `✗ ${  r.error || 'errore'}`;
        document.getElementById('fm-rule-status').style.color = '#b91c1c';
    }
    return false;
}

async function toggleRule(id) {
    const r = await apiCall('POST', `/admin/waf/api/rules/${  id  }/toggle`, null,
        auditReason(`Attivazione/disattivazione regola WAF #${  id}`));
    if (r.ok) location.reload();
}

async function deleteRule(id) {
    if (!confirm(`Eliminare la regola #${  id  }?`)) return;
    const r = await apiCall('DELETE', `/admin/waf/api/rules/${  id}`, null,
        auditReason(`Eliminazione regola WAF #${  id}, confermata`));
    if (r.ok) location.reload();
}
// ── azioni dichiarate nella vista con data-fm-action (core/declarative.js) ──
window.FM = window.FM || {};
window.FM.pageActions = Object.assign(window.FM.pageActions || {}, {
    createRule: (e, el) => createRule(e, el),
    toggleRule: (e, el) => toggleRule(Number(el.dataset.fmId)),
    deleteRule: (e, el) => deleteRule(Number(el.dataset.fmId)),
});

}
