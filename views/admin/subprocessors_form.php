<?php
/** @var array|null $sp @var string $csrf @var array $user */
$h = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$isEdit = $sp !== null;
$page_title    = ($isEdit ? '✎ Modifica' : '+ Nuovo') . ' sub-processor';
$page_subtitle = 'Compila le info coerentemente con il DPA dell\'esterno + sezione §9 informativa.';
$breadcrumb    = [
    ['label' => 'Sub-processor', 'href' => '/admin/subprocessors'],
    ['label' => $isEdit ? '#' . (int)$sp['id'] : 'Nuovo'],
];
include __DIR__ . '/_partials/page_head.php';
?>

<form method="POST" action="/admin/subprocessors/save" class="fm-d-flex fm-flex-col fm-gap-4 fm-max-w-xl">
    <input type="hidden" name="_csrf" value="<?= $h($csrf) ?>">
    <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$sp['id'] ?>">
    <?php endif; ?>

    <label>Nome *
        <input type="text" name="name" required maxlength="160" value="<?= $h($sp['name'] ?? '') ?>"
               placeholder="es. il provider di hosting"
               class="fm-w-full">
    </label>

    <label>Descrizione servizio *
        <input type="text" name="service_description" required maxlength="255" value="<?= $h($sp['service_description'] ?? '') ?>"
               placeholder="es. Web hosting + database + storage"
               class="fm-w-full">
    </label>

    <label>Paese *
        <input type="text" name="country" required maxlength="64" value="<?= $h($sp['country'] ?? '') ?>"
               placeholder="es. Italia"
               class="fm-w-full">
    </label>

    <label class="checkbox-label fm-d-flex fm-items-center fm-gap-2" >
        <input type="checkbox" name="extra_eu_transfer" value="1"<?= !empty($sp['extra_eu_transfer']) ? ' checked' : '' ?>>
        Trasferimento dati extra-UE
    </label>

    <label>Safeguards (se extra-UE)
        <input type="text" name="transfer_safeguards" maxlength="255" value="<?= $h($sp['transfer_safeguards'] ?? '') ?>"
               placeholder="es. SCC + DPF"
               class="fm-w-full">
    </label>

    <label class="checkbox-label fm-d-flex fm-items-center fm-gap-2" >
        <input type="checkbox" name="dpa_signed" value="1"<?= !empty($sp['dpa_signed']) ? ' checked' : '' ?>>
        DPA firmato (Art. 28 GDPR)
    </label>

    <label>URL DPA (PDF/link)
        <input type="url" name="dpa_url" maxlength="512" value="<?= $h($sp['dpa_url'] ?? '') ?>"
               placeholder="https://..."
               class="fm-w-full">
    </label>

    <label>Email contatto privacy
        <input type="email" name="contact_email" maxlength="255" value="<?= $h($sp['contact_email'] ?? '') ?>"
               placeholder="privacy@subprocessor.com"
               class="fm-w-full">
    </label>

    <label>Note giuridiche <small>(opzionale, per casi atipici)</small>
        <textarea name="notes" rows="4" maxlength="2000"
                  placeholder="Es. 'Non sub-processor classico ma flow OAuth opt-in user-driven, applicazione diretta ToS del fornitore'"
                  class="fm-w-full fm-font-sans"><?= $h($sp['notes'] ?? '') ?></textarea>
    </label>

    <label class="checkbox-label fm-d-flex fm-items-center fm-gap-2" >
        <input type="checkbox" name="active" value="1"<?= !$isEdit || !empty($sp['active']) ? ' checked' : '' ?>>
        Attivo (visibile in /privacy/informativa)
    </label>

    <div class="fm-d-flex fm-gap-2">
        <button type="submit" class="fm-btn fm-btn--primary">💾 <?= $isEdit ? 'Aggiorna' : 'Crea' ?></button>
        <a href="/admin/subprocessors" class="fm-btn fm-btn--ghost" data-full-reload>Annulla</a>
    </div>
</form>

</div>
