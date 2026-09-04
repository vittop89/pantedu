<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? '');
$title   = (string)($section['title'] ?? '');
$placeholder = (string)($section['placeholder'] ?? '');
$current = (string)($ctx['compilation']['fields'][$name] ?? '');
?>
<div class="section">
    <?php if ($title): ?><div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div><?php endif; ?>
    <div class="section-content">
        <textarea name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                  placeholder="<?= htmlspecialchars($placeholder, ENT_QUOTES) ?>"><?= htmlspecialchars($current, ENT_QUOTES) ?></textarea>
    </div>
</div>
