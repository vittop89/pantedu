<?php
/**
 * Phase 25.R.25 — Admin Logs unified panel.
 *
 * @var array<string,string> $tabs        key=table_name → label
 * @var string               $current     tab attivo
 * @var string               $csrf
 * @var array                $user
 */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$page_title    = '📜 Admin Logs — Vista unificata';
$page_subtitle = 'Tutti i registri applicativi: attività di ogni ruolo, eventi contenuti, accessi privilegiati, consensi (genitoriale compreso), operazioni crypto, chiavi di recupero, custody KMS.';
$breadcrumb    = [['label' => 'Logs']];
include __DIR__ . '/_partials/page_head.php';
?>

<?php // Il tab attivo passa al modulo via data-current; i click li gestisce js/entries/admin-logs.js (P8, 2026-09-04). ?>
<nav class="fm-admin-tabs fm-m-0 fm-mb-6" data-current="<?= $h($current) ?>">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="fm-admin-tab <?= $key === $current ? 'is-active' : '' ?>"
           href="/admin/logs?tab=<?= $h($key) ?>" data-tab="<?= $h($key) ?>">
            <?= $h($label) ?>
        </a>
    <?php endforeach; ?>
</nav>

<section class="fm-card fm-mb-4" >
    <h2 class="fm-mt-0 fm-text-17">🔎 Filtri</h2>
    <div class="fm-form-grid">
        <label>
            <span class="fm-form-label-text">Da data (since)</span>
            <input type="date" id="fm-logs-since" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">A data (until)</span>
            <input type="date" id="fm-logs-until" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Teacher ID</span>
            <input type="number" id="fm-logs-tid" min="1" placeholder="es. 77" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Actor user ID</span>
            <input type="number" id="fm-logs-actor" min="1" placeholder="es. 77" class="fm-w-full">
        </label>
        <label>
            <span class="fm-form-label-text">Ruolo attore</span>
            <input type="text" id="fm-logs-role" list="fm-logs-roles" placeholder="es. student" class="fm-w-full">
            <datalist id="fm-logs-roles">
                <option value="super_admin"></option>
                <option value="administrator"></option>
                <option value="teacher"></option>
                <option value="student"></option>
                <option value="guest"></option>
                <option value="system"></option>
            </datalist>
        </label>
        <label>
            <span class="fm-form-label-text">Azione</span>
            <input type="text" id="fm-logs-action" list="fm-logs-actions" placeholder="es. parent_consent_granted" class="fm-w-full">
            <datalist id="fm-logs-actions">
                <option value="http_request"></option>
                <option value="registration_submitted"></option>
                <option value="registration_approved"></option>
                <option value="registration_rejected"></option>
                <option value="parent_consent_requested"></option>
                <option value="parent_consent_granted"></option>
                <option value="parent_consent_rejected"></option>
                <option value="parent_consent_revoked"></option>
                <option value="parent_consent_expired"></option>
            </datalist>
        </label>
        <label>
            <span class="fm-form-label-text">Limit (1-500)</span>
            <input type="number" id="fm-logs-limit" min="1" max="500" value="100" class="fm-w-full">
        </label>
        <div class="fm-form-actions">
            <button type="button" class="fm-btn fm-btn--primary" data-fm-logs-action="refresh">🔎 Aggiorna</button>
            <button type="button" class="fm-btn fm-btn--ghost" data-fm-logs-action="export">📥 Export CSV</button>
            <span id="fm-logs-status" class="fm-inline-status"></span>
        </div>
    </div>
</section>

<section class="fm-card">
    <div id="fm-logs-results">
        <p class="fm-muted">Caricamento…</p>
    </div>
</section>

</div><!-- /.fm-card -->

<?= \App\Support\ViteManifest::script('js/entries/admin-logs.js') ?>
