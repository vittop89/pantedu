<?php
/** @var list<array<string,mixed>> $rows */
/** @var string $currentVersion */
/** @var int $outdated */

$page_title = '📋 ToS Acceptance Log';
$breadcrumb = [['href' => '/admin', 'label' => 'Admin']];
$back_href  = '/admin';
$back_label = '← Torna alla Dashboard';
include __DIR__ . '/_partials/page_head.php';
?>

<section class="fm-admin-kpi">
    <h2 class="fm-admin-kpi__title">Stato accettazione Termini di Servizio</h2>
    <p class="fm-muted">
        Versione corrente: <strong><?= htmlspecialchars($currentVersion, ENT_QUOTES) ?></strong>.
        Utenti con versione non aggiornata: <strong><?= (int)$outdated ?></strong>.
    </p>
</section>

<section class="fm-mt-8">
    <?php if (empty($rows)): ?>
        <p class="fm-muted">Nessun utente teacher/admin registrato.</p>
    <?php else: ?>
        <table class="fm-table">
            <thead>
                <tr>
                    <th scope="col">Username</th>
                    <th scope="col">Ruolo</th>
                    <th scope="col">ToS ver.</th>
                    <th scope="col">AUP ver.</th>
                    <th scope="col">Accettato il</th>
                    <th scope="col">IP</th>
                    <th scope="col">User-Agent</th>
                    <th scope="col">Stato</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r):
                    $accepted = $r['tos_version'] !== null;
                    $aligned  = $accepted && ((string)$r['tos_version'] === $currentVersion);
                    $stateLabel = !$accepted ? '❌ Mai accettato' : ($aligned ? '✅ Aggiornato' : '⚠️ Non aggiornato');
                    $stateClass = !$accepted ? 'fm-status--danger' : ($aligned ? 'fm-status--ok' : 'fm-status--warn');
                ?>
                    <tr>
                        <td><span class="fm-code"><?= htmlspecialchars((string)$r['username'], ENT_QUOTES) ?></span></td>
                        <td><span class="fm-status" data-role="<?= htmlspecialchars((string)$r['role'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$r['role'], ENT_QUOTES) ?></span></td>
                        <td><?= htmlspecialchars((string)($r['tos_version'] ?? '—'), ENT_QUOTES) ?></td>
                        <td><?= htmlspecialchars((string)($r['aup_version'] ?? '—'), ENT_QUOTES) ?></td>
                        <td><span class="fm-code"><?= htmlspecialchars((string)($r['accepted_at'] ?? '—'), ENT_QUOTES) ?></span></td>
                        <td><span class="fm-code"><?= htmlspecialchars((string)($r['accepted_ip'] ?? '—'), ENT_QUOTES) ?></span></td>
                        <td class="fm-max-w-280 fm-truncate" title="<?= htmlspecialchars((string)($r['user_agent'] ?? ''), ENT_QUOTES) ?>">
                            <?= htmlspecialchars(mb_substr((string)($r['user_agent'] ?? '—'), 0, 60), ENT_QUOTES) ?>
                        </td>
                        <td><span class="fm-status <?= $stateClass ?>"><?= $stateLabel ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

</div><!-- /.fm-card -->
