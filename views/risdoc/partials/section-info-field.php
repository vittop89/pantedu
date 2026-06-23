<?php
/** @var array $section */
/** @var array $ctx */
$name  = (string)($section['name']  ?? '');
$label = (string)($section['label'] ?? '');
$type  = (string)($section['input_type'] ?? 'text');
$placeholder = (string)($section['placeholder'] ?? '');
$options = (array)($section['options'] ?? []);
$current = (string)($ctx['compilation']['fields'][$name] ?? '');
$required = !empty($section['required']);
?>
<div class="info-field">
    <?php if ($label): ?><span class="label"><?= htmlspecialchars($label, ENT_QUOTES) ?>:</span><?php endif; ?>
    <?php if ($type === 'select' && !empty($options)): ?>
        <select class="field" name="<?= htmlspecialchars($name, ENT_QUOTES) ?>" <?= $required ? 'required' : '' ?>>
            <option value="">— Seleziona —</option>
            <?php foreach ($options as $opt):
                $v = is_array($opt) ? (string)($opt['value'] ?? '') : (string)$opt;
                $l = is_array($opt) ? (string)($opt['label'] ?? $v) : (string)$opt;
            ?>
                <option value="<?= htmlspecialchars($v, ENT_QUOTES) ?>" <?= $v === $current ? 'selected' : '' ?>><?= htmlspecialchars($l, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
    <?php else: ?>
        <input class="field" type="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
               name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
               value="<?= htmlspecialchars($current, ENT_QUOTES) ?>"
               placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>"
               <?= $required ? 'required' : '' ?>>
    <?php endif; ?>
</div>
