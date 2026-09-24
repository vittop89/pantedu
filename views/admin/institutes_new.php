<?php
/** @var string $csrf */
/** @var string|null $error */
/** @var array $old */
/** @var bool $con_amministratore */

$page_title = '➕ Nuovo Istituto';
$breadcrumb = [
    ['href' => '/admin', 'label' => 'Admin'],
    ['href' => '/admin/institutes', 'label' => 'Istituti'],
];
$back_href  = '/admin/institutes';
$back_label = '← Torna alla lista';
include __DIR__ . '/_partials/page_head.php';

$v = function (string $key, string $default = '') use ($old): string {
    $val = $old[$key] ?? $default;
    return htmlspecialchars((string)$val, ENT_QUOTES);
};
?>

<?php if (!empty($error)): ?>
    <div class="fm-alert fm-alert--danger fm-mb-4" >
        <?= htmlspecialchars((string)$error, ENT_QUOTES) ?>
    </div>
<?php endif; ?>

<section class="fm-admin-kpi">
    <h2 class="fm-admin-kpi__title">Onboarding nuovo istituto <button type="button" class="fm-infotip" aria-label="Info onboarding istituto"><span class="fm-infotip__body" hidden>Crea un nuovo istituto. Nello scenario 3 crea anche il suo <strong>amministratore di istituto</strong>, con ambito limitato a quell'istituto; la password verrà mostrata UNA SOLA VOLTA dopo la creazione.</span></button></h2>
</section>

<form method="POST" action="/admin/institutes/new" class="fm-form fm-max-w-720 fm-mt-8 fm-d-grid fm-gap-4" >
    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="_audit_reason" value="Creazione di un nuovo istituto con il suo amministratore">

    <fieldset>
        <legend><strong>1. Anagrafica istituto</strong></legend>
        <div class="fm-d-grid fm-gap-2">
            <label>Codice istituto (A-Z, 0-9, _, -) — es. <code>GOB-OM</code>
                <input type="text" name="code" required pattern="[A-Z0-9_\-]{2,20}" maxlength="20"
                       value="<?= $v('code') ?>" class="fm-font-mono fm-text-14 fm-uppercase">
            </label>
            <label>Nome istituto
                <input type="text" name="name" required maxlength="200"
                       placeholder="Liceo Scientifico di Esempio"
                       value="<?= $v('name') ?>">
            </label>
            <label>Header label (per intestazioni LaTeX/PDF) <span class="fm-muted">opzionale</span>
                <input type="text" name="header_label" maxlength="200"
                       placeholder="I.I.S. di Esempio — Comune Esempio"
                       value="<?= $v('header_label') ?>">
            </label>
            <label>Città <input type="text" name="city" maxlength="100" value="<?= $v('city') ?>"></label>
            <label>Regione <input type="text" name="region" maxlength="80" value="<?= $v('region') ?>"></label>
        </div>
    </fieldset>

    <?php if (!empty($con_amministratore)): ?>
    <fieldset>
        <legend><strong>2. Amministratore di istituto (utente iniziale)</strong></legend>
        <p class="fm-muted">Sarà l'utente di gestione (dirigente, referente, animatore digitale), con ambito limitato a questo istituto.</p>
        <div class="fm-d-grid fm-gap-2">
            <label>Nome <input type="text" name="admin_first_name" maxlength="80" value="<?= $v('admin_first_name') ?>"></label>
            <label>Cognome <input type="text" name="admin_last_name" maxlength="80" value="<?= $v('admin_last_name') ?>"></label>
            <label>Email (per recovery password)
                <input type="email" name="admin_email" required maxlength="200" value="<?= $v('admin_email') ?>">
            </label>
            <label>Username (3-32 char minuscole/numeri/._-)
                <input type="text" name="admin_username" required pattern="[a-z0-9._\-]{3,32}" maxlength="32"
                       value="<?= $v('admin_username') ?>" class="fm-font-mono fm-text-14">
            </label>
        </div>
    </fieldset>
    <?php else: ?>
    <p class="fm-muted">Nessun amministratore di istituto: esiste solo quando la piattaforma è adottata da un Istituto (scenario 3, ADR-040).</p>
    <?php endif; ?>

    <div class="fm-d-flex fm-gap-4 fm-items-center">
        <button type="submit" class="fm-btn fm-btn--primary"><?= !empty($con_amministratore) ? 'Crea istituto e amministratore' : 'Crea istituto' ?></button>
        <a href="/admin/institutes" class="fm-link">Annulla</a>
    </div>
</form>

</div><!-- /.fm-card -->
