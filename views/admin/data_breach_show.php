<?php
/** @var array $incident @var string $csrf @var array $user @var int $id */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = "🚨 Incident #{$id}";
$page_subtitle = 'Aggiorna stato, registra notifiche al Garante e agli utenti.';
$breadcrumb    = [
    ['label' => 'Data Breach', 'href' => '/admin/data-breach'],
    ['label' => "#{$id}"],
];
include __DIR__ . '/_partials/page_head.php';

$detectedTs = strtotime((string)$incident['detected_at']) ?: time();
$deadlineTs = $detectedTs + 72 * 3600;
$slaRemaining = $deadlineTs - time();
$slaText = $slaRemaining > 0
    ? sprintf('⏳ %d ore rimanenti', (int)floor($slaRemaining / 3600))
    : '❌ SLA Art. 33 scaduto';
?>

<dl class="fm-meta-grid">
    <dt>Avvenuto</dt><dd><?= $h($incident['occurred_at']) ?></dd>
    <dt>Rilevato</dt><dd><?= $h($incident['detected_at']) ?></dd>
    <dt>SLA 72h Art. 33</dt><dd><?= $h($slaText) ?> (scade <?= $h(date('Y-m-d H:i', $deadlineTs)) ?>)</dd>
    <dt>Severity</dt><dd><span class="fm-badge fm-badge--severity-<?= $h($incident['severity']) ?>"><?= $h($incident['severity']) ?></span></dd>
    <dt>Status</dt><dd><span class="fm-status fm-status--<?= $h($incident['status']) ?>"><?= $h($incident['status']) ?></span></dd>
    <dt>Utenti coinvolti</dt><dd><?= $incident['affected_users_count'] !== null ? (int)$incident['affected_users_count'] : '—' ?></dd>
    <dt>Categorie dati</dt><dd><?= $h($incident['data_categories'] ?? '—') ?></dd>
    <dt>Descrizione</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($incident['description']) ?></pre></dd>
    <dt>Root cause</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($incident['root_cause'] ?? '—') ?></pre></dd>
    <dt>Mitigazioni</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($incident['remedial_actions'] ?? '—') ?></pre></dd>
    <dt>Notif. Garante</dt><dd><?= !empty($incident['notified_garante_at']) ? ('✅ ' . $h($incident['notified_garante_at']) . ' — ref: ' . $h($incident['garante_ref'] ?? '—')) : '—' ?></dd>
    <dt>Notif. utenti</dt><dd><?= !empty($incident['notified_users_at']) ? ('✅ ' . $h($incident['notified_users_at']) . ' via ' . $h($incident['users_notification_method'] ?? '?')) : '—' ?></dd>
    <?php /* 24/9/2026 — il titolare esterno e il suo avviso (migrazione 136) si scrivevano e non si vedevano. */ ?>
    <dt>Titolare esterno</dt><dd><?= $h(($incident['titolare_esterno'] ?? '') !== '' ? $incident['titolare_esterno'] : '—') ?></dd>
    <dt>Avviso al titolare</dt><dd><?= !empty($incident['notified_controller_at']) ? ('✅ ' . $h($incident['notified_controller_at']) . ' via ' . $h($incident['controller_notification_method'] ?? '?')) : '—' ?></dd>
    <dt>Aperto da</dt><dd>user #<?= (int)($incident['reported_by_user_id'] ?? 0) ?></dd>
    <dt>Chiuso</dt><dd><?= $h($incident['closed_at'] ?? '—') ?></dd>
</dl>

<div class="fm-card fm-mt-6" >
    <h2 class="fm-mt-0">Notifica al Garante (Art. 33)</h2>
    <form method="POST" action="/admin/data-breach/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-2 fm-max-w-lg">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Incidente #<?= (int)$id ?>: notifica al Garante registrata (art. 33 GDPR)">
        <input type="hidden" name="action" value="notify_garante">
        <label>Riferimento Garante (ID procedimento)
            <input type="text" name="garante_ref" maxlength="128"
                   class="fm-w-full">
        </label>
        <button type="submit" class="fm-btn fm-btn--warn fm-self-start" >📨 Marca notificato al Garante</button>
    </form>
</div>

<div class="fm-card fm-mt-4" >
    <h2 class="fm-mt-0">Avviso al titolare dei dati (quando non siamo noi)</h2>
    <p class="fm-muted fm-text-13 fm-mt-0">Quando i dati coinvolti sono di un altro titolare &mdash; l'Istituto di un docente, per un dato scritto in un campo a testo libero &mdash; va avvisato <strong>senza ingiustificato ritardo</strong>. Una volta registrato, l'avviso non si pu&ograve; disdire.</p>
    <form method="POST" action="/admin/data-breach/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-2 fm-max-w-lg">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Incidente #<?= (int)$id ?>: avviso al titolare dei dati registrato">
        <input type="hidden" name="action" value="notify_controller">
        <label>Come
            <select name="method" required class="fm-w-full">
                <option value="pec">PEC</option>
                <option value="email">Email</option>
                <option value="telefono">Telefono, con nota scritta</option>
                <option value="raccomandata">Raccomandata</option>
            </select>
        </label>
        <button type="submit" class="fm-btn fm-btn--warn fm-self-start">🏫 Marca avvisato il titolare</button>
    </form>
</div>

<div class="fm-card fm-mt-4" >
    <h2 class="fm-mt-0">Notifica utenti (Art. 34 — rischio elevato)</h2>
    <form method="POST" action="/admin/data-breach/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-2 fm-max-w-lg">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Incidente #<?= (int)$id ?>: notifica agli interessati registrata (art. 34 GDPR)">
        <input type="hidden" name="action" value="notify_users">
        <label>Metodo
            <select name="method" required class="fm-w-full">
                <option value="email">Email diretta</option>
            </select>
        </label>
        <button type="submit" class="fm-btn fm-btn--primary fm-self-start" >📢 Marca notificato agli utenti</button>
    </form>
</div>

<div class="fm-card fm-mt-4" >
    <h2 class="fm-mt-0">Cambia status</h2>
    <form method="POST" action="/admin/data-breach/<?= (int)$id ?>/action" class="fm-d-flex fm-gap-2 fm-items-end fm-flex-wrap">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Incidente #<?= (int)$id ?>: cambio di stato nel registro delle violazioni">
        <input type="hidden" name="action" value="set_status">
        <label>Status
            <select name="status" required>
                <?php foreach (['detected','assessing','notified_garante','notified_users','closed'] as $s): ?>
                    <option value="<?= $s ?>"<?= $incident['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button type="submit" class="fm-btn fm-btn--ghost">Aggiorna</button>
    </form>
</div>

</div>
