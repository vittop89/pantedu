<?php
/** @var array<string,string> $config */
/** @var array{today:int,hour:int,last_5min:int,total:int,blocked_today:int} $counters */
/** @var list<array<string,mixed>> $recent */
/** @var bool $enrich */
/** @var string $csrf */
$current_tab = 'dashboard';
$page_title  = 'Dashboard';
$enrich      = (bool)($enrich ?? false);
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>

<div class="fm-waf-counter">
    <div><div class="num"><?= (int)$counters['last_5min'] ?></div><div class="lbl">Last 5 min</div></div>
    <div><div class="num"><?= (int)$counters['hour'] ?></div><div class="lbl">Last hour</div></div>
    <div><div class="num"><?= (int)$counters['today'] ?></div><div class="lbl">Today</div></div>
    <div><div class="num"><?= (int)$counters['total'] ?></div><div class="lbl">Total logged</div></div>
    <div><div class="num danger"><?= (int)$counters['blocked_today'] ?></div><div class="lbl">Blocked 24h</div></div>
</div>

<?php /* Phase 25.R follow-up — Grafana live metrics integrato via iframe SSO
        in /admin/monitoring (auth_request nginx → super_admin gate). */ ?>
<section class="fm-info-banner fm-my-6" >
    📊 <strong>Live metrics Grafana</strong> — dashboard di sicurezza tempo reale
    (fail2ban rate · nginx rate · Suricata IDS alerts · WAF blocks · CrowdSec decisions)
    <a href="/admin/monitoring" class="fm-btn fm-btn--sm fm-btn--primary fm-ml-2" >
        Apri Monitor →
    </a>
</section>

<h2 class="fm-mt-6 fm-mb-2 fm-text-em-xl">📋 Ultime 50 richieste scrutinizzate</h2>
<?php include __DIR__ . '/_enrich_toggle.php'; ?>
<div class="fm-overflow-x-auto">
<table class="fm-waf-table" id="fm-waf-recent">
    <thead>
        <tr>
            <th scope="col">TS</th>
            <th scope="col">IP</th>
            <th scope="col">Country</th>
            <?php if ($enrich): ?><th scope="col">rDNS</th><th scope="col">ASN</th><?php endif; ?>
            <th scope="col">Method</th>
            <th scope="col">URI</th>
            <th scope="col">User-Agent</th>
            <th scope="col">Score</th>
            <th scope="col">Outcome</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($recent as $r): ?>
            <tr>
                <td><?= $h($r['ts'] ?? '') ?></td>
                <td><code><?= $h($r['ip'] ?? '') ?></code></td>
                <td><?= $h($r['country'] ?? '–') ?></td>
                <?php if ($enrich): ?>
                    <td>
                        <?= !empty($r['rdns'])
                            ? '<span class="fm-waf-rdns">' . $h($r['rdns']) . '</span>'
                            : '<small class="fm-muted">–</small>' ?>
                    </td>
                    <td>
                        <?php if (!empty($r['asn'])): ?>
                            <span class="fm-waf-asn">
                                <span class="fm-waf-asn__num">AS<?= (int)$r['asn'] ?></span>
                                <?php if (!empty($r['org'])): ?>
                                    <span class="fm-waf-asn__org"><?= $h($r['org']) ?></span>
                                <?php endif; ?>
                            </span>
                        <?php else: ?>
                            <small class="fm-muted">–</small>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td><?= $h($r['method'] ?? '') ?></td>
                <td class="fm-max-w-240 fm-truncate"
                    title="<?= $h($r['request_uri'] ?? '') ?>">
                    <?= $h($r['request_uri'] ?? '') ?>
                </td>
                <td class="fm-max-w-220 fm-truncate fm-text-em-sm fm-text-muted"
                    title="<?= $h($r['user_agent'] ?? '') ?>">
                    <?= $h($r['user_agent'] ?? '–') ?>
                </td>
                <td><?= $r['score'] !== null ? (int)$r['score'] : '–' ?></td>
                <td><span class="fm-waf-badge <?= $h($r['outcome'] ?? '') ?>">
                    <?= $h($r['outcome'] ?? '') ?>
                </span></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($recent)): ?>
            <tr><td colspan="<?= $enrich ? 10 : 8 ?>" class="fm-text-center fm-text-muted fm-p-8">Nessuna richiesta loggata. Abilita il WAF per iniziare a raccogliere dati.</td></tr>
        <?php endif; ?>
    </tbody>
</table>
</div>

<p class="fm-mt-4 fm-text-14 fm-text-muted">
    🔄 Auto-refresh ogni 10s. <a href="/admin/waf/dashboard" style="text-decoration:underline">Refresh manuale</a>
</p>

</div><!-- /.fm-card -->

<script>
// Auto-refresh dashboard ogni 10s
setTimeout(() => location.reload(), 10000);
</script>
