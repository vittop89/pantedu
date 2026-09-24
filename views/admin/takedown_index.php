<?php
/**
 * Phase 25.R.3.1 — Takedown queue index (refactor da standalone HTML hardcoded
 * a layout admin coerente con dashboard/tools/templates/waf).
 *
 * @var list<array> $pending
 * @var string|null $statusFilter
 * @var array $user
 */

$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$page_title    = '🛡️ Takedown Queue';
$page_subtitle = 'Coda Notice & Takedown — gestione segnalazioni contenuti.';
$breadcrumb    = [['label' => 'Takedown']];
$back_href     = '/admin/dashboard';
$back_label    = 'Admin Dashboard';

include __DIR__ . '/_partials/page_head.php';
?>

<div class="fm-toolbar fm-m-0 fm-mb-4 fm-d-flex fm-gap-1 fm-flex-wrap" >
    <?php
    $tabs = [
        ''             => 'Aperti (default)',
        'new'          => 'New',
        'under_review' => 'Under review',
        'actioned'     => 'Actioned',
        'rejected'     => 'Rejected',
        'closed'       => 'Closed',
    ];
    foreach ($tabs as $key => $label):
        $href = '/admin/takedown' . ($key !== '' ? '?status=' . $key : '');
        $active = ($statusFilter ?? null) === $key || ($statusFilter === null && $key === '');
    ?>
        <a href="<?= $h($href) ?>"
           class="fm-btn fm-btn--ghost<?= $active ? ' is-active' : '' ?>"
           data-full-reload><?= $h($label) ?></a>
    <?php endforeach; ?>
</div>

<p class="fm-muted fm-m-0 fm-mb-4" >
    <?= count($pending) ?> entries —
    <?= $h($statusFilter ? "filter: {$statusFilter}" : 'open queue') ?>
</p>

<?php if (empty($pending)): ?>
    <div class="fm-empty">Nessuna segnalazione in coda 🎉</div>
<?php else: ?>
    <table class="fm-table">
        <thead>
            <tr>
                <th scope="col">ID</th>
                <th scope="col">Submitted</th>
                <th scope="col">Tipo</th>
                <th scope="col">Segnalante</th>
                <th scope="col">Contenuto</th>
                <th scope="col">Status</th>
                <th scope="col">Action</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($pending as $r):
                $id   = (int)$r['id'];
                $type = (string)$r['violation_type'];
                $stat = (string)$r['status'];
                $uploaderUid = (int)($r['uploader_user_id'] ?? 0);
            ?>
                <tr>
                    <td>#<?= $id ?></td>
                    <td><?= $h($r['submitted_at']) ?></td>
                    <td><span class="fm-badge fm-badge--<?= $h($type) ?>"><?= $h($type) ?></span></td>
                    <td>
                        <?= $h($r['submitter_role']) ?><br>
                        <small><?= $h($r['submitter_name'] ?? '(anonimo)') ?></small><br>
                        <small><?= $h($r['submitter_email'] ?? '—') ?></small>
                    </td>
                    <td>
                        <code><?= $h($r['content_ref']) ?></code><br>
                        <small>uploader: #<?= $uploaderUid ?></small>
                    </td>
                    <td><span class="fm-status fm-status--<?= $h($stat) ?>"><?= $h($stat) ?></span></td>
                    <td>
                        <a href="/admin/takedown/<?= $id ?>" class="fm-btn fm-btn--sm">Dettaglio →</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div><?php /* /.fm-card aperto da page_head.php */ ?>
