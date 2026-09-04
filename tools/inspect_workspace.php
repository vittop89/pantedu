<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
$svc = new \App\Services\Tikz\TeacherTemplateWorkspaceService();
$tid = 77;
$ws = $svc->getWorkspace($tid);
foreach ($ws as $groupKey => $items) {
    echo "[$groupKey] " . count($items) . " items\n";
    foreach (array_slice($items, 0, 2) as $i => $item) {
        echo "  item[$i] keys: " . implode(",", array_keys($item)) . "\n";
        echo "  item[$i].label = " . ($item['label'] ?? '?') . "\n";
        echo "  item[$i].type  = " . ($item['type'] ?? '?') . "\n";
        if (isset($item['content'])) echo "  item[$i].content (" . strlen((string)$item['content']) . " bytes): " . substr((string)$item['content'], 0, 80) . "...\n";
        if (isset($item['code']))    echo "  item[$i].code (" . strlen((string)$item['code']) . " bytes): " . substr((string)$item['code'], 0, 80) . "...\n";
        if (isset($item['_data']))   echo "  item[$i]._data = " . json_encode($item['_data'], JSON_UNESCAPED_UNICODE) . "\n";
        if (isset($item['_override'])) echo "  item[$i]._override = " . var_export($item['_override'], true) . "\n";
    }
    // continua tutti i gruppi per cercare schema-modulare
}
