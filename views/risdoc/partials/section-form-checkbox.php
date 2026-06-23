<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? '');
$label   = (string)($section['label'] ?? '');
$checked = !empty($ctx['compilation']['fields'][$name]);
$id      = 'cb_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
?>
<div class="checkbox-item">
    <input type="checkbox" id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
           name="<?= htmlspecialchars($name, ENT_QUOTES) ?>" value="1" <?= $checked ? 'checked' : '' ?>>
    <label for="<?= htmlspecialchars($id, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?></label>
</div>
