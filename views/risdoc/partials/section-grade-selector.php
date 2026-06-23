<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? 'gradeSelector');
$label   = (string)($section['label'] ?? 'Voto');
$options = (array)($section['options'] ?? []);
$current = (string)($ctx['compilation']['fields'][$name] ?? '');
?>
<div class="grade-selector-container">
    <label for="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?>:</label>
    <select id="<?= htmlspecialchars($name, ENT_QUOTES) ?>" name="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
        <?php foreach ($options as $opt):
            if (is_scalar($opt)) { $id = ''; $value = (string)$opt; $text = (string)$opt; }
            else { $id = (string)($opt['id'] ?? ''); $value = (string)($opt['value'] ?? ''); $text = (string)($opt['label'] ?? $value); }
        ?>
            <option <?= $id ? 'id="' . htmlspecialchars($id, ENT_QUOTES) . '"' : '' ?> value="<?= htmlspecialchars($value, ENT_QUOTES) ?>" <?= $value === $current ? 'selected' : '' ?>><?= htmlspecialchars($text, ENT_QUOTES) ?></option>
        <?php endforeach; ?>
    </select>
</div>
