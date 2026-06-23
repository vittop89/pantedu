<?php
/**
 * Phase 25.R.4.1 — DSR log (admin view sopra tabella dpo_requests).
 *
 * @var list<array> $rows
 * @var array<string,int> $counts
 * @var string|null $statusFilter
 * @var array $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

$page_title    = '🗃️ Data Requests (DSR)';
$page_subtitle = 'Richieste GDPR Art. 15-22 inviate via /dpo-contact. SLA risposta: 30 giorni.';
$breadcrumb    = [['label' => 'GDPR', 'href' => '/admin/gdpr'], ['label' => 'Data Requests']];
include __DIR__ . '/_partials/page_head.php';
$gdpr_current = 'requests';
include __DIR__ . '/_partials/gdpr_nav.php';
?>

<div class="fm-toolbar fm-d-flex fm-gap-1 fm-flex-wrap fm-m-0 fm-mb-4" >
    <?php
    $statuses = ['' => 'Tutti', 'open' => 'Open', 'acknowledged' => 'Acknowledged', 'responded' => 'Responded', 'closed' => 'Closed', 'spam' => 'Spam'];
    foreach ($statuses as $k => $label):
        $active = ($statusFilter ?? '') === $k;
        $href = '/admin/data-requests' . ($k !== '' ? '?status=' . $k : '');
        $count = $k === '' ? array_sum($counts ?? []) : (int)($counts[$k] ?? 0);
    ?>
        <a href="<?= $h($href) ?>" class="fm-btn fm-btn--ghost<?= $active ? ' is-active' : '' ?>" data-full-reload>
            <?= $h($label) ?> <span class="fm-muted">(<?= $count ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($rows)): ?>
    <div class="fm-empty">Nessuna richiesta.</div>
<?php else: ?>
    <table class="fm-table">
        <thead>
            <tr>
                <th scope="col">ID</th><th scope="col">Quando</th><th scope="col">Richiedente</th><th scope="col">Tipo</th>
                <th scope="col">Minore</th><th scope="col">Status</th><th scope="col">Azione</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r):
                $id = (int)$r['id'];
                $subj = (string)$r['subject'];
            ?>
                <tr>
                    <td>#<?= $id ?></td>
                    <td><?= $h($r['created_at']) ?></td>
                    <td><?= $h($r['name']) ?><br><small><?= $h($r['email']) ?></small></td>
                    <td><span class="fm-badge fm-badge--<?= $h($subj) ?>"><?= $h($subj) ?></span></td>
                    <td><?= !empty($r['is_minor_related']) ? '⚠️ sì' : '—' ?></td>
                    <td><span class="fm-status fm-status--<?= $h($r['status']) ?>"><?= $h($r['status']) ?></span></td>
                    <td><a href="/admin/data-requests/<?= $id ?>" class="fm-btn fm-btn--sm">Dettaglio →</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
