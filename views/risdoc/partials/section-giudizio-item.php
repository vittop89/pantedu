<?php
/** @var array $section */
/** @var array $ctx */
$name        = (string)($section['name']        ?? '');
$description = (string)($section['description'] ?? '');
$options     = (array)($section['options']     ?? []);
$selectId    = 'select_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
$current     = (string)($ctx['compilation']['fields'][$name] ?? '');
?>
<div class="select-description"><?= htmlspecialchars($description, ENT_QUOTES) ?></div>
<select name="<?= htmlspecialchars($name, ENT_QUOTES) ?>" id="<?= htmlspecialchars($selectId, ENT_QUOTES) ?>" class="risp_giud">
    <?php foreach ($options as $opt):
        $id    = (string)($opt['id']    ?? '');
        $value = (string)($opt['value'] ?? '');
        $text  = (string)($opt['label'] ?? $value);
    ?>
        <option <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '' ?> value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $value === $current ? 'selected' : '' ?>><?= htmlspecialchars($text, ENT_QUOTES) ?></option>
    <?php endforeach; ?>
</select>
