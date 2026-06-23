<?php
/** @var array $section */
/** @var array $ctx */
$name    = (string)($section['name']  ?? 'nota_alunno');
$label   = (string)($section['label'] ?? '');
$current = (string)($ctx['compilation']['fields'][$name] ?? '');
$containerId = $name . '_container';
?>
<div id="<?= htmlspecialchars($containerId, ENT_QUOTES) ?>">
    <label for="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($label, ENT_QUOTES) ?>:</label>
    <textarea id="<?= htmlspecialchars($name, ENT_QUOTES) ?>" name="<?= htmlspecialchars($name, ENT_QUOTES) ?>"><?= htmlspecialchars($current, ENT_QUOTES) ?></textarea>
    <div id="clipboard_status"></div>
    <div id="debug"></div>
</div>
