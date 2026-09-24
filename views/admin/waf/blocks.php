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

<?php // I valori PHP passano al modulo via data-*: niente JS generato dal server (P8, 2026-09-04). ?>
<div id="fm-waf-blocks-config" hidden
     data-csrf="<?= $h($csrf) ?>"
     data-client-ip="<?= $h($client_ip) ?>"
     data-enrich="<?= $enrich ? '1' : '0' ?>"></div>
<?= \App\Support\ViteManifest::script('js/entries/admin-waf-blocks.js') ?>

</div><!-- /.fm-card -->
