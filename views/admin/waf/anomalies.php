<?php
/** @var array<string,string> $config */
/** @var string $csrf */
$current_tab = 'anomalies';
$page_title  = 'Anomalies & soglie';
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<p class="fm-muted fm-m-0 fm-mb-4 fm-text-15" >
    Real-time anomaly detection (<code>AnomalyDetectionService</code>):
    <strong>excessive_access</strong> (troppi accessi alla stessa sezione)
    + <strong>credential_sharing</strong> (stesso utente da troppi IP).
    Output → soglie configurabili sotto, alert via <code>AdminNotificationsService</code>.
</p>

<section class="fm-mb-6">
<details open>
<summary class="fm-cursor-pointer fm-fw-600 fm-text-base">⚙️ Soglie rilevamento (low / medium / high)</summary>
<form id="fm-sec-cfg-form" class="fm-mt-4">
<div id="fm-sec-cfg-body" class="fm-muted">Caricamento…</div>
<button type="submit" class="fm-btn fm-btn--primary fm-mt-2" >💾 Salva soglie</button>
<button type="button" class="fm-btn fm-btn--ghost">🔄 Ricarica</button>
<script>document.currentScript.previousElementSibling.addEventListener("click",function(event){loadConfig()})</script>
<span id="fm-sec-cfg-status" class="fm-ml-4 fm-text-15"></span>
</form>
<script>document.currentScript.previousElementSibling.addEventListener("submit",function(event){event.preventDefault();saveConfig(event)})</script>
</details>
</section>

<section>
<h2 class="fm-text-17 fm-m-0 fm-mb-2">🚨 Anomalie rilevate (real-time)</h2>
<div id="fm-anomalies-list"><p class="fm-muted">Caricamento…</p></div>
<button type="button" class="fm-btn fm-mt-2" >🔄 Refresh</button>
<script>document.currentScript.previousElementSibling.addEventListener("click",function(event){loadAnomalies()})</script>
</section>

</div><!-- /.fm-card -->

<script>
const CSRF = '<?= $h($csrf) ?>';

async function apiGet(url) {
    const res = await fetch(url, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });
    return res.json();
}

async function apiPost(url, data) {
    const fd = new FormData();
    for (const k in data) fd.append(k, data[k]);
    fd.append('_csrf', CSRF);
    const res = await fetch(url, {
        method: 'POST',
        body: fd,
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });
    return res.json();
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

async function loadConfig() {
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

    body.innerHTML = `
    <h3 class="fm-text-15 fm-mt-4 fm-mb-2">🔴 Excessive access (troppi accessi alla stessa sezione)</h3>
    <div class="fm-waf-kv">
        <label>Enabled</label>
        <select name="ea_enabled"><option value="1"${ea.enabled?' selected':''}>ON</option><option value="0"${!ea.enabled?' selected':''}>OFF</option></select>
        <label>Threshold per section</label>
        <input type="number" name="ea_threshold_per_section" value="${ea.threshold_per_section??50}">
        <label>Time window (h)</label>
        <input type="number" name="ea_time_window_hours" value="${ea.time_window_hours??24}">
        <label>Low: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" name="ea_low_min" value="${eaRisk.low?.min_accesses??50}" placeholder="min">
            <input type="number" name="ea_low_max" value="${eaRisk.low?.max_accesses??99}" placeholder="max">
        </div>
        <label>Medium: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" name="ea_medium_min" value="${eaRisk.medium?.min_accesses??100}" placeholder="min">
            <input type="number" name="ea_medium_max" value="${eaRisk.medium?.max_accesses??199}" placeholder="max">
        </div>
        <label>High: min (no max)</label>
        <input type="number" name="ea_high_min" value="${eaRisk.high?.min_accesses??200}">
    </div>

    <h3 class="fm-text-15 fm-mt-4 fm-mb-2">🔵 Credential sharing (stesso utente da troppi IP)</h3>
    <div class="fm-waf-kv">
        <label>Enabled</label>
        <select name="cs_enabled"><option value="1"${cs.enabled?' selected':''}>ON</option><option value="0"${!cs.enabled?' selected':''}>OFF</option></select>
        <label>Min IPs required</label>
        <input type="number" name="cs_min_ips_required" value="${cs.min_ips_required??3}">
        <label>Min accesses per IP</label>
        <input type="number" name="cs_min_accesses_per_ip" value="${cs.min_accesses_per_ip??2}">
        <label>Time window (h)</label>
        <input type="number" name="cs_time_window_hours" value="${cs.time_window_hours??24}">
        <label>Low: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" name="cs_low_min" value="${csRisk.low?.min_ips??3}" placeholder="min">
            <input type="number" name="cs_low_max" value="${csRisk.low?.max_ips??5}" placeholder="max">
        </div>
        <label>Medium: min / max</label>
        <div class="fm-d-flex fm-gap-1">
            <input type="number" name="cs_medium_min" value="${csRisk.medium?.min_ips??6}" placeholder="min">
            <input type="number" name="cs_medium_max" value="${csRisk.medium?.max_ips??9}" placeholder="max">
        </div>
        <label>High: min (no max)</label>
        <input type="number" name="cs_high_min" value="${csRisk.high?.min_ips??10}">
    </div>`;
}

async function saveConfig(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const status = document.getElementById('fm-sec-cfg-status');
    status.textContent = '⏳ Salvataggio…';
    const r = await apiPost('/api/admin/security/config', data);
    if (r.ok) {
        status.textContent = '✓ Salvato';
        status.style.color = '#065f46';
        setTimeout(() => loadConfig(), 500);
    } else {
        status.textContent = '✗ ' + (r.error || 'errore');
        status.style.color = '#b91c1c';
    }
    return false;
}

async function loadAnomalies() {
    const r = await apiGet('/api/admin/security/anomalies');
    const list = document.getElementById('fm-anomalies-list');
    if (!r.ok || !r.rows || !r.rows.length) {
        list.innerHTML = '<p class="fm-muted">Nessuna anomalia rilevata.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr><th scope="col">Tipo</th><th scope="col">Status</th><th scope="col">Utente / IP</th><th scope="col">Risk</th><th scope="col">Count</th><th scope="col">Detected</th><th scope="col">Detail</th></tr></thead><tbody>';
    for (const a of r.rows) {
        const risk = a.risk_level || '?';
        const cls = risk === 'high' ? 'block' : (risk === 'medium' ? 'soft' : 'pass');
        const blocked = a.blocked === true;
        const statusBadge = blocked
            ? '<span class="fm-waf-badge block" title="Già presente in waf_blocked_ips / waf_blocked_credentials">🔒 blocked</span>'
            : '<span class="fm-waf-badge monitor" title="Rilevato ma non bloccato">👁 detected</span>';
        const target  = escapeHtml(a.username || a.ip || '');
        const lastSeen = a.last_seen || a.detected_at || '';
        const detail  = a.detail || (a.section ? `sezione: ${a.section}` : (Array.isArray(a.ips) ? `${a.ips.length} IP` : ''));
        html += `<tr>
            <td>${escapeHtml(a.type)}</td>
            <td>${statusBadge}</td>
            <td><code>${target}</code></td>
            <td><span class="fm-waf-badge ${cls}">${escapeHtml(risk)}</span></td>
            <td>${escapeHtml(a.count || '')}</td>
            <td><small>${escapeHtml(lastSeen)}</small></td>
            <td><small>${escapeHtml(detail)}</small></td>
        </tr>`;
    }
    list.innerHTML = html + '</tbody></table></div>';
}

loadConfig();
loadAnomalies();
</script>

</div><!-- /.fm-card -->
