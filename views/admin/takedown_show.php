<?php
/**
 * Phase 25.R.3.1 — Takedown detail (refactor coerente con admin layout).
 *
 * @var array $request
 * @var string $csrf
 * @var array $user
 * @var int $id
 */

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$attachments = '';
if (!empty($request['attachments'])) {
    $att = is_string($request['attachments'])
        ? json_decode($request['attachments'], true)
        : $request['attachments'];
    if (is_array($att)) {
        foreach ($att as $a) {
            $attachments .= '<li>' . $h($a) . '</li>';
        }
    }
}

$page_title    = "Takedown #{$id}";
$page_subtitle = 'Dettaglio segnalazione — applica azione per chiudere il ticket.';
$breadcrumb    = [
    ['label' => 'Takedown', 'href' => '/admin/takedown'],
    ['label' => "#{$id}"],
];
$back_href     = '/admin/takedown';
$back_label    = 'Coda';

include __DIR__ . '/_partials/page_head.php';
?>

<dl class="fm-meta-grid">
    <dt>Submitted at</dt><dd><?= $h($request['submitted_at']) ?></dd>
    <dt>Status</dt><dd><span class="fm-status fm-status--<?= $h($request['status']) ?>"><?= $h($request['status']) ?></span></dd>
    <dt>Submitter</dt><dd><?= $h($request['submitter_name'] ?? '(anonimo)') ?> &lt;<?= $h($request['submitter_email'] ?? '—') ?>&gt;</dd>
    <dt>Role</dt><dd><?= $h($request['submitter_role']) ?></dd>
    <dt>IP origine</dt><dd><?= $h($request['submitter_ip'] ?? '—') ?></dd>
    <dt>Content ref</dt><dd><code><?= $h($request['content_ref']) ?></code></dd>
    <dt>Uploader user_id</dt><dd>#<?= $h($request['uploader_user_id'] ?? '—') ?></dd>
    <dt>Violation type</dt><dd><span class="fm-badge fm-badge--<?= $h($request['violation_type']) ?>"><?= $h($request['violation_type']) ?></span></dd>
    <dt>Description</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($request['description']) ?></pre></dd>
    <dt>Attachments</dt><dd><?= $attachments !== '' ? "<ul style=\"margin:0;padding-left:1.2em\">{$attachments}</ul>" : '—' ?></dd>
    <dt>Action taken</dt><dd><?= $h($request['action_taken'] ?? '—') ?></dd>
    <dt>Action notes</dt><dd><?= $h($request['action_notes'] ?? '—') ?></dd>
    <dt>Actioned at</dt><dd><?= $h($request['actioned_at'] ?? '—') ?></dd>
    <dt>Notified uploader</dt><dd><?= !empty($request['notified_uploader']) ? 'Sì' : 'No' ?></dd>
</dl>

<div class="fm-card fm-mt-6" >
    <h2 class="fm-mt-0">Azione da intraprendere</h2>
    <form method="POST" action="/admin/takedown/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-4">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

        <label>Note (motivazione decisione):
            <textarea name="notes" required minlength="20" rows="5"
                      placeholder="Motivazione + riferimenti normativi"
                      class="fm-w-full"></textarea>
        </label>

        <div class="fm-d-flex fm-gap-3 fm-flex-wrap">
            <button type="submit" name="action" value="removed"             class="fm-btn fm-btn--danger">🗑 Rimuovi contenuto</button>
            <button type="submit" name="action" value="suspended_user"      class="fm-btn fm-btn--warn">⏸ Sospendi uploader</button>
            <button type="submit" name="action" value="dismissed"           class="fm-btn fm-btn--ghost">✕ Rigetta segnalazione</button>
            <button type="submit" name="action" value="forwarded_authority" class="fm-btn fm-btn--primary">⚖ Inoltra ad autorità</button>
        </div>
    </form>
</div>

</div><?php /* /.fm-card aperto da page_head.php */ ?>
