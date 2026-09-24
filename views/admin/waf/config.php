<?php
/** @var array<string,string> $config */
/** @var string $csrf */
$current_tab = 'config';
$page_title  = 'Configurazione';
include __DIR__ . '/_layout_head.php';

$cfg = $config;
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<nav class="fm-waf-blocks-toc">
    <a href="#waf-master">🚦 WAF</a>
    <a href="#threat-intel-config">🌍 Threat Intel</a>
    <a href="#anomaly-thresholds">🚨 Anomaly thresholds</a>
</nav>

<section id="waf-master">

<form data-fm-action="submitConfig" id="fm-waf-config-form">
<input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">🚦 Master switch</h2>
<div class="fm-waf-kv fm-mb-6" >
    <label for="cfg_enabled">Enabled</label>
    <select id="cfg_enabled" name="enabled">
        <option value="1" <?= $cfg['enabled'] === '1' ? 'selected' : '' ?>>ON</option>
        <option value="0" <?= $cfg['enabled'] !== '1' ? 'selected' : '' ?>>OFF (bypass totale)</option>
    </select>

    <label for="cfg_mode">Mode</label>
    <select id="cfg_mode" name="mode">
        <?php foreach (['off','monitor','soft','enforce','under_attack'] as $m): ?>
            <option value="<?= $h($m) ?>" <?= ($cfg['mode'] ?? 'monitor') === $m ? 'selected' : '' ?>>
                <?= $h($m) ?>
                <?php
                    $tip = [
                        'off' => 'nessuna azione',
                        'monitor' => 'solo log, mai blocco',
                        'soft' => 'blocco solo score alto',
                        'enforce' => 'tutte le azioni applicate (PROD)',
                        'under_attack' => 'ogni request senza cookie → interstitial',
                    ];
                    echo ' — ' . $h($tip[$m] ?? '');
                ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">📊 Soglie score</h2>
<div class="fm-waf-kv fm-mb-6" >
    <label for="cfg_pass">Threshold pass</label>
    <input type="number" min="0" max="99" id="cfg_pass" name="threshold_pass" value="<?= $h($cfg['threshold_pass'] ?? '40') ?>">

    <label for="cfg_block">Threshold block</label>
    <input type="number" min="1" max="100" id="cfg_block" name="threshold_block" value="<?= $h($cfg['threshold_block'] ?? '70') ?>">

    <label for="cfg_ttl">Session TTL (s)</label>
    <input type="number" min="60" max="86400" id="cfg_ttl" name="session_ttl" value="<?= $h($cfg['session_ttl'] ?? '3600') ?>">

    <label for="cfg_template">Challenge template</label>
    <select id="cfg_template" name="challenge_template">
        <?php foreach (['invisible','interstitial','under_attack'] as $t): ?>
            <option value="<?= $h($t) ?>" <?= ($cfg['challenge_template'] ?? 'invisible') === $t ? 'selected' : '' ?>>
                <?= $h($t) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">🌍 GeoIP</h2>
<div class="fm-waf-kv fm-mb-6" >
    <label for="cfg_geomode">Geo mode</label>
    <select id="cfg_geomode" name="geo_mode">
        <?php foreach (['off','monitor','enforce'] as $m): ?>
            <option value="<?= $h($m) ?>" <?= ($cfg['geo_mode'] ?? 'monitor') === $m ? 'selected' : '' ?>><?= $h($m) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="cfg_geoallow">Paesi ammessi (CSV ISO-3166-1 alpha-2)</label>
    <input type="text" id="cfg_geoallow" name="geo_allowed" value="<?= $h($cfg['geo_allowed'] ?? 'IT') ?>"
           placeholder="IT,SM,VA,CH">
</div>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">🗄️ Retention</h2>
<div class="fm-waf-kv fm-mb-6" >
    <label for="cfg_retention">Log retention (giorni)</label>
    <input type="number" min="1" max="365" id="cfg_retention" name="log_retention_days" value="<?= $h($cfg['log_retention_days'] ?? '7') ?>">
</div>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17" id="threat-intel-config">🌍 Threat Intelligence</h2>
<p class="fm-muted fm-text-14 fm-m-0 fm-mb-2" >
    Master toggle per controllo feed esterni (CrowdSec, Spamhaus, Tor, X4BNet, bad-ASN list).
    Sorgenti + sync UI: <a href="/admin/waf/blocks#threat-intel" style="text-decoration:underline">🚫 Blocks & Anomalies → Threat Intel</a>.
</p>
<div class="fm-waf-kv fm-mb-4" >
    <label for="cfg_ti_enabled">Threat Intel enabled</label>
    <select id="cfg_ti_enabled" name="threat_intel_enabled">
        <option value="1" <?= ($cfg['threat_intel_enabled'] ?? '1') === '1' ? 'selected' : '' ?>>ON</option>
        <option value="0" <?= ($cfg['threat_intel_enabled'] ?? '1') !== '1' ? 'selected' : '' ?>>OFF</option>
    </select>
    <label for="cfg_crowdsec_key">CrowdSec API key</label>
    <?php /* Audit 25.R.31 — segreto: type=password + autocomplete=off (era type=text
              → chiave in chiaro nel DOM/sorgente HTML). */ ?>
    <input type="password" id="cfg_crowdsec_key" name="crowdsec_api_key"
           value="<?= $h($cfg['crowdsec_api_key'] ?? '') ?>"
           placeholder="Free signup: https://app.crowdsec.net"
           autocomplete="off"
           class="fm-font-mono fm-text-em-md">
</div>
<p class="fm-muted fm-text-13 fm-mt-n2 fm-mb-6" >
    CrowdSec community blocklist richiede signup gratuito → Machine API key.
    Senza chiave il layer CrowdSec è disabilitato (altri 4 layer continuano).
    Auto-sync cron: vedi systemd units <code>tools/systemd/waf-threat-intel-*.{service,timer}</code>.
</p>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17">🔍 Enrichment IP tables</h2>
<div class="fm-waf-kv fm-mb-6" >
    <label for="cfg_enrich">RDNS &amp; ASN admin toggle</label>
    <select id="cfg_enrich" name="enrich_rdns_asn">
        <option value="0" <?= ($cfg['enrich_rdns_asn'] ?? '0') !== '1' ? 'selected' : '' ?>>OFF (default, fast)</option>
        <option value="1" <?= ($cfg['enrich_rdns_asn'] ?? '0') === '1' ? 'selected' : '' ?>>ON (admin può attivare colonne rDNS+ASN)</option>
    </select>
</div>
<p class="fm-muted fm-text-13 fm-mt-n4 fm-mb-6" >
    Se ON, ogni pagina admin con tabelle IP (Lists / Reports / Credentials / Dashboard)
    espone un toggle "🔍 RDNS &amp; ASN" che mostra colonne extra con reverse DNS
    (~50-2000ms/IP) e ASN+org (mmdb lookup). Disattivato di default per performance.
    Richiede <code>WAF_GEOIP_ASN_DB</code> in .env (db-ip ASN Lite mmdb).
</p>

<h2 class="fm-mt-4 fm-mb-2 fm-text-17" id="csp-mode">🛡️ Content-Security-Policy (anti-XSS)</h2>
<?php $_csp = $cfg['csp_mode'] ?? 'relaxed'; ?>
<div class="fm-waf-kv fm-mb-2" >
    <label for="cfg_csp_mode">Modalità CSP</label>
    <select id="cfg_csp_mode" name="csp_mode">
        <option value="relaxed"     <?= $_csp === 'relaxed'     ? 'selected' : '' ?>>relaxed — inline ammesso (default sicuro, niente unsafe-eval)</option>
        <option value="report-only" <?= $_csp === 'report-only' ? 'selected' : '' ?>>report-only — policy strict SOLO segnalata (non blocca, raccoglie violazioni)</option>
        <option value="strict"      <?= $_csp === 'strict'      ? 'selected' : '' ?>>strict — nonce + strict-dynamic ENFORCE (blocca script iniettati)</option>
    </select>
</div>
<p class="fm-muted fm-text-13 fm-mb-6" >
    Rollout consigliato: <strong>report-only</strong> per qualche giorno (controlla la console / i report
    che nessuna funzione legittima venga segnalata), poi <strong>strict</strong>. Se qualcosa si rompe,
    torna a <strong>relaxed</strong> da qui — effetto immediato, nessun redeploy. Tutte le view server-side
    sono già prive di handler inline; gli attributi <code>style=</code> restano ammessi.
    Override runtime di <code>CSP_MODE</code> in <code>.env</code> (questo valore ha precedenza).
</p>

<div class="fm-d-flex fm-gap-2 fm-mt-6">
    <button type="submit" class="fm-btn fm-btn--primary">💾 Salva configurazione</button>
    <span id="fm-waf-save-status" class="fm-inline-status fm-self-center" ></span>
</div>
</form>
</section>

<!-- ────────── ANOMALY DETECTION THRESHOLDS (ex /admin/waf/anomalies → migrato qui) ────────── -->
<section id="anomaly-thresholds" class="fm-waf-block-section">
<h2 class="fm-mt-4 fm-mb-2 fm-text-17">🚨 Soglie rilevamento anomalie (low / medium / high)</h2>
<p class="fm-muted fm-text-14 fm-mb-2" >
    Configurazione <code>AnomalyDetectionService</code>: definisce <strong>excessive_access</strong>
    (troppi accessi alla stessa sezione) e <strong>credential_sharing</strong> (stesso utente da troppi IP).
    La <strong>lista anomalie rilevate</strong> in tempo reale è in
    <a href="/admin/waf/blocks#anomalies-detected" style="text-decoration:underline">🛡️ Blocks → Anomalies detected</a>.
</p>
<form data-fm-action="saveAnomalyConfig" id="fm-sec-cfg-form">
    <div id="fm-sec-cfg-body" class="fm-muted">Caricamento…</div>
    <div class="fm-d-flex fm-gap-2 fm-mt-4">
        <button type="submit" class="fm-btn fm-btn--primary">💾 Salva soglie</button>
        <?php /* 2026-09-05 — il refactor dello script inline aveva spezzato questi due bottoni
                 (testo e classe mescolati al vecchio <script>); l'azione è dichiarativa. */ ?>
        <button type="button" class="fm-btn fm-btn--ghost" data-fm-action="loadAnomalyConfig">🔄 Ricarica</button>
        <span id="fm-sec-cfg-status" class="fm-text-15 fm-self-center"></span>
    </div>
</form>
</section>

</div><!-- /.fm-card -->

<div id="fm-page-config" hidden data-csrf="<?= $h($csrf) ?>"></div>
<?= \App\Support\ViteManifest::script('js/entries/admin-waf-config.js') ?>

</div><!-- /.fm-card -->
