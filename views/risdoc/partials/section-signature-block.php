<?php
/** @var array $section */
$labelFirma  = (string)($section['label_firma']  ?? 'Firma');
$labelData   = (string)($section['label_data']   ?? 'Data');
$showSubmit  = !empty($section['show_submit']);
$showReset   = !empty($section['show_reset']);
$submitText  = (string)($section['submit_label'] ?? 'Invia');
$resetText   = (string)($section['reset_label']  ?? 'Reset');
?>
<div class="footer-signature">
    <div class="info-field">
        <span class="label"><?= htmlspecialchars($labelData, ENT_QUOTES) ?>:</span>
        <span class="line line-short"></span>
    </div>
    <div class="info-field">
        <span class="label"><?= htmlspecialchars($labelFirma, ENT_QUOTES) ?>:</span>
        <span class="line"></span>
    </div>
    <?php if ($showSubmit || $showReset): ?>
        <div class="save-load-buttons-container">
            <?php if ($showSubmit): ?><button type="submit" class="btn-risdoc"><?= htmlspecialchars($submitText, ENT_QUOTES) ?></button><?php endif; ?>
            <?php if ($showReset):  ?><button type="reset" class="cache-action-btn" id="reset-form-btn"><?= htmlspecialchars($resetText, ENT_QUOTES) ?></button><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
