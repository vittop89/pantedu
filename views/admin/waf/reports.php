<?php
/** @var array<string,string> $config */
/** @var list<array<string,mixed>> $top_ips */
/** @var list<array<string,mixed>> $top_countries */
/** @var list<array<string,mixed>> $score_dist */
/** @var list<array<string,mixed>> $rpm_outcome */
/** @var list<array<string,mixed>> $outcome_breakdown */
/** @var array{today:int,hour:int,last_5min:int,total:int,blocked_today:int} $counters */
/** @var array{blocked_credentials:int,blocked_ips_auth:int,blocked_ips_manual:int,blocked_ips_total:int} $auth_counters */
/** @var int $anomalies_count */
/** @var bool $enrich */
/** @var string $csrf */
$enrich = (bool)($enrich ?? false);
$current_tab = 'reports';
$page_title  = 'Reports unificato';
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// Score distribution: bucket 0-100, ogni 10
$dist = array_fill_keys(range(0, 100, 10), 0);
foreach ($score_dist as $row) {
    $b = (int)$row['bucket'];
    $dist[$b] = (int)$row['count'];
}
$maxDist = max(1, max($dist));
?>

<!-- ── UNIFIED KPI ROW ──────────────────────────────────────── -->
<h2 class="fm-m-0 fm-mb-2 fm-text-17">📊 Visione unificata sicurezza (ultimi 7 giorni)</h2>
<div class="fm-waf-counter">
    <div>
        <div class="num"><?= (int)$counters['today'] ?></div>
        <div class="lbl">WAF requests 24h</div>
    </div>
    <div>
        <div class="num danger"><?= (int)$counters['blocked_today'] ?></div>
        <div class="lbl">WAF blocked 24h</div>
    </div>
    <div>
        <div class="num warning"><?= (int)$auth_counters['blocked_credentials'] ?></div>
        <div class="lbl">Credentials blocked</div>
    </div>
    <div>
        <div class="num warning"><?= (int)$auth_counters['blocked_ips_total'] ?></div>
        <div class="lbl">IP blocked (auth + manual)</div>
    </div>
    <div>
        <div class="num danger"><?= (int)$anomalies_count ?></div>
        <div class="lbl">Anomalies detected</div>
    </div>
</div>

<p class="fm-muted fm-text-14 fm-mb-6" >
    Sintesi cross-layer unificata: WAF pre-route (geo + bot) + auth-flow protection
    (<a href="/admin/waf/blocks#ip-auth">Blocks → IP auth-flow</a>) + anomaly detection
    (<a href="/admin/waf/anomalies">Anomalies</a>) — storage DB centralizzato.
    Dettaglio IP: <?= (int)$auth_counters['blocked_ips_auth'] ?> auth-flow (per-section) +
    <?= (int)$auth_counters['blocked_ips_manual'] ?> manuali (WAF blacklist).
</p>

<!-- ── TOP COUNTRIES + GEOGRAPHIC DISTRIBUTION ──────────────── -->
<div class="fm-waf-grid-2 fm-mb-6" >

<section>
<h2 class="fm-text-17 fm-m-0 fm-mb-2">🌍 Top countries (7 giorni)</h2>
<table class="fm-waf-table">
<thead><tr><th scope="col">Country</th><th scope="col">Requests</th><th scope="col">Blocked</th><th scope="col">Block rate</th></tr></thead>
<tbody>
<?php foreach ($top_countries as $c):
    $count = (int)$c['count']; $blocked = (int)$c['blocked'];
    $rate = $count > 0 ? round($blocked / $count * 100, 1) : 0;
?>
    <tr>
        <td>
            <?php if (!empty($c['flag'])): ?>
                <span class="fm-waf-flag"><?= $c['flag'] ?></span>
            <?php endif; ?>
            <strong><?= $h($c['country'] ?? '?') ?></strong>
        </td>
        <td><?= $count ?></td>
        <td><?= $blocked ?></td>
        <td><?= $rate ?>%</td>
    </tr>
<?php endforeach; ?>
<?php if (empty($top_countries)): ?>
    <tr><td colspan="4" class="fm-waf-empty">Dati GeoIP insufficienti.</td></tr>
<?php endif; ?>
</tbody>
</table>
</section>

<section>
<h2 class="fm-text-17 fm-m-0 fm-mb-2">🥧 Outcome breakdown (7 giorni)</h2>
<table class="fm-waf-table">
<thead><tr><th scope="col">Outcome</th><th scope="col">Count</th></tr></thead>
<tbody>
<?php foreach ($outcome_breakdown as $o): ?>
    <tr>
        <td><span class="fm-waf-badge <?= $h($o['outcome']) ?>"><?= $h($o['outcome']) ?></span></td>
        <td><?= (int)$o['count'] ?></td>
    </tr>
<?php endforeach; ?>
<?php if (empty($outcome_breakdown)): ?>
    <tr><td colspan="2" class="fm-waf-empty">Dati insufficienti.</td></tr>
<?php endif; ?>
</tbody>
</table>
</section>

</div>

<!-- ── TOP IP 7 GIORNI ───────────────────────────────────────── -->
<h2 class="fm-text-17 fm-mt-4 fm-mb-2">🏆 Top 20 IP (7 giorni)</h2>
<?php include __DIR__ . '/_enrich_toggle.php'; ?>
<div class="fm-overflow-x-auto fm-mb-6">
<table class="fm-waf-table">
<thead><tr><th scope="col">IP</th><th scope="col">Country</th><?php if ($enrich): ?><th scope="col">rDNS</th><th scope="col">ASN</th><?php endif; ?><th scope="col">Last UA</th><th scope="col">Last outcome</th><th scope="col">Requests</th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($top_ips as $row): ?>
    <tr>
        <td><code><?= $h($row['ip']) ?></code></td>
        <td>
            <?php if (!empty($row['country_flag'])): ?>
                <span class="fm-waf-flag"><?= $row['country_flag'] ?></span>
            <?php endif; ?>
            <?= $h($row['country'] ?? '–') ?>
        </td>
        <?php if ($enrich): ?>
            <td>
                <?= !empty($row['rdns'])
                    ? '<span class="fm-waf-rdns">' . $h($row['rdns']) . '</span>'
                    : '<small class="fm-muted">–</small>' ?>
            </td>
            <td>
                <?php if (!empty($row['asn'])): ?>
                    <span class="fm-waf-asn">
                        <span class="fm-waf-asn__num">AS<?= (int)$row['asn'] ?></span>
                        <?php if (!empty($row['org'])): ?>
                            <span class="fm-waf-asn__org"><?= $h($row['org']) ?></span>
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <small class="fm-muted">–</small>
                <?php endif; ?>
            </td>
        <?php endif; ?>
        <td class="fm-max-w-220 fm-truncate fm-text-em-sm fm-text-muted"
            title="<?= $h($row['last_user_agent'] ?? '') ?>">
            <?= $h($row['last_user_agent'] ?? '–') ?>
        </td>
        <td><span class="fm-waf-badge <?= $h($row['last_outcome'] ?? '') ?>"><?= $h($row['last_outcome'] ?? '–') ?></span></td>
        <td><?= (int)$row['count'] ?></td>
        <td>
            <a class="fm-btn fm-btn--xs" href="/admin/waf/blocks#blacklist" title="Vai a Blocks → Blacklist per gestire">🛠️</a>
        </td>
    </tr>
<?php endforeach; ?>
<?php if (empty($top_ips)): ?>
    <tr><td colspan="<?= $enrich ? 8 : 6 ?>" class="fm-waf-empty">Dati insufficienti.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- ── SCORE DISTRIBUTION ─────────────────────────────────── -->
<h2 class="fm-text-17 fm-mt-4 fm-mb-2">📊 Score distribution (7 giorni)</h2>
<div class="fm-waf-histogram">
    <div class="fm-waf-histogram-bars">
    <?php foreach ($dist as $bucket => $count):
        $h_bar = $count > 0 ? max(2, ($count / $maxDist) * 180) : 2;
        $color = $bucket <= 40 ? '#10b981' : ($bucket <= 70 ? '#f59e0b' : '#ef4444');
    ?>
        <div class="fm-waf-histogram-bar">
            <span class="count"><?= $count ?></span>
            <div class="bar fm-bar-chart" style="--fm-bar-h:<?= (int)$h_bar ?>px;--fm-bar-color:<?= $color ?>"
                 title="Bucket <?= $bucket ?>-<?= $bucket+9 ?>: <?= $count ?>"></div>
            <span class="label"><?= $bucket ?></span>
        </div>
    <?php endforeach; ?>
    </div>
    <p class="fm-muted fm-text-13 fm-mt-2 fm-text-center" >
        Bucket score (0-100). 🟢 pass (≤40) · 🟡 soft (41-70) · 🔴 block (>70)
    </p>
</div>

<!-- ── RPM PER OUTCOME ───────────────────────────────────── -->
<h2 class="fm-text-17 fm-mt-4 fm-mb-2">⏱️ RPM per outcome (ultime 6 ore)</h2>
<div class="fm-overflow-x-auto fm-mb-6">
<table class="fm-waf-table">
<thead><tr><th scope="col">Minuto</th><th scope="col">Outcome</th><th scope="col">Count</th></tr></thead>
<tbody>
<?php
$shown = 0;
foreach (array_reverse($rpm_outcome) as $r):
    if ($shown++ >= 50) break;
?>
    <tr>
        <td><small><?= $h($r['minute']) ?></small></td>
        <td><span class="fm-waf-badge <?= $h($r['outcome']) ?>"><?= $h($r['outcome']) ?></span></td>
        <td><?= (int)$r['count'] ?></td>
    </tr>
<?php endforeach; ?>
<?php if (empty($rpm_outcome)): ?>
    <tr><td colspan="3" class="fm-waf-empty">Dati insufficienti.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>

<!-- ── DIAGNOSTICS ACCORDION (ex /admin/waf/diag merged here — Phase 25.R.22) ── -->
<section id="diagnostics" class="fm-mt-8">
<details>
    <summary class="fm-cursor-pointer fm-fw-600 fm-text-17 fm-py-2">
        🩺 Diagnostica sistema (GeoIP, CrowdSec, Honeypot, Threat Intel, Deploy log)
    </summary>
    <div class="fm-mt-4">
    <?php include __DIR__ . '/_diag_fragment.php'; ?>
    </div>
</details>
</section>

</div><!-- /.fm-card -->
