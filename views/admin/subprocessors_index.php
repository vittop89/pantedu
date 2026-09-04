<?php
/** @var list<array> $rows @var string $csrf @var array|null $flash @var array $user */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '🏢 Sub-processor (DPA art. 9)';
$page_subtitle = 'Lista responsabili esterni del trattamento. Pubblicata in /privacy/informativa §9.';
$breadcrumb    = [['label' => 'GDPR', 'href' => '/admin/gdpr'], ['label' => 'Sub-processor']];
include __DIR__ . '/_partials/page_head.php';
$gdpr_current = 'subprocessors';
include __DIR__ . '/_partials/gdpr_nav.php';
?>

<?php if ($flash): ?>
    <div class="fm-alert fm-alert--<?= $h($flash['type'] ?? 'info') ?>">
        <?= $h($flash['message'] ?? '') ?>
    </div>
<?php endif; ?>

<div class="fm-d-flex fm-justify-end fm-mb-4">
    <a href="/admin/subprocessors/new" class="fm-btn fm-btn--primary" data-full-reload>+ Nuovo sub-processor</a>
</div>

<?php if (empty($rows)): ?>
    <div class="fm-empty">Nessun sub-processor registrato.</div>
<?php else: ?>
    <table class="fm-table">
        <thead>
            <tr>
                <th scope="col">Nome</th><th scope="col">Servizio</th><th scope="col">Paese</th>
                <th scope="col">Extra-UE</th><th scope="col">DPA</th><th scope="col">Attivo</th><th scope="col">Azioni</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $sp):
                $id = (int)$sp['id'];
                $hasNotes = !empty($sp['notes']);
            ?>
                <tr>
                    <td>
                        <strong><?= $h($sp['name']) ?></strong>
                        <?php if ($hasNotes): ?>
                            <span title="Note giuridiche — vedi dettaglio" class="fm-text-em-md">ℹ️</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= $h($sp['service_description']) ?>
                        <?php if ($hasNotes): ?>
                            <details class="fm-mt-1 fm-text-em-md">
                                <summary class="fm-muted fm-cursor-pointer">📋 Note giuridiche</summary>
                                <div class="fm-warning-block">
                                    <?= $h($sp['notes']) ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </td>
                    <td><?= $h($sp['country']) ?></td>
                    <td><?= !empty($sp['extra_eu_transfer'])
                        ? '⚠️ sì <small>(' . $h($sp['transfer_safeguards'] ?? '—') . ')</small>'
                        : 'no' ?></td>
                    <?php /* Audit 25.R.31 — href solo se schema http/https: htmlspecialchars
                              NON blocca javascript:/data: → stored XSS al click. */ ?>
                    <?php $_dpaSafe = !empty($sp['dpa_url']) && preg_match('#^https?://#i', (string)$sp['dpa_url']); ?>
                    <td><?= !empty($sp['dpa_signed'])
                        ? ('✅ firmato' . ($_dpaSafe ? ' — <a href="' . $h($sp['dpa_url']) . '" target="_blank" rel="noopener noreferrer">link</a>' : ''))
                        : ($hasNotes ? '— <small>(vedi note)</small>' : '❌ non firmato') ?></td>
                    <td><?= !empty($sp['active']) ? '✅' : '❌' ?></td>
                    <td class="fm-d-flex fm-gap-1">
                        <a href="/admin/subprocessors/<?= $id ?>/edit" class="fm-btn fm-btn--sm fm-btn--ghost">✎ Modifica</a>
                        <?php /* Audit 25.R.31 — confirm generico: l'interpolazione del nome
                                  (htmlspecialchars decodificato dal parser HTML prima del JS)
                                  permetteva breakout della stringa JS. */ ?>
                        <form method="POST" action="/admin/subprocessors/<?= $id ?>/delete"
                              class="fm-d-inline">
                            <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
                            <button type="submit" class="fm-btn fm-btn--sm fm-btn--danger">🗑 Elimina</button>
                        </form>
                        <script>document.currentScript.previousElementSibling.addEventListener("submit",function(event){if(!confirm('Eliminare questo sub-processor?'))event.preventDefault()})</script>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

</div>
