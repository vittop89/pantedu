<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? '');
$title   = (string)($section['title'] ?? '');
$options = (array)($section['options'] ?? []);
$checked = (array)($ctx['compilation']['fields'][$name] ?? []);
?>
<div class="section">
    <?php if ($title): ?><div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div><?php endif; ?>
    <div class="section-content">
        <?php foreach ($options as $i => $opt):
            $id    = is_array($opt) ? (string)($opt['id']    ?? "{$name}_{$i}") : "{$name}_{$i}";
            $value = is_array($opt) ? (string)($opt['value'] ?? $opt['label'] ?? '') : (string)$opt;
            $text  = is_array($opt) ? (string)($opt['label'] ?? $value) : (string)$opt;
            $isChecked = in_array($value, $checked, true);
        ?>
            <div class="checkbox-item">
                <input type="checkbox" id="<?= htmlspecialchars($id, ENT_QUOTES) ?>"
                       name="<?= htmlspecialchars($name, ENT_QUOTES) ?>[]"
                       value="<?= htmlspecialchars($value, ENT_QUOTES) ?>"
                       <?= $isChecked ? 'checked' : '' ?>>
                <label for="<?= htmlspecialchars($id, ENT_QUOTES) ?>"><?= htmlspecialchars($text, ENT_QUOTES) ?></label>
            </div>
        <?php endforeach; ?>
    </div>
</div>
