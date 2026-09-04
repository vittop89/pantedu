<?php
/** @var array $section */
/** @var array $ctx */
$title = (string)($section['title'] ?? 'Glossario');
$rows  = (array)($section['rows'] ?? []);
$columns = (array)($section['columns'] ?? [
    ['key'=>'term',       'label'=>'Termine'],
    ['key'=>'definition', 'label'=>'Definizione'],
    ['key'=>'source',     'label'=>'Fonte'],
]);
?>
<div class="section">
    <?php if ($title): ?><div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div><?php endif; ?>
    <table class="interdisciplinary-table">
        <thead>
            <tr><?php foreach ($columns as $c): ?><th scope="col"><?= htmlspecialchars((string)($c['label'] ?? $c['key']), ENT_QUOTES) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
                <tr><?php foreach ($columns as $c):
                    $v = is_array($row) ? (string)($row[$c['key']] ?? '') : '';
                ?><td><?= htmlspecialchars($v, ENT_QUOTES) ?></td><?php endforeach; ?></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
