<?php
/** @var array<string,string> $config */
/** @var array{today:int,hour:int,last_5min:int,total:int,blocked_today:int} $counters */
/** @var list<array<string,mixed>> $recent */
/** @var bool $enrich */
/** @var array{outcome:?string,limit:int,dalle:?string,alle:?string} $filtro */
/** @var list<string> $esiti */
/** @var string $csrf */
$current_tab = 'dashboard';
$page_title  = 'Dashboard';
$enrich      = (bool)($enrich ?? false);
include __DIR__ . '/_layout_head.php';
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);

// Chi guarda una fascia oraria sta leggendo il passato: la pagina non si
// aggiorna da sola, altrimenti ricaricherebbe sopra la finestra scelta.
// Senza filtro i contatori restano dal vivo, ogni dieci secondi.
$guardaIlPassato = ($filtro['dalle'] ?? null) || ($filtro['alle'] ?? null);
?>

<div class="fm-waf-counter"<?= $guardaIlPassato ? '' : ' data-fm-autorefresh="10000"' ?>>
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

<h2 class="fm-mt-6 fm-mb-2 fm-text-em-xl">📋 Richieste scrutinizzate<?= isset($filtro) && ($filtro["dalle"] || $filtro["alle"]) ? " nella finestra scelta" : " (le più recenti)" ?> — <?= count($recent) ?></h2>

<?php /* 21/9/2026 — la finestra oraria. Prima la tabella dava le ultime
         cinquanta richieste e basta: durante una lezione sono pochi secondi,
         e alla domanda «che cosa è successo fra le dieci e le undici» non si
         poteva rispondere. Gli istanti sono quelli del server, e il campo li
         accetta anche scritti a mano. */ ?>
<form method="get" action="/admin/waf/dashboard" class="fm-waf-filtro fm-d-flex fm-gap-2 fm-items-end fm-flex-wrap fm-mb-2">
    <label class="fm-text-13">Dalle
        <input type="datetime-local" name="dalle" class="fm-input"
               value="<?= $h(str_replace(' ', 'T', substr((string)($filtro['dalle'] ?? ''), 0, 16))) ?>">
    </label>
    <label class="fm-text-13">Alle
        <input type="datetime-local" name="alle" class="fm-input"
               value="<?= $h(str_replace(' ', 'T', substr((string)($filtro['alle'] ?? ''), 0, 16))) ?>">
    </label>
    <label class="fm-text-13">Esito
        <select name="outcome" class="fm-input">
            <option value="">tutti</option>
            <?php foreach (($esiti ?? []) as $e): ?>
                <option value="<?= $h($e) ?>"<?= ($filtro['outcome'] ?? null) === $e ? ' selected' : '' ?>><?= $h($e) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="fm-text-13">Quante
        <input type="number" name="limit" class="fm-input" min="10" max="1000" step="10"
               value="<?= (int)($filtro['limit'] ?? 50) ?>" style="max-width:7em">
    </label>
    <?php if ($enrich): ?><input type="hidden" name="enrich" value="1"><?php endif; ?>
    <button type="submit" class="fm-btn fm-btn--primary fm-btn--sm">Filtra</button>
    <a class="fm-btn fm-btn--sm" href="/admin/waf/dashboard">Azzera</a>
</form>
<p class="fm-muted fm-text-13 fm-m-0">
    Gli orari sono quelli del server (<?= $h(date('T, H:i')) ?> adesso). Senza
    finestra si vedono le più recenti; il massimo è mille richieste per volta.
</p>
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

