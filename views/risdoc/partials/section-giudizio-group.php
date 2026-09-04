<?php
/** @var array       $section */
/** @var array       $ctx */
/** @var \App\Services\Risdoc\FormRenderer $renderer */
$title = (string)($section['title'] ?? '');
$items = (array)($section['items'] ?? []);
?>
<div class="giudizio-item">
    <label><?= htmlspecialchars($title, ENT_QUOTES) ?></label>
    <?php foreach ($items as $item): echo $renderer->renderSection($item, $ctx); endforeach; ?>
</div>
