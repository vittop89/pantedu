<?php
/** @var array $request @var string $csrf @var array $user @var int $id */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = "Data Request #{$id}";
$page_subtitle = 'Richiesta GDPR — esegui acknowledgement / risposta / chiusura.';
$breadcrumb    = [
    ['label' => 'Data Requests', 'href' => '/admin/data-requests'],
    ['label' => "#{$id}"],
];
include __DIR__ . '/_partials/page_head.php';
?>

<dl class="fm-meta-grid">
    <dt>Aperta il</dt><dd><?= $h($request['created_at']) ?></dd>
    <dt>Stato</dt><dd><span class="fm-status fm-status--<?= $h($request['status']) ?>"><?= $h($request['status']) ?></span></dd>
    <dt>Acknowledged</dt><dd><?= $h($request['acknowledged_at'] ?? '—') ?></dd>
    <dt>Risposta</dt><dd><?= $h($request['responded_at'] ?? '—') ?></dd>
    <dt>Chiusa</dt><dd><?= $h($request['closed_at'] ?? '—') ?></dd>
    <dt>Richiedente</dt><dd><?= $h($request['name']) ?> &lt;<?= $h($request['email']) ?>&gt;</dd>
    <dt>Tipo</dt><dd><span class="fm-badge fm-badge--<?= $h($request['subject']) ?>"><?= $h($request['subject']) ?></span></dd>
    <dt>Minore</dt><dd><?= !empty($request['is_minor_related']) ? '⚠️ sì' : '—' ?></dd>
    <dt>Messaggio</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($request['message']) ?></pre></dd>
    <dt>Note DPO</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($request['dpo_notes'] ?? '—') ?></pre></dd>
</dl>

<div class="fm-card fm-mt-6" >
    <h2 class="fm-mt-0">Azioni</h2>
    <form method="POST" action="/admin/data-requests/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-4">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <label>Note (motivazione / risposta inviata / riferimenti):
            <textarea name="notes" required minlength="3" rows="4" class="fm-w-full"></textarea>
        </label>
        <div class="fm-d-flex fm-gap-3 fm-flex-wrap">
            <button type="submit" name="action" value="mark_acknowledged" class="fm-btn fm-btn--ghost">✉️ Acknowledge</button>
            <button type="submit" name="action" value="mark_responded"    class="fm-btn fm-btn--primary">✅ Risposto</button>
            <button type="submit" name="action" value="mark_closed"       class="fm-btn fm-btn--ghost">🔒 Chiudi</button>
            <button type="submit" name="action" value="mark_spam"         class="fm-btn fm-btn--danger">🚫 Spam</button>
        </div>
    </form>
</div>

</div>
