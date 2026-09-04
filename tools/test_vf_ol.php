<?php
declare(strict_types=1);
require_once __DIR__ . '/../app/bootstrap.php';
use App\Services\PhpContentParser;

$file = __DIR__ . '/../_legacy_archive_phase15/verifiche/php/MAT/MAT-Equazioni_di_secondo_grado-ver.php';
$html = file_get_contents($file);
$parser = new PhpContentParser([
    'teacher_id' => 77, 'institute_id' => 106,
    'kind' => 'verifica', 'subject' => 'MAT', 'topic' => 'Equazioni di secondo grado',
]);
$contract = $parser->parse($html);
$rm = null;
foreach ($contract['groups'] ?? [] as $g) {
    if (($g['type'] ?? '') === 'RM') { $rm = $g; break; }
}
foreach ($rm['items'] as $i => $it) {
    $opts = $it['options'] ?? [];
    echo "item $i id=" . substr($it['id'] ?? '', -6) . " options=" . count($opts);
    if ($opts) {
        echo "  letters=";
        foreach ($opts as $o) echo ($o['letter'] ?? '?') . ':' . ($o['answer'] ?? '-') . ($o['correct'] ? '*' : '') . ' ';
    }
    echo "\n";
}
