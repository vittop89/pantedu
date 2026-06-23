<?php
/** @var array $section */
/** @var array $ctx */
$title = (string)($section['title'] ?? '');
$body  = (string)($section['body'] ?? '');
$bodyRef = (string)($section['body_ref'] ?? '');
if ($bodyRef !== '' && $body === '') {
    // Supporta body_ref = path a file MD/HTML relativo alla root.
    $abs = dirname(__DIR__, 3) . '/' . ltrim($bodyRef, '/');
    if (is_file($abs)) { $body = (string)file_get_contents($abs); }
}
$items = (array)($section['items'] ?? []);
?>
<div class="section static-content">
    <?php if ($title): ?><div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div><?php endif; ?>
    <?php if ($body): ?><div class="section-content"><?= $body /* fidato: server-side content */ ?></div><?php endif; ?>
    <?php foreach ($items as $item): echo $renderer->renderSection($item, $ctx); endforeach; ?>
</div>
