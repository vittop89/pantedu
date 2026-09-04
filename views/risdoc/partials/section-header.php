<?php
/** @var array  $section */
/** @var array  $ctx */
$title     = (string)($section['title'] ?? '');
$selectors = (array)($section['selectors'] ?? []);
$values    = (array)($ctx['compilation']['state'] ?? []);
?>
<div class="header">
    <div class="header-title">
        <div><?= htmlspecialchars($title, ENT_QUOTES) ?></div>
    </div>
    <?php if (!empty($selectors)): ?>
        <div class="dynamic-selector-container">
            <?php foreach ($selectors as $key): ?>
                <select class="field" data-key="<?= htmlspecialchars($key, ENT_QUOTES) ?>" name="<?= htmlspecialchars($key, ENT_QUOTES) ?>">
                    <option value="">— <?= htmlspecialchars(ucfirst($key), ENT_QUOTES) ?> —</option>
                    <?php if (!empty($values[$key])): ?>
                        <option value="<?= htmlspecialchars((string)$values[$key], ENT_QUOTES) ?>" selected><?= htmlspecialchars((string)$values[$key], ENT_QUOTES) ?></option>
                    <?php endif; ?>
                </select>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
