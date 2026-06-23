<?php
/** @var array $section */
$title = (string)($section['title'] ?? 'Informativa Privacy');
$body  = (string)($section['body']  ?? '');
?>
<div class="section privacy-block">
    <div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div>
    <div class="section-content small-text"><?= $body /* trusted */ ?></div>
</div>
