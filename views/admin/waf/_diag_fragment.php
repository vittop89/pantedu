<?php
/**
 * Phase 25.R.22 — WAF Diagnostic fragment (ex /admin/waf/diag tab).
 *
 * Tab dedicato eliminato → contenuto inlined come accordion in
 * /admin/waf/reports (vista analitica unificata).
 *
 * Variabili in scope (calcolate dal controller):
 *   $diag_countryPath string
 *   $diag_asnPath     string
 *   $diag_envInfo     array
 *   $diag_sdkAvail    bool
 *   $diag_results     array
 *   $diag_wafCfg      array
 *   $diag_csStatus    array
 *   $diag_hpStats     array
 *   $diag_hpTop       array
 *   $diag_tiStats     array
 *   $diag_logTail     ?array  (null se non leggibile)
 *   $diag_logPath     string
 */
$dh = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$criticalKeys = ['enabled', 'mode', 'geo_mode', 'geo_allowed', 'threshold_pass', 'threshold_block', 'threat_intel_enabled'];
?>
<h3 class="fm-text-base fm-mt-4">SDK GeoIp2</h3>
<p>Class <code>\GeoIp2\Database\Reader</code>:
    <strong class="fm-diag-status" data-ok="<?= $diag_sdkAvail ? 'true' : 'false' ?>">
        <?= $diag_sdkAvail ? '✓ installed (geoip2/geoip2 via composer)' : '✗ NOT installed' ?>
    </strong>
</p>

<h3 class="fm-text-base fm-mt-4">Path mmdb</h3>
<table class="fm-waf-table">
<tr><th scope="col">Var</th><th scope="col">Path</th><th scope="col">Exists</th><th scope="col">Size</th></tr>
<tr>
    <td>WAF_GEOIP_DB (country)</td>
    <td><code><?= $dh($diag_countryPath) ?></code></td>
    <td><?= $diag_countryPath && file_exists($diag_countryPath) ? '✓' : '✗' ?></td>
    <td><?= $diag_countryPath && file_exists($diag_countryPath) ? number_format(filesize($diag_countryPath)/1024/1024, 1) . ' MB' : '–' ?></td>
</tr>
<tr>
    <td>WAF_GEOIP_ASN_DB</td>
    <td><code><?= $dh($diag_asnPath) ?></code></td>
    <td><?= $diag_asnPath && file_exists($diag_asnPath) ? '✓' : '✗' ?></td>
    <td><?= $diag_asnPath && file_exists($diag_asnPath) ? number_format(filesize($diag_asnPath)/1024/1024, 1) . ' MB' : '–' ?></td>
</tr>
</table>

<h3 class="fm-text-base fm-mt-4">Env raw</h3>
<pre class="fm-keybox-sm"><?= $dh(json_encode($diag_envInfo, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) ?></pre>

<h3 class="fm-text-base fm-mt-4">Test lookup</h3>
<table class="fm-waf-table">
<tr><th scope="col">IP</th><th scope="col">Country</th><th scope="col">rDNS</th><th scope="col">ASN</th><th scope="col">Org</th></tr>
<?php foreach ($diag_results as $ip => $r): ?>
    <tr>
        <td><code><?= $dh($ip) ?></code></td>
        <td><?= $dh($r['country'] ?? '(null)') ?></td>
        <td><small><?= $dh((string)($r['enrich']['rdns'] ?? '(null)')) ?></small></td>
        <td><?= $r['enrich']['asn'] ? 'AS' . (int)$r['enrich']['asn'] : '(null)' ?></td>
        <td><small><?= $dh((string)($r['enrich']['org'] ?? '(null)')) ?></small></td>
    </tr>
<?php endforeach; ?>
</table>

<h3 class="fm-text-base fm-mt-4">WAF Config (DB) — chiavi critiche</h3>
<table class="fm-waf-table">
<tr><th scope="col">Key</th><th scope="col">Value</th><th scope="col">Effect</th></tr>
<?php foreach ($criticalKeys as $k):
    $v = $diag_wafCfg[$k] ?? '(unset)';
    $effect = match (true) {
        $k === 'enabled' && $v !== '1'              => '⚠️ WAF disabilitato — bypass totale',
        $k === 'enabled' && $v === '1'              => '✓ WAF attivo',
        $k === 'mode' && $v === 'monitor'           => '⚠️ Solo log, NESSUN blocco effettivo',
        $k === 'mode' && $v === 'enforce'           => '✓ Block enforced',
        $k === 'mode' && $v === 'off'               => '⚠️ Mode OFF — bypass',
        $k === 'mode' && $v === 'soft'              => '⚠️ Soft: solo score>block, geo monitor',
        $k === 'geo_mode' && $v === 'monitor'       => '⚠️ Geo solo log (non blocca paesi non-IT)',
        $k === 'geo_mode' && $v === 'enforce'       => '✓ Geo block attivo',
        $k === 'threat_intel_enabled' && $v === '1' => '✓ Threat intel attivo',
        $k === 'threat_intel_enabled' && $v !== '1' => '⚠️ Threat intel disattivato',
        default                                      => '—',
    };
?>
    <tr>
        <td><code><?= $dh($k) ?></code></td>
        <td><strong><?= $dh((string)$v) ?></strong></td>
        <td><?= $effect ?></td>
    </tr>
<?php endforeach; ?>
</table>
<p class="fm-text-em-md fm-text-muted">
    Per bloccare GB/PL/US: vai a <a href="/admin/waf/config">Config</a> → <code>mode=enforce</code> AND <code>geo_mode=enforce</code>.
    Outcome cambierà da <code>monitor_geo</code> a <code>blocked_geo</code>.
</p>

<h3 class="fm-text-base fm-mt-4">🐝 CrowdSec Bouncer</h3>
<table class="fm-waf-table">
<tr><th scope="col">Field</th><th scope="col">Value</th></tr>
<tr><td>Configured</td><td><?= $diag_csStatus['configured'] ? '✓ YES' : '✗ NO (CROWDSEC_LAPI_KEY mancante)' ?></td></tr>
<tr><td>LAPI reachable</td><td><?= $diag_csStatus['reachable'] ? '✓ YES' : '✗ NO' ?></td></tr>
<?php if (!empty($diag_csStatus['error'])): ?>
    <tr><td>Error</td><td><small><?= $dh($diag_csStatus['error']) ?></small></td></tr>
<?php endif; ?>
</table>
<?php if (!$diag_csStatus['configured']): ?>
    <p class="fm-muted fm-text-em-md" >
        Setup: <code>bash tools/dev/setup_crowdsec_vps.sh</code> dal locale.
    </p>
<?php endif; ?>

<h3 class="fm-text-base fm-mt-4">🍯 Honeypot stats</h3>
<p>
    Hit totali: <strong><?= (int)($diag_hpStats['hits_total'] ?? 0) ?></strong>
    · Hit 24h: <strong><?= (int)($diag_hpStats['hits_24h'] ?? 0) ?></strong>
    · IP unici: <strong><?= (int)($diag_hpStats['unique_ips'] ?? 0) ?></strong>
</p>
<?php if ($diag_hpTop): ?>
    <table class="fm-waf-table">
    <tr><th scope="col">IP</th><th scope="col">Country</th><th scope="col">Hits</th><th scope="col">Ultimo path</th><th scope="col">Ultimo hit</th></tr>
    <?php foreach ($diag_hpTop as $h): ?>
        <tr>
            <td><code><?= $dh((string)$h['ip']) ?></code></td>
            <td><?= $dh((string)($h['country'] ?? '–')) ?></td>
            <td><?= (int)$h['hits'] ?></td>
            <td><code class="fm-text-em-base"><?= $dh((string)$h['last_path']) ?></code></td>
            <td><small><?= $dh((string)$h['last_ts']) ?></small></td>
        </tr>
    <?php endforeach; ?>
    </table>
<?php else: ?>
    <p class="fm-muted">Nessun hit honeypot ancora.</p>
<?php endif; ?>

<h3 class="fm-text-base fm-mt-4">Threat Intel sources</h3>
<table class="fm-waf-table">
<tr><th scope="col">Source</th><th scope="col">Tabelle</th><th scope="col">Entries attive</th><th scope="col">Ultimo sync</th><th scope="col">Status</th></tr>
<?php foreach ($diag_tiStats as $s): ?>
    <tr>
        <td><strong><?= $dh($s['source']) ?></strong></td>
        <td><code><?= $dh($s['tables']) ?></code></td>
        <td><?= number_format((int)$s['count'], 0, ',', '.') ?></td>
        <td><small><?= $dh($s['last_sync'] ?? '—') ?></small></td>
        <td><?= $s['status'] ? $dh($s['status']) : '<small class="fm-muted">mai</small>' ?></td>
    </tr>
<?php endforeach; ?>
</table>

<h3 class="fm-text-base fm-mt-4">Deploy log (ultime 50 righe)</h3>
<?php if ($diag_logTail !== null): ?>
    <pre class="fm-keybox-scroll"><?= $dh(implode("\n", $diag_logTail)) ?></pre>
<?php else: ?>
    <p class="fm-muted">Log non leggibile (<?= $dh($diag_logPath) ?> — permessi o file mancante).</p>
<?php endif; ?>
