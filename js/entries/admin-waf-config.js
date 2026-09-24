// Entry Vite di views/admin/waf/config.php — il JavaScript era inline nella vista fino al
// 2026-09-04 (revisione architetturale, intervento P8): spostato qui tale e
// quale, cosi' passa da ESLint e dal bundle. I blocchi originali sono
// separati dai commenti «blocco N»; l'ordine e' quello della pagina.

import { auditReason } from "../modules/core/audit-reason.js";

// ── blocco 1 ──
{
async function submitConfig(e) {
    e.preventDefault();
    const form = e.target;
    const data = Object.fromEntries(new FormData(form));
    const status = document.getElementById('fm-waf-save-status');
    status.textContent = '⏳ Salvataggio…';
    status.className = 'fm-inline-status';
    try {
        const res = await fetch('/admin/waf/api/config', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': data._csrf,
                'X-Requested-With': 'XMLHttpRequest',
                'X-Audit-Reason': `Modifica configurazione WAF: ${  Object.keys(data).filter(k => k !== '_csrf').join(', ')}`
            },
            body: JSON.stringify(data),
            credentials: 'same-origin'
        });
        const json = await res.json();
        if (res.ok && json.ok) {
            status.textContent = `✓ Salvato (${  (json.updated || []).join(', ')  })`;
            status.className = 'fm-inline-status fm-inline-status--ok';
            setTimeout(() => location.reload(), 800);
        } else {
            status.textContent = `✗ Errore: ${  json.error || res.status}`;
            status.className = 'fm-inline-status fm-inline-status--error';
        }
    } catch (err) {
        status.textContent = `✗ Errore: ${  err.message}`;
        status.className = 'fm-inline-status fm-inline-status--error';
    }
    return false;
}

// ─── Anomaly thresholds (ex /admin/waf/anomalies) ──────────────
const CSRF = document.getElementById("fm-page-config")?.dataset.csrf ?? "";
async function apiGet(url) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });
    return res.json();
}
// `motivo` va in X-Audit-Reason: le rotte /api/admin/security/* lo
// pretendono dal 23/9/2026 (A-69), e senza rispondono 400.
async function apiPost(url, data, motivo) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    fd.append('_csrf', CSRF);
    const res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest', 'X-Audit-Reason': motivo },
        credentials: 'same-origin'
    });
    return res.json();
}

async function loadAnomalyConfig() {
    const r = await apiGet('/api/admin/security/config');
    const body = document.getElementById('fm-sec-cfg-body');
    if (!r.ok || !r.config) {
        body.innerHTML = '<p class="fm-muted">Config non disponibile.</p>';
        return;
    }
    const cfg = r.config.security_alerts || {};
    const ea = cfg.excessive_access || {};
    const eaRisk = ea.risk_levels || {};
    const cs = cfg.credential_sharing || {};
    const csRisk = cs.risk_levels || {};

    // 2026-09-08 — ogni etichetta è legata al proprio campo.
    //
    // Prima erano `<label>` sciolte: nella griglia stanno accanto al campo e
    // si leggono benissimo con gli occhi, ma per chi naviga con uno screen
    // reader non nominano niente. axe-core lo segnalava come `label` e
    // `select-name`, gravità critical, su sette campi e due select — tutti
    // quelli senza `placeholder`, perché il placeholder faceva da nome di
    // ripiego agli altri (WCAG 1.3.1 e 4.1.2).
    //
    // Le coppie min/max hanno due campi sotto un'etichetta sola: lì
    // l'etichetta visibile punta al primo, e ciascuno porta il proprio
    // `aria-label` che ripete il gruppo per esteso. Il testo dell'etichetta
    // resta dentro il nome accessibile, come chiede WCAG 2.5.3.
    body.innerHTML = `
    <h3 class="fm-text-15 fm-mt-4 fm-mb-2">🔴 Excessive access (troppi accessi alla stessa sezione)</h3>
    <div class="fm-waf-kv">
        <label for="ea_enabled">Enabled</label>
        <select id="ea_enabled" name="ea_enabled"><option value="1"${ea.enabled?' selected':''}>ON</option><option value="0"${!ea.enabled?' selected':''}>OFF</option></select>
        <label for="ea_threshold_per_section">Threshold per section</label>
        <input type="number" id="ea_threshold_per_section" name="ea_threshold_per_section" value="${ea.threshold_per_section??50}">
        <label for="ea_time_window_hours">Time window (h)</label>
        <input type="number" id="ea_time_window_hours" name="ea_time_window_hours" value="${ea.time_window_hours??24}">
        <label for="ea_low_min">Low: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" id="ea_low_min" name="ea_low_min" value="${eaRisk.low?.min_accesses??50}" placeholder="min" aria-label="Low: min accesses">
            <input type="number" id="ea_low_max" name="ea_low_max" value="${eaRisk.low?.max_accesses??99}" placeholder="max" aria-label="Low: max accesses">
        </div>
        <label for="ea_medium_min">Medium: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" id="ea_medium_min" name="ea_medium_min" value="${eaRisk.medium?.min_accesses??100}" placeholder="min" aria-label="Medium: min accesses">
            <input type="number" id="ea_medium_max" name="ea_medium_max" value="${eaRisk.medium?.max_accesses??199}" placeholder="max" aria-label="Medium: max accesses">
        </div>
        <label for="ea_high_min">High: min (no max)</label>
        <input type="number" id="ea_high_min" name="ea_high_min" value="${eaRisk.high?.min_accesses??200}">
    </div>

    <h3 class="fm-text-15 fm-mt-4 fm-mb-2">🔵 Credential sharing (stesso utente da troppi IP)</h3>
    <div class="fm-waf-kv">
        <label for="cs_enabled">Enabled</label>
        <select id="cs_enabled" name="cs_enabled"><option value="1"${cs.enabled?' selected':''}>ON</option><option value="0"${!cs.enabled?' selected':''}>OFF</option></select>
        <label for="cs_min_ips_required">Min IPs required</label>
        <input type="number" id="cs_min_ips_required" name="cs_min_ips_required" value="${cs.min_ips_required??3}">
        <label for="cs_min_accesses_per_ip">Min accesses per IP</label>
        <input type="number" id="cs_min_accesses_per_ip" name="cs_min_accesses_per_ip" value="${cs.min_accesses_per_ip??2}">
        <label for="cs_time_window_hours">Time window (h)</label>
        <input type="number" id="cs_time_window_hours" name="cs_time_window_hours" value="${cs.time_window_hours??24}">
        <label for="cs_low_min">Low: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" id="cs_low_min" name="cs_low_min" value="${csRisk.low?.min_ips??3}" placeholder="min" aria-label="Low: min IPs">
            <input type="number" id="cs_low_max" name="cs_low_max" value="${csRisk.low?.max_ips??5}" placeholder="max" aria-label="Low: max IPs">
        </div>
        <label for="cs_medium_min">Medium: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" id="cs_medium_min" name="cs_medium_min" value="${csRisk.medium?.min_ips??6}" placeholder="min" aria-label="Medium: min IPs">
            <input type="number" id="cs_medium_max" name="cs_medium_max" value="${csRisk.medium?.max_ips??9}" placeholder="max" aria-label="Medium: max IPs">
        </div>
        <label for="cs_high_min">High: min (no max)</label>
        <input type="number" id="cs_high_min" name="cs_high_min" value="${csRisk.high?.min_ips??10}">
    </div>`;
}

async function saveAnomalyConfig(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const status = document.getElementById('fm-sec-cfg-status');
    status.textContent = '⏳ Salvataggio…';
    status.className = 'fm-inline-status';
    const r = await apiPost('/api/admin/security/config', data,
        auditReason('Modifica delle soglie delle anomalie di sicurezza dal pannello WAF'));
    if (r.ok) {
        status.textContent = '✓ Salvato';
        status.className = 'fm-inline-status fm-inline-status--ok';
        setTimeout(() => loadAnomalyConfig(), 500);
    } else {
        status.textContent = `✗ ${  r.error || 'errore'}`;
        status.className = 'fm-inline-status fm-inline-status--error';
    }
    return false;
}

loadAnomalyConfig();
// ── azioni dichiarate nella vista con data-fm-action (core/declarative.js) ──
window.FM = window.FM || {};
window.FM.pageActions = Object.assign(window.FM.pageActions || {}, {
    submitConfig: (e, el) => submitConfig(e, el),
    loadAnomalyConfig: (e, el) => loadAnomalyConfig(e, el),
    saveAnomalyConfig: (e, el) => saveAnomalyConfig(e, el),
});

}
