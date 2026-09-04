<?php
/**
 * GDPR sub-nav — Phase 25.R.22 hub unification.
 *
 * Mostra tab tra le 3 aree GDPR (Data Requests, Data Breach, Sub-processors).
 * Include subito dopo page_head.php in:
 *   - data_requests_index.php  → $gdpr_current = 'requests'
 *   - data_breach_index.php    → $gdpr_current = 'breach'
 *   - subprocessors_index.php  → $gdpr_current = 'subprocessors'
 *
 * @var ?string $gdpr_current  identificatore tab attiva
 */
$gdpr_current = $gdpr_current ?? '';
$gdpr_tabs = [
    ['key' => 'requests',         'href' => '/admin/data-requests',         'label' => '🗃️ Data Requests'],
    ['key' => 'breach',           'href' => '/admin/data-breach',           'label' => '🚨 Data Breach'],
    ['key' => 'subprocessors',    'href' => '/admin/subprocessors',         'label' => '🏢 Sub-processors'],
    ['key' => 'authority-export', 'href' => '/admin/gdpr/authority-export', 'label' => '⚖️ Authority Export'],
];
?>
<nav class="fm-admin-tabs">
<?php foreach ($gdpr_tabs as $t): ?>
    <a class="fm-admin-tab <?= $t['key'] === $gdpr_current ? 'is-active' : '' ?>"
       href="<?= htmlspecialchars($t['href'], ENT_QUOTES) ?>" data-full-reload>
        <?= htmlspecialchars($t['label'], ENT_QUOTES) ?>
    </a>
<?php endforeach; ?>
</nav>
