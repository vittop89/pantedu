<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? '');
$title   = (string)($section['title'] ?? '');
$columns = (array)($section['columns'] ?? [['key'=>'col1','label'=>'Colonna 1']]);
$defaultRows = (int)($section['default_rows'] ?? 1);
$saved = (array)($ctx['compilation']['fields'][$name] ?? []);
$rowsCount = max($defaultRows, count($saved));
?>
<div class="section dynamic-actions-section">
    <?php if ($title): ?><div class="section-header"><?= htmlspecialchars($title, ENT_QUOTES) ?></div><?php endif; ?>
    <table class="dynamic-actions-table" id="<?= htmlspecialchars($name, ENT_QUOTES) ?>">
        <thead>
            <tr>
                <?php foreach ($columns as $c): ?>
                    <th scope="col"><?= htmlspecialchars((string)($c['label'] ?? $c['key']), ENT_QUOTES) ?></th>
                <?php endforeach; ?>
                <th scope="col" class="uda-actions-cell"></th>
            </tr>
        </thead>
        <tbody>
            <?php for ($r = 0; $r < $rowsCount; $r++):
                $row = $saved[$r] ?? [];
            ?>
                <tr>
                    <?php foreach ($columns as $c):
                        $k = (string)($c['key'] ?? '');
                        $v = (string)($row[$k] ?? ($row[array_search($c,$columns,true)] ?? ''));
                    ?>
                        <td><textarea name="<?= htmlspecialchars($name.'['.$r.']['.$k, ENT_QUOTES) ?>]"><?= htmlspecialchars($v, ENT_QUOTES) ?></textarea></td>
                    <?php endforeach; ?>
                    <td class="uda-actions-cell">
                        <button type="button" class="remove-row-btn" title="Rimuovi">-</button>
                        <button type="button" class="add-row-btn" title="Aggiungi">+</button>
                    </td>
                </tr>
            <?php endfor; ?>
        </tbody>
    </table>
</div>
