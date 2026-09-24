<?php
/**
 * Phase 25.R.3.1 — Takedown detail (refactor coerente con admin layout).
 *
 * @var array $request
 * @var string $csrf
 * @var array $user
 * @var int $id
 * @var string $casella casella delle segnalazioni (`mail.abuse_email`), '' se manca
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

<?php
/* 21/9/2026 — il motivo lo dice questa pagina, non l'indirizzo.
 *
 * Prima si stampava `$_GET['error']` così com'era: escapato, quindi non era
 * un'iniezione, ma un collegamento costruito ad arte faceva dire alla pagina
 * di amministrazione qualunque cosa («la sessione e' scaduta, riaccedi
 * qui…»). I codici che il controller manda sono pochi e noti: gli altri
 * diventano una frase generica, e il codice grezzo resta fuori.
 * Stesso schema degli errori del QR in views/auth/class_access.php.
 */
$erroriNoti = [
    'invalid_action'  => "l'azione richiesta non esiste.",
    'notes_required'  => 'serve una nota: le decisioni si motivano.',
    'Invalid status'  => 'lo stato richiesto non esiste.',
    'Invalid action'  => "l'azione richiesta non esiste.",
];
$codice  = isset($_GET['error']) ? (string)$_GET['error'] : null;
$perche  = $codice === null ? null : ($erroriNoti[$codice] ?? 'motivo non riconosciuto.');
?>
<?php if ($perche !== null): ?>
    <div class="fm-alert fm-alert--danger" role="alert">
        Azione non applicata: <?= $h($perche) ?>
    </div>
<?php elseif (isset($_GET['ok'])): ?>
    <div class="fm-alert fm-alert--success" role="status">Azione registrata.</div>
    <?php if (($_GET['notice'] ?? '') === 'uploader_not_notified'): ?>
        <div class="fm-alert fm-alert--warn" role="alert">
            Notifica automatica all'uploader <strong>non inviata</strong> (uploader non
            identificato, senza email, mailer o casella delle segnalazioni non configurati).
            Fase 4 della procedura va completata a mano
            <?php if (($casella ?? '') !== ''): ?>da <code><?= $h($casella) ?></code><?php else: ?>(casella
            delle segnalazioni non configurata: <code>ABUSE_EMAIL</code>)<?php endif; ?>:
            vedi il template §5.2 in <a href="/legal/takedown-procedure">Notice &amp; Takedown</a>.
        </div>
    <?php endif; ?>
<?php endif; ?>

<dl class="fm-meta-grid">
    <dt>Submitted at</dt><dd><?= $h($request['submitted_at']) ?></dd>
    <dt>Status</dt><dd><span class="fm-status fm-status--<?= $h($request['status']) ?>"><?= $h($request['status']) ?></span></dd>
    <dt>Submitter</dt><dd><?= $h($request['submitter_name'] ?? '(anonimo)') ?> &lt;<?= $h($request['submitter_email'] ?? '—') ?>&gt;</dd>
    <dt>Role</dt><dd><?= $h($request['submitter_role']) ?></dd>
    <dt>Impronta dell'IP</dt><dd><code><?= $h(substr((string)($request['submitter_ip'] ?? ''), 0, 16) ?: '—') ?></code></dd>
    <dt>Content ref</dt><dd><code><?= $h($request['content_ref']) ?></code></dd>
    <dt>Uploader user_id</dt><dd>#<?= $h($request['uploader_user_id'] ?? '—') ?></dd>
    <dt>Violation type</dt><dd><span class="fm-badge fm-badge--<?= $h($request['violation_type']) ?>"><?= $h($request['violation_type']) ?></span></dd>
    <dt>Description</dt><dd><pre class="fm-ws-pre-wrap fm-m-0"><?= $h($request['description']) ?></pre></dd>
    <dt>Attachments</dt><dd><?= $attachments !== '' ? "<ul style=\"margin:0;padding-left:1.2em\">{$attachments}</ul>" : '—' ?></dd>
    <dt>Action taken</dt><dd><?= $h($request['action_taken'] ?? '—') ?></dd>
    <dt>Action notes</dt><dd><?= $h($request['action_notes'] ?? '—') ?></dd>
    <dt>Actioned at</dt><dd><?= $h($request['actioned_at'] ?? '—') ?></dd>
    <dt>Notified uploader</dt><dd><?= !empty($request['notified_uploader'])
        ? 'Sì — ' . $h($request['notified_at'] ?? '')
        : 'No' ?></dd>
</dl>

<div class="fm-card fm-mt-6" >
    <h2 class="fm-mt-0">Azione da intraprendere</h2>
    <form method="POST" action="/admin/takedown/<?= (int)$id ?>/action" class="fm-d-flex fm-flex-col fm-gap-4">
        <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
        <input type="hidden" name="_audit_reason" value="Decisione sulla segnalazione di contenuti #<?= (int)$id ?> (notice and takedown)">

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
            <button type="submit" name="action" value="open_incident"       class="fm-btn fm-btn--warn" title="Rimuovere il contenuto non è valutare se ci sia stata una violazione: questo apre l'incidente art. 33">🚨 Apri incidente (art. 33)</button>
        </div>
    </form>
</div>

</div><?php /* /.fm-card aperto da page_head.php */ ?>
