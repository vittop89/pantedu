<?php
/** @var array $snapshot */
$db      = $snapshot['db']      ?? [];
$fs      = $snapshot['fs']      ?? [];
$bk      = $snapshot['backup']  ?? [];
$st      = $snapshot['storage'] ?? [];
$cnt     = $snapshot['counts']  ?? [];
$th      = $snapshot['thresholds'] ?? [];
$pill    = static function (string $level): string {
    return match ($level) {
        'critical' => '<span class="fm-pill fm-pill--critical">CRITICO</span>',
        'high'     => '<span class="fm-pill fm-pill--high">ALTO</span>',
        'warn'     => '<span class="fm-pill fm-pill--warn">ATTENZIONE</span>',
        default    => '<span class="fm-pill fm-pill--ok">OK</span>',
    };
};
$page_title    = '🏗️ Infrastruttura';
$page_subtitle = 'Metriche aggregate. Nessun dato personale studente. Accessi tracciati in privileged_access_log. Soglie: warn ≥ ' . (int)($th['warn'] ?? 70) . '% · high ≥ ' . (int)($th['high'] ?? 85) . '% · critical ≥ ' . (int)($th['critical'] ?? 95) . '%.';
$breadcrumb    = [['label' => 'Infrastruttura']];
include __DIR__ . '/_partials/page_head.php';
?>
<main class="fm-infra">
  <div class="fm-grid">
    <section class="fm-card">
      <h2>Database <?= $pill((string)($db['threshold'] ?? 'ok')) ?></h2>
      <dl>
        <dt>Abilitato</dt><dd><?= !empty($db['enabled']) ? 'sì' : 'no' ?></dd>
        <dt>Uso</dt>     <dd><?= isset($db['used_mb'])  ? htmlspecialchars((string)$db['used_mb'])  . ' MB' : 'n/d' ?></dd>
        <dt>Quota</dt>   <dd><?= isset($db['quota_mb']) ? htmlspecialchars((string)$db['quota_mb']) . ' MB' : 'n/d' ?></dd>
        <dt>% usato</dt> <dd><?= isset($db['pct'])      ? htmlspecialchars((string)$db['pct']) . '%' : 'n/d' ?></dd>
      </dl>
    </section>

    <section class="fm-card">
      <h2>Filesystem <?= $pill((string)($fs['threshold'] ?? 'ok')) ?></h2>
      <dl>
        <dt>Totale</dt>   <dd><?= htmlspecialchars((string)($fs['total_gb'] ?? 0)) ?> GB</dd>
        <dt>Libero</dt>   <dd><?= htmlspecialchars((string)($fs['free_gb']  ?? 0)) ?> GB</dd>
        <dt>% usato</dt>  <dd><?= (int)($fs['used_pct'] ?? 0) ?>%</dd>
      </dl>
    </section>

    <section class="fm-card">
      <h2>Backup</h2>
      <dl>
        <dt>DB ultimo</dt>   <dd><?= htmlspecialchars((string)($bk['db_last_iso']    ?? 'n/d')) ?></dd>
        <dt>File ultimo</dt> <dd><?= htmlspecialchars((string)($bk['files_last_iso'] ?? 'n/d')) ?></dd>
        <dt>Stato</dt>       <dd><?= !empty($bk['stale'])
            ? '<span class="fm-pill fm-pill--high">STALE</span>'
            : '<span class="fm-pill fm-pill--ok">FRESCO</span>' ?></dd>
      </dl>
    </section>

    <section class="fm-card">
      <h2>Storage oggetti</h2>
      <dl>
        <dt>Provider</dt> <dd><?= htmlspecialchars((string)($st['provider'] ?? '')) ?></dd>
        <dt>Oggetti</dt>  <dd><?= htmlspecialchars((string)($st['objects']  ?? 'n/d')) ?></dd>
        <dt>Peso</dt>     <dd><?= htmlspecialchars((string)($st['size_mb']  ?? 0)) ?> MB</dd>
      </dl>
    </section>

    <section class="fm-card">
      <h2>Conteggi aggregati</h2>
      <dl>
        <dt>Utenti</dt>       <dd><?= (int)($cnt['users_total']   ?? 0) ?></dd>
        <dt>Docenti</dt>      <dd><?= (int)($cnt['users_teacher'] ?? 0) ?></dd>
        <dt>Studenti</dt>     <dd><?= (int)($cnt['users_student'] ?? 0) ?></dd>
        <dt>Istituti</dt>     <dd><?= (int)($cnt['institutes']    ?? 0) ?></dd>
        <dt>Materiali</dt>    <dd><?= (int)($cnt['materials']     ?? 0) ?></dd>
        <dt>Reg. pending</dt> <dd><?= (int)($cnt['pending_regs']  ?? 0) ?></dd>
      </dl>
    </section>

    <?php /* Grafana spostato in /admin/waf/dashboard — semanticamente è un
            dashboard di sicurezza (WAF + IDS + fail2ban + nginx rate alerts),
            non infrastructure. */ ?>
  </div>
</main>
</div><!-- /.fm-card -->
