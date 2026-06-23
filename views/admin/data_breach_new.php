<?php
/** @var string $csrf @var string|null $error @var array $old @var array $user */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$old = $old ?? [];
$page_title    = '🚨 Nuovo Data Breach Incident';
$page_subtitle = 'Apri un incident. La data di rilevamento avvia il countdown SLA 72h Art. 33 GDPR.';
$breadcrumb    = [
    ['label' => 'Data Breach', 'href' => '/admin/data-breach'],
    ['label' => 'Nuovo'],
];
include __DIR__ . '/_partials/page_head.php';

// WCAG 3.3.1: marker visivo+screen-reader per campo obbligatorio.
$_required_marker = '<span aria-hidden="true" class="fm-req-marker">*</span><span class="fm-sr-only"> (campo obbligatorio)</span>';
?>

<?php if ($error): ?>
    <div class="fm-alert fm-alert--danger" role="alert" id="form-error-summary">
        <?= $h($error) ?>
    </div>
<?php endif; ?>

<form method="POST" action="/admin/data-breach/new"
      <?= $error ? 'aria-describedby="form-error-summary"' : '' ?>
      class="fm-d-flex fm-flex-col fm-gap-4 fm-max-w-xl">
    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">

    <div class="fm-field">
        <label for="db-occurred_at">Data e ora del breach (stima) <?= $_required_marker ?></label>
        <input type="datetime-local" id="db-occurred_at" name="occurred_at" required aria-required="true"
               value="<?= $h($old['occurred_at'] ?? '') ?>" class="fm-w-full">
    </div>

    <div class="fm-field">
        <label for="db-detected_at">Data e ora rilevamento (start SLA 72h) <?= $_required_marker ?></label>
        <input type="datetime-local" id="db-detected_at" name="detected_at" required aria-required="true"
               value="<?= $h($old['detected_at'] ?? date('Y-m-d\TH:i')) ?>"
               aria-describedby="db-detected_at-help"
               class="fm-w-full">
        <small id="db-detected_at-help" class="fm-muted">
            Il countdown SLA 72h GDPR Art. 33 parte da questa data.
        </small>
    </div>

    <div class="fm-field">
        <label for="db-severity">Severity <?= $_required_marker ?></label>
        <select id="db-severity" name="severity" required aria-required="true" class="fm-w-full">
            <?php foreach (['low','medium','high','critical'] as $opt): ?>
                <option value="<?= $opt ?>"<?= ($old['severity'] ?? '') === $opt ? ' selected' : '' ?>><?= $opt ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="fm-field">
        <label for="db-affected_users_count">Numero stimato utenti coinvolti</label>
        <input type="number" id="db-affected_users_count" name="affected_users_count" min="0"
               value="<?= $h($old['affected_users_count'] ?? '') ?>" class="fm-w-full">
    </div>

    <div class="fm-field">
        <label for="db-data_categories">Categorie di dati esposti</label>
        <input type="text" id="db-data_categories" name="data_categories" maxlength="255"
               value="<?= $h($old['data_categories'] ?? '') ?>"
               placeholder="es. auth, pii"
               aria-describedby="db-data_categories-help"
               class="fm-w-full">
        <small id="db-data_categories-help" class="fm-muted">
            CSV consentite: auth, pii, content, crypto.
        </small>
    </div>

    <div class="fm-field">
        <label for="db-description">Descrizione cosa è successo <?= $_required_marker ?></label>
        <textarea id="db-description" name="description" required aria-required="true"
                  minlength="20" rows="5"
                  aria-describedby="db-description-help"
                  class="fm-w-full"><?= $h($old['description'] ?? '') ?></textarea>
        <small id="db-description-help" class="fm-muted">
            Minimo 20 caratteri. Descrivi natura dell'incident, vettore, dati interessati.
        </small>
    </div>

    <div class="fm-field">
        <label for="db-root_cause">Root cause (se nota)</label>
        <textarea id="db-root_cause" name="root_cause" rows="3"
                  class="fm-w-full"><?= $h($old['root_cause'] ?? '') ?></textarea>
    </div>

    <div class="fm-field">
        <label for="db-remedial_actions">Azioni di mitigazione intraprese</label>
        <textarea id="db-remedial_actions" name="remedial_actions" rows="3"
                  class="fm-w-full"><?= $h($old['remedial_actions'] ?? '') ?></textarea>
    </div>

    <button type="submit" class="fm-btn fm-btn--danger fm-self-start" >Apri incident</button>
</form>

</div>
