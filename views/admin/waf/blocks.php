<?php
/**
 * Phase 25.R.19 — Tab WAF unificato "Blocks" (merge di lists.php + credentials.php).
 *
 * Sezioni:
 *   1. ✅ Whitelist (waf_whitelist) — bypass WAF generico
 *   2. 🚫 WAF Blacklist (waf_blacklist) — blocco pre-route generico (geo + bot + manuale)
 *   3. 🌐 IP bloccati auth-flow (waf_blocked_ips, section!=NULL) — per-sezione anomaly-based
 *   4. 🔐 Credenziali bloccate (waf_blocked_credentials) — brute-force lockout
 *
 * Le 4 sezioni gestiscono storage DB **distinti** ma sono concettualmente
 * "blocchi sicurezza" → tab unico con TOC sticky e sezioni espandibili.
 */
/** @var array<string,string> $config */
/** @var list<array<string,mixed>> $blacklist */
/** @var list<array<string,mixed>> $whitelist */
/** @var string $client_ip */
/** @var ?string $client_country */
/** @var bool $enrich */
/** @var string $csrf */
$current_tab = 'blocks';
$page_title  = 'Blocks';
$enrich      = (bool)($enrich ?? false);
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$clientFlag = \App\Services\Waf\GeoIpService::countryFlag($client_country ?? null);

$_renderAsn = static function (array $row) use ($h): string {
    if (empty($row['asn'])) return '<small class="fm-muted">–</small>';
    return '<span class="fm-waf-asn"><span class="fm-waf-asn__num">AS' . (int)$row['asn'] . '</span>'
        . (!empty($row['org']) ? '<span class="fm-waf-asn__org">' . $h($row['org']) . '</span>' : '')
        . '</span>';
};
$_renderRdns = static function (array $row) use ($h): string {
    return !empty($row['rdns'])
        ? '<span class="fm-waf-rdns">' . $h($row['rdns']) . '</span>'
        : '<small class="fm-muted">–</small>';
};
?>

<nav class="fm-waf-blocks-toc">
    <a href="#live-blocks">🛡️ Live blocks 24h</a>
    <a href="#anomalies-detected">🚨 Anomalies detected</a>
    <a href="#threat-intel">🌍 Threat Intel</a>
    <a href="#whitelist">✅ Whitelist (<?= count($whitelist) ?>)</a>
    <a href="#blacklist">🚫 WAF Blacklist (<?= count($blacklist) ?>)</a>
    <a href="#ip-auth">🌐 IP auth-flow</a>
    <a href="#credentials">🔐 Credenziali</a>
</nav>

<div class="fm-waf-actions-bar">
    <div class="fm-d-flex fm-items-center fm-gap-2 fm-flex-1-grow">
        <strong>Il tuo IP:</strong>
        <code><?= $h($client_ip) ?></code>
        <?php if ($clientFlag): ?>
            <span class="fm-waf-flag" title="<?= $h($client_country ?? '') ?>"><?= $clientFlag ?></span>
            <small class="fm-muted"><?= $h($client_country ?? '') ?></small>
        <?php else: ?>
            <small class="fm-muted">(country unknown / private IP)</small>
        <?php endif; ?>
    </div>
    <button class="fm-btn fm-btn--ghost" data-act="addMyIpWl" type="button" title="Aggiunge il tuo IP corrente alla whitelist (bypass WAF)">
        ✅ Whitelist My IP
    </button>
    <button class="fm-btn fm-btn--ghost" data-act="unbanMyIp" type="button" title="Se sei accidentalmente in blacklist, sblocca">
        🔓 Unban My IP
    </button>
    <button class="fm-btn fm-btn--danger" data-act="unbanAll" type="button" title="Rimuove tutti gli IP dalla blacklist (emergency)">
        ⚠️ Unban All
    </button>
</div>

<?php include __DIR__ . '/_enrich_toggle.php'; ?>

<!-- ────────── 0. LIVE BLOCKS (cross-source view, ultime 24h) ────────── -->
<section id="live-blocks" class="fm-waf-block-section">
<h2 class="fm-section-heading--warning fm-text-17 fm-m-0 fm-mb-2" >🛡️ Live blocks (ultime 24h)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Vista unificata di TUTTI gli IP bloccati di recente, aggregati da <code>waf_logs</code>.
    Include sorgenti che non finiscono in <a href="#blacklist">Blacklist manuale</a>
    (geo, threat intel, CrowdSec, score WAF, custom rule) — risolve la frammentazione
    tra <code>waf_blacklist</code>, <code>waf_threat_ips</code> e decisioni live.
    Click su "📌 Blacklist permanente" per promuovere a blocco manuale persistente.
</p>
<div class="fm-d-flex fm-gap-2 fm-items-center fm-mb-2">
    <label class="fm-text-14">Finestra:
        <select id="fm-live-hours" data-act-change="loadLive">
            <option value="1">1 ora</option>
            <option value="6">6 ore</option>
            <option value="24" selected>24 ore</option>
            <option value="72">3 giorni</option>
            <option value="168">7 giorni</option>
        </select>
    </label>
    <button type="button" class="fm-btn fm-btn--ghost" data-act="loadLive">🔄 Refresh</button>
    <span id="fm-live-status" class="fm-muted fm-text-13" ></span>
</div>
<div id="fm-live-blocks-table"><p class="fm-muted">Caricamento…</p></div>
</section>

<!-- ────────── 0.4 THREAT INTEL FEEDS (ex /admin/waf/threat-intel → migrato qui) ────────── -->
<?php
$ti_enabled  = ($config['threat_intel_enabled'] ?? '1') === '1';
$ti_stats    = $ti_stats ?? [];
?>
<section id="threat-intel" class="fm-waf-block-section">
<h2 class="fm-section-heading--warning fm-text-17 fm-m-0 fm-mb-2" >🌍 Threat Intelligence (feed esterni)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Layer di import bulk da threat-intel feed pubblici:
    <strong>brianhama/bad-asn-list</strong> · <strong>Spamhaus DROP+EDROP</strong> ·
    <strong>X4BNet/lists_vpn</strong> · <strong>CrowdSec community</strong> ·
    <strong>Tor exit nodes</strong>. WAF middleware verifica ogni request contro queste tabelle.
    Config CrowdSec key + master toggle: <a href="/admin/waf/config#threat-intel-config">⚙️ Config → Threat Intel</a>.
</p>
<div class="fm-waf-mode-banner <?= $ti_enabled ? 'enforce' : 'off' ?>" class="fm-mb-4">
    Threat Intel check: <strong><?= $ti_enabled ? 'ATTIVO' : 'DISATTIVO' ?></strong>
    <?php if (!$ti_enabled): ?>
        — riattiva da <a href="/admin/waf/config">Config</a>
    <?php endif; ?>
</div>
<div class="fm-waf-table-scroll">
<table class="fm-waf-table">
<thead><tr><th scope="col">Source</th><th scope="col">Tabelle</th><th scope="col">Entries attive</th><th scope="col">Ultimo sync</th><th scope="col">Status</th><th scope="col">Azioni</th></tr></thead>
<tbody>
<?php foreach ($ti_stats as $s):
    $statusCls = match ($s['status'] ?? '') {
        'ok'      => 'pass',
        'fail'    => 'block',
        'running' => 'soft',
        default   => '',
    };
?>
    <tr>
        <td><strong><?= $h($s['source']) ?></strong></td>
        <td><code><?= $h($s['tables']) ?></code></td>
        <td><?= number_format((int)$s['count'], 0, ',', '.') ?></td>
        <td><small><?= $h($s['last_sync'] ?? '—') ?></small></td>
        <td>
            <?php if ($s['status']): ?>
                <span class="fm-waf-badge <?= $statusCls ?>"><?= $h($s['status']) ?></span>
            <?php else: ?>
                <small class="fm-muted">mai eseguito</small>
            <?php endif; ?>
            <?php if (!empty($s['error'])): ?>
                <br><small class="fm-text-error" title="<?= $h($s['error']) ?>">⚠️ <?= $h(substr((string)$s['error'], 0, 60)) ?>…</small>
            <?php endif; ?>
        </td>
        <td>
            <button class="fm-btn fm-btn--xs fm-btn--primary"
                    data-act="syncTi" data-source="<?= $h($s['source']) ?>" type="button">🔄 Sync</button>
        </td>
    </tr>
<?php endforeach; ?>
<?php if (empty($ti_stats)): ?>
    <tr><td colspan="6" class="fm-waf-empty">Nessuna sorgente configurata.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="fm-mt-2">
    <button class="fm-btn fm-btn--primary" data-act="syncTi" data-source="all" type="button">🔄 Sync tutti</button>
    <span id="fm-ti-sync-status" class="fm-inline-status fm-ml-4" ></span>
</div>
</section>

<!-- ────────── 0.5 ANOMALIES DETECTED (ex /admin/waf/anomalies → migrato qui) ────────── -->
<section id="anomalies-detected" class="fm-waf-block-section">
<h2 class="fm-section-heading--danger fm-text-17 fm-m-0 fm-mb-2" >🚨 Anomalie rilevate (real-time)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Detection live da <code>access_log</code> via <code>AnomalyDetectionService</code>:
    <strong>excessive_access</strong> (DoS-like) + <strong>credential_sharing</strong>.
    Soglie configurabili in <a href="/admin/waf/config#anomaly-thresholds">⚙️ Config → Anomaly thresholds</a>.
    Status badge "🔒 blocked" = già in <code>waf_blocked_ips</code> / <code>waf_blocked_credentials</code>;
    "👁 detected" = solo rilevato, non bloccato.
</p>
<div class="fm-d-flex fm-gap-2 fm-items-center fm-mb-2">
    <button type="button" class="fm-btn fm-btn--ghost" data-act="loadAnoms">🔄 Refresh</button>
    <span id="fm-anomalies-status" class="fm-muted fm-text-13" ></span>
</div>
<div id="fm-anomalies-list"><p class="fm-muted">Caricamento…</p></div>
</section>

<!-- ────────── 1. WHITELIST ────────── -->
<section id="whitelist" class="fm-waf-block-section">
<h2 class="fm-section-heading--success fm-text-17 fm-m-0 fm-mb-2" >✅ Whitelist (bypass WAF)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    IP/CIDR che bypassano tutti i layer WAF (geo + score + threat-intel).
    Tabella DB <code>waf_whitelist</code>.
</p>
<form data-act-submit="addWl" class="fm-mb-4">
    <div class="fm-waf-kv">
        <label>IP / CIDR</label>
        <input name="ip_or_cidr" required placeholder="Team dev / monitoring">
        <label>Motivo</label>
        <input name="reason" placeholder="uptime monitor uptimerobot">
        <label>Scade il (opt)</label>
        <input name="expires_at" type="datetime-local">
    </div>
    <button class="fm-btn fm-btn--primary fm-mt-2"  type="submit">Aggiungi a whitelist</button>
</form>

<div class="fm-waf-table-scroll">
<table class="fm-waf-table">
<thead><tr><th scope="col">IP/CIDR</th><th scope="col">Country</th><?php if ($enrich): ?><th scope="col">rDNS</th><th scope="col">ASN</th><?php endif; ?><th scope="col">Motivo</th><th scope="col">Aggiunto</th><th scope="col">Scade</th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($whitelist as $w): ?>
    <tr>
        <td><code><?= $h($w['ip_or_cidr']) ?></code></td>
        <td>
            <?php if (!empty($w['country_flag'])): ?>
                <span class="fm-waf-flag"><?= $w['country_flag'] ?></span>
                <small><?= $h($w['country'] ?? '') ?></small>
            <?php else: ?>
                <small class="fm-muted">–</small>
            <?php endif; ?>
        </td>
        <?php if ($enrich): ?>
            <td><?= $_renderRdns($w) ?></td>
            <td><?= $_renderAsn($w) ?></td>
        <?php endif; ?>
        <td><?= $h($w['reason'] ?? '') ?></td>
        <td><small><?= $h($w['created_at']) ?></small></td>
        <td><small><?= $h($w['expires_at'] ?? '–') ?></small></td>
        <td><button class="fm-btn fm-btn--xs" data-act="delItem" data-list="whitelist" data-id="<?= (int)$w['id'] ?>" type="button">🗑️</button></td>
    </tr>
<?php endforeach; ?>
<?php if (empty($whitelist)): ?>
    <tr><td colspan="<?= $enrich ? 8 : 6 ?>" class="fm-waf-empty">Nessun IP in whitelist.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<!-- ────────── 2. WAF BLACKLIST (PRE-ROUTE) ────────── -->
<section id="blacklist" class="fm-waf-block-section">
<h2 class="fm-section-heading--danger fm-text-17 fm-m-0 fm-mb-2" >🚫 WAF Blacklist (block pre-route)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Blocco generico applicato da WAF middleware prima del routing (geo + bot + manuale).
    Tabella DB <code>waf_blacklist</code>. Per blocchi anomaly-based per-sezione
    vai a <a href="#ip-auth">IP auth-flow</a>.
</p>
<form data-act-submit="addBl" class="fm-mb-4">
    <div class="fm-waf-kv">
        <label>IP / CIDR</label>
        <input name="ip_or_cidr" required placeholder="1.2.3.4 o 1.2.0.0/16">
        <label>Motivo</label>
        <input name="reason" placeholder="bot crawling aggressive">
        <label>Scade il (opt)</label>
        <input name="expires_at" type="datetime-local">
    </div>
    <button class="fm-btn fm-btn--primary fm-mt-2"  type="submit">Aggiungi a blacklist</button>
</form>

<div class="fm-waf-table-scroll">
<table class="fm-waf-table">
<thead><tr><th scope="col">IP/CIDR</th><th scope="col">Country</th><?php if ($enrich): ?><th scope="col">rDNS</th><th scope="col">ASN</th><?php endif; ?><th scope="col">Motivo</th><th scope="col">Aggiunto</th><th scope="col">Scade</th><th scope="col">Hit</th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($blacklist as $b): ?>
    <tr>
        <td><code><?= $h($b['ip_or_cidr']) ?></code></td>
        <td>
            <?php if (!empty($b['country_flag'])): ?>
                <span class="fm-waf-flag"><?= $b['country_flag'] ?></span>
                <small><?= $h($b['country'] ?? '') ?></small>
            <?php else: ?>
                <small class="fm-muted">–</small>
            <?php endif; ?>
        </td>
        <?php if ($enrich): ?>
            <td><?= $_renderRdns($b) ?></td>
            <td><?= $_renderAsn($b) ?></td>
        <?php endif; ?>
        <td><?= $h($b['reason'] ?? '') ?></td>
        <td><small><?= $h($b['created_at']) ?></small></td>
        <td><small><?= $h($b['expires_at'] ?? '–') ?></small></td>
        <td><?= (int)($b['hit_count'] ?? 0) ?></td>
        <td><button class="fm-btn fm-btn--xs" data-act="delItem" data-list="blacklist" data-id="<?= (int)$b['id'] ?>" type="button">🗑️</button></td>
    </tr>
<?php endforeach; ?>
<?php if (empty($blacklist)): ?>
    <tr><td colspan="<?= $enrich ? 9 : 7 ?>" class="fm-waf-empty">Nessun IP in blacklist.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<!-- ────────── 3. IP BLOCKED AUTH-FLOW (PER-SECTION) ────────── -->
<section id="ip-auth" class="fm-waf-block-section">
<h2 class="fm-section-heading--danger fm-text-17 fm-m-0 fm-mb-2" >🌐 IP bloccati auth-flow (per-sezione)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Blocco anomaly-based per-sezione gestito da <code>AnomalyDetectionService</code>
    (storage <code>waf_blocked_ips</code> con campo <code>section</code>).
    Trigger automatico da <a href="/admin/waf/anomalies">Anomalies</a>.
    Distinto da <a href="#blacklist">WAF Blacklist</a> globale (pre-route).
</p>
<form id="fm-block-ip-form" data-act-submit="blockIp" class="fm-mb-4">
    <div class="fm-waf-kv">
        <label>IP</label>
        <input name="ip" required placeholder="1.2.3.4">
        <label>Sezione (opt)</label>
        <input name="section" placeholder="login | api | …">
        <label>Motivo</label>
        <input name="reason" placeholder="excessive_access pattern">
    </div>
    <button class="fm-btn fm-btn--primary fm-mt-2"  type="submit">🚫 Blocca</button>
</form>
<div id="fm-ip-table"><p class="fm-muted">Caricamento…</p></div>
</section>

<!-- ────────── 4. CREDENZIALI BLOCCATE ────────── -->
<section id="credentials" class="fm-waf-block-section">
<h2 class="fm-section-heading--danger fm-text-17 fm-m-0 fm-mb-2" >🔐 Credenziali bloccate (brute-force)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Auto-lockout dopo <code>LOGIN_MAX_ATTEMPTS</code> failed login (default 5).
    TTL <code>LOGIN_LOCKOUT_SECONDS</code> (default 300s).
    Tabella DB <code>waf_blocked_credentials</code>.
</p>
<form id="fm-block-cred-form" data-act-submit="blockCred" class="fm-mb-4">
    <div class="fm-waf-kv">
        <label>Username</label>
        <input name="username" required placeholder="utente.bloccato">
        <label>Motivo</label>
        <input name="reason" placeholder="brute force osservato">
    </div>
    <button class="fm-btn fm-btn--primary fm-mt-2"  type="submit">🚫 Blocca</button>
</form>
<div id="fm-cred-table"><p class="fm-muted">Caricamento…</p></div>
</section>

</div><!-- /.fm-card -->

<script>
const CSRF = '<?= $h($csrf) ?>';
const CLIENT_IP = '<?= $h($client_ip) ?>';
const ENRICH = <?= $enrich ? 'true' : 'false' ?>;

// ─── Lists API (whitelist/blacklist) ───────────────────────────
async function addList(e, kind) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const res = await fetch('/admin/waf/api/' + kind, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(data),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
    else alert('Errore: ' + (j.error || 'sconosciuto'));
    return false;
}

async function deleteListItem(kind, id) {
    if (!confirm('Eliminare?')) return;
    const res = await fetch('/admin/waf/api/' + kind + '/' + id, {
        method: 'DELETE',
        headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
}

async function addMyIp(kind) {
    const reason = prompt('Motivo per aggiungere ' + CLIENT_IP + ' a ' + kind + ':',
                          kind === 'whitelist' ? 'admin trusted' : 'manual block');
    if (reason === null) return;
    const res = await fetch('/admin/waf/api/' + kind, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ ip_or_cidr: CLIENT_IP, reason }),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) location.reload();
    else alert('Errore: ' + (j.error || 'sconosciuto'));
}

async function removeMyIpFromBlacklist() {
    const blSection = document.getElementById('blacklist');
    const tbody = blSection.querySelectorAll('table.fm-waf-table tbody tr');
    for (const tr of tbody) {
        const code = tr.querySelector('code')?.textContent || '';
        if (code === CLIENT_IP) {
            const btn = tr.querySelector('button[data-act="delItem"][data-list="blacklist"]');
            if (btn) { btn.click(); return; }
        }
    }
    alert('Il tuo IP (' + CLIENT_IP + ') non è in blacklist.');
}

async function unbanAll() {
    if (!confirm('⚠️ ATTENZIONE: questa operazione rimuove TUTTI gli IP dalla blacklist.\n\nProcedere?')) return;
    if (!confirm('Confermi davvero? Operazione irreversibile.')) return;
    const tbody = document.querySelector('#blacklist table.fm-waf-table tbody');
    const btns = tbody?.querySelectorAll('button[data-act="delItem"][data-list="blacklist"]') ?? [];
    let count = 0;
    for (const btn of btns) {
        const id = btn.dataset.id;
        if (!id) continue;
        const res = await fetch('/admin/waf/api/blacklist/' + id, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        });
        if ((await res.json()).ok) count++;
    }
    alert(count + ' IP sbloccati. Ricarico pagina.');
    location.reload();
}

// ─── Auth-flow API (IP+credentials) ────────────────────────────
async function apiGet(url) {
    if (ENRICH) {
        url += (url.includes('?') ? '&' : '?') + 'enrich=1';
    }
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

async function loadCreds() {
    const r = await apiGet('/api/admin/security/blocked-credentials');
    const tbl = document.getElementById('fm-cred-table');
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = '<p class="fm-muted">Nessuna credenziale bloccata.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr><th scope="col">Username</th><th scope="col">Bloccato il</th><th scope="col">Motivo</th><th scope="col">Da</th><th scope="col"></th></tr></thead><tbody>';
    for (const row of r.rows) {
        html += `<tr>
            <td><code>${escapeHtml(row.username||'')}</code></td>
            <td><small>${escapeHtml(row.blocked_at||'')}</small></td>
            <td>${escapeHtml(row.reason||'')}</td>
            <td><small>${escapeHtml(row.blocked_by||'')}</small></td>
            <td><button class="fm-btn fm-btn--xs" data-act="unblockCred" data-username="${escapeHtml(row.username||'')}">✓ Sblocca</button></td>
        </tr>`;
    }
    tbl.innerHTML = html + '</tbody></table></div>';
}

async function loadIps() {
    const r = await apiGet('/api/admin/security/blocked-ips');
    const tbl = document.getElementById('fm-ip-table');
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = '<p class="fm-muted">Nessun IP bloccato lato auth-flow.</p>';
        return;
    }
    const extraCols = ENRICH ? '<th scope="col">rDNS</th><th scope="col">ASN</th>' : '';
    let html = `<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr><th scope="col">IP</th><th scope="col">Country</th>${extraCols}<th scope="col">Sezione</th><th scope="col">Bloccato il</th><th scope="col">Motivo</th><th scope="col"></th></tr></thead><tbody>`;
    for (const row of r.rows) {
        const flagHtml = row.country_flag || '';
        const ccText = escapeHtml(row.country || '–');
        let enrichCells = '';
        if (ENRICH) {
            const rdns = row.rdns
                ? `<span class="fm-waf-rdns">${escapeHtml(row.rdns)}</span>`
                : '<small class="fm-muted">–</small>';
            const asn = row.asn
                ? `<span class="fm-waf-asn"><span class="fm-waf-asn__num">AS${parseInt(row.asn, 10)}</span>${row.org ? `<span class="fm-waf-asn__org">${escapeHtml(row.org)}</span>` : ''}</span>`
                : '<small class="fm-muted">–</small>';
            enrichCells = `<td>${rdns}</td><td>${asn}</td>`;
        }
        html += `<tr>
            <td><code>${escapeHtml(row.ip||'')}</code></td>
            <td><span class="fm-waf-flag">${flagHtml}</span> <small>${ccText}</small></td>
            ${enrichCells}
            <td><small>${escapeHtml(row.section||'global')}</small></td>
            <td><small>${escapeHtml(row.blocked_at||'')}</small></td>
            <td>${escapeHtml(row.reason||'')}</td>
            <td><button class="fm-btn fm-btn--xs" data-act="unblockIp" data-ip="${escapeHtml(row.ip||'')}" data-section="${escapeHtml(row.section||'')}">✓ Sblocca</button></td>
        </tr>`;
    }
    tbl.innerHTML = html + '</tbody></table></div>';
}

async function blockCred(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const r = await apiPost('/api/admin/security/credentials/block', data);
    if (r.ok) { e.target.reset(); loadCreds(); }
    else alert('Errore: ' + (r.error || 'sconosciuto'));
    return false;
}

async function blockIp(e) {
    e.preventDefault();
    const data = Object.fromEntries(new FormData(e.target));
    const r = await apiPost('/api/admin/security/ips/block', data);
    if (r.ok) { e.target.reset(); loadIps(); }
    else alert('Errore: ' + (r.error || 'sconosciuto'));
    return false;
}

async function unblockCred(username) {
    if (!confirm('Sblocca credenziale ' + username + '?')) return;
    const r = await apiPost('/api/admin/security/credentials/unblock', { username });
    if (r.ok) loadCreds();
}

async function unblockIp(ip, section) {
    if (!confirm('Sblocca IP ' + ip + (section ? ' (sezione: ' + section + ')' : '') + '?')) return;
    const r = await apiPost('/api/admin/security/ips/unblock', { ip, section });
    if (r.ok) loadIps();
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// ─── Live blocks (cross-source aggregate da waf_logs) ──────────
async function loadLiveBlocks() {
    const hours = document.getElementById('fm-live-hours')?.value || 24;
    const status = document.getElementById('fm-live-status');
    const tbl = document.getElementById('fm-live-blocks-table');
    if (status) status.textContent = '⏳';
    const r = await apiGet('/api/admin/security/live-blocks?hours=' + encodeURIComponent(hours));
    if (status) status.textContent = '';
    if (!r.ok || !r.rows || !r.rows.length) {
        tbl.innerHTML = '<p class="fm-muted">Nessun IP bloccato nelle ultime ' + escapeHtml(String(hours)) + ' ore.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr>'
        + '<th scope="col">IP</th><th scope="col">Country</th><th scope="col">Sorgente (last)</th><th scope="col">Tutte sorgenti</th>'
        + '<th scope="col">Hit</th><th scope="col">Primo</th><th scope="col">Ultimo</th><th scope="col">Stato</th><th scope="col"></th></tr></thead><tbody>';
    for (const row of r.rows) {
        const flag = row.country_flag || '';
        const cc = escapeHtml(row.country || '–');
        const last = escapeHtml(row.last_outcome || '');
        const sources = escapeHtml(row.sources || '').replaceAll(',', ', ');
        let stateBadge;
        if (row.in_whitelist) {
            stateBadge = '<span class="fm-waf-badge whitelist" title="IP in whitelist permanente">✅ whitelisted</span>';
        } else if (row.in_blacklist) {
            stateBadge = '<span class="fm-waf-badge block" title="Già in waf_blacklist permanente">📌 in blacklist</span>';
        } else {
            stateBadge = '<span class="fm-waf-badge monitor" title="Bloccato al volo (no entry persistente)">⚡ live-only</span>';
        }
        const action = (row.in_blacklist || row.in_whitelist)
            ? ''
            : `<button class="fm-btn fm-btn--xs" data-act="promote" data-ip="${escapeHtml(row.ip)}" data-outcome="${escapeHtml(row.last_outcome)}" title="Aggiungi a waf_blacklist permanente">📌 Blacklist</button>`;
        html += `<tr>
            <td><code>${escapeHtml(row.ip||'')}</code></td>
            <td><span class="fm-waf-flag">${flag}</span> <small>${cc}</small></td>
            <td><span class="fm-waf-badge ${last}">${last}</span></td>
            <td><small class="fm-muted">${sources}</small></td>
            <td>${row.count}</td>
            <td><small>${escapeHtml(row.first_seen||'')}</small></td>
            <td><small>${escapeHtml(row.last_seen||'')}</small></td>
            <td>${stateBadge}</td>
            <td>${action}</td>
        </tr>`;
    }
    tbl.innerHTML = html + '</tbody></table></div>';
}

async function promoteToBlacklist(ip, lastOutcome) {
    const reason = prompt('Motivo per blacklist permanente di ' + ip + ':',
                          'live-block promoted (last outcome: ' + lastOutcome + ')');
    if (reason === null) return;
    const res = await fetch('/admin/waf/api/blacklist', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ ip_or_cidr: ip, reason }),
        credentials: 'same-origin'
    });
    const j = await res.json();
    if (j.ok) {
        loadLiveBlocks();
        // ricarica pagina per aggiornare anche sezione Blacklist (PHP-rendered)
        setTimeout(() => location.reload(), 600);
    } else {
        alert('Errore: ' + (j.error || 'sconosciuto'));
    }
}

// ─── Threat Intel sync (ex /admin/waf/threat-intel) ────────────
async function syncThreatSource(source) {
    const status = document.getElementById('fm-ti-sync-status');
    if (status) {
        status.textContent = source === 'all' ? '⏳ Sync tutti…' : `⏳ Sync ${source}…`;
        status.className = 'fm-inline-status';
    }
    try {
        const res = await fetch('/admin/waf/api/threat-intel/sync', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ source }),
        });
        const j = await res.json();
        if (j.ok) {
            const total = Object.values(j.results || {}).reduce((acc, r) => acc + ((r && r.imported) || 0), 0);
            const failed = Object.entries(j.results || {}).filter(([, r]) => !r.ok).map(([k]) => k);
            let msg = `✓ Sync completo: ${total} entries`;
            if (failed.length) msg += ` (failed: ${failed.join(',')})`;
            if (status) {
                status.textContent = msg;
                status.className = 'fm-inline-status fm-inline-status--' + (failed.length ? 'error' : 'ok');
            }
            setTimeout(() => location.reload(), 1500);
        } else {
            if (status) {
                status.textContent = '✗ Sync failed';
                status.className = 'fm-inline-status fm-inline-status--error';
            }
        }
    } catch (err) {
        if (status) {
            status.textContent = '✗ Errore: ' + err.message;
            status.className = 'fm-inline-status fm-inline-status--error';
        }
    }
}

// ─── Anomalies detected (ex /admin/waf/anomalies) ──────────────
async function loadAnomalies() {
    const status = document.getElementById('fm-anomalies-status');
    const list = document.getElementById('fm-anomalies-list');
    if (status) status.textContent = '⏳';
    const r = await apiGet('/api/admin/security/anomalies');
    if (status) status.textContent = '';
    if (!r.ok || !r.rows || !r.rows.length) {
        list.innerHTML = '<p class="fm-muted">Nessuna anomalia rilevata.</p>';
        return;
    }
    let html = '<div class="fm-waf-table-scroll"><table class="fm-waf-table"><thead><tr>'
        + '<th scope="col">Tipo</th><th scope="col">Status</th><th scope="col">Utente / IP</th><th scope="col">Risk</th><th scope="col">Count</th>'
        + '<th scope="col">Detected</th><th scope="col">Detail</th></tr></thead><tbody>';
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

// CSP strict — event delegation (sostituisce gli on* inline rimossi).
document.addEventListener('click', function (e) {
    const b = e.target.closest('[data-act]');
    if (!b) return;
    switch (b.dataset.act) {
        case 'addMyIpWl':   addMyIp('whitelist'); break;
        case 'unbanMyIp':   removeMyIpFromBlacklist(); break;
        case 'unbanAll':    unbanAll(); break;
        case 'loadLive':    loadLiveBlocks(); break;
        case 'loadAnoms':   loadAnomalies(); break;
        case 'syncTi':      syncThreatSource(b.dataset.source); break;
        case 'delItem':     deleteListItem(b.dataset.list, parseInt(b.dataset.id, 10)); break;
        case 'unblockCred': unblockCred(b.dataset.username); break;
        case 'unblockIp':   unblockIp(b.dataset.ip, b.dataset.section); break;
        case 'promote':     promoteToBlacklist(b.dataset.ip, b.dataset.outcome); break;
    }
});
document.addEventListener('change', function (e) {
    const el = e.target.closest('[data-act-change="loadLive"]');
    if (el) loadLiveBlocks();
});
document.addEventListener('submit', function (e) {
    const f = e.target.closest('[data-act-submit]');
    if (!f) return;
    e.preventDefault();
    switch (f.dataset.actSubmit) {
        case 'addWl':     addList(e, 'whitelist'); break;
        case 'addBl':     addList(e, 'blacklist'); break;
        case 'blockIp':   blockIp(e); break;
        case 'blockCred': blockCred(e); break;
    }
});

loadCreds();
loadIps();
loadLiveBlocks();
loadAnomalies();
</script>

</div><!-- /.fm-card -->
