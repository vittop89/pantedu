<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\PhpContentParser;

$file = __DIR__ . '/../_legacy_archive_phase15/verifiche/php/MAT/MAT-Sistemi_lineari-ver.php';
$html = file_get_contents($file);

$parser = new PhpContentParser([
    'teacher_id' => 77,
    'institute_id' => 106,
    'kind' => 'verifica',
    'subject' => 'MAT',
    'topic' => 'Sistemi lineari',
]);
$contract = $parser->parse($html);

$rm = null;
foreach ($contract['groups'] ?? [] as $g) {
    if (($g['type'] ?? '') === 'RM') { $rm = $g; break; }
}

if (!$rm) { echo "No RM group found\n"; exit(1); }
echo "RM group: " . ($rm['title'] ?? '?') . "\n";
echo "Items: " . count($rm['items']) . "\n\n";

foreach ($rm['items'] as $i => $it) {
    echo "item $i: " . ($it['id'] ?? '?') . " — options=" . count($it['options'] ?? []) . "\n";
    foreach ($it['options'] ?? [] as $j => $op) {
        $letter = $op['letter'] ?? '?';
        $ans    = $op['answer']  ?? '?';
        $correct = $op['correct'] ? 'CORRECT' : 'wrong';
        echo "    [$j] letter=$letter answer=$ans ($correct) content_blocks=" . count($op['content'] ?? []);
        if (!empty($op['content'][0]['content'])) {
            echo " preview=" . mb_substr(trim((string)$op['content'][0]['content']), 0, 50);
        }
        echo "\n";
    }
    echo "\n";
    if ($i >= 2) break;
}
