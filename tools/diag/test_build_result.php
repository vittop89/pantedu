<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\BuildResult;
use App\Services\TexBuilder\VersionPicker;

$pub = <<<'RAW'
\(\enclose{circle}[mathcolor=red]{x}\)
RAW;

$sel = Selection::fromArray([
    'version' => 'A', 'verTitle' => 'TestG20',
    'selectedIIS' => 'sc', 'selectedCLS' => '3', 'selectedMATER' => 'MAT',
    'anno' => '2026', 'sezione' => 'NOR',
    'problems' => [[
        'filePath' => '/x', 'problemId' => 'type_Collect_x', 'position' => 1, 'type' => 'Collect',
        'text' => 'Risolvi.',
        'items' => [[
            'html' => "Determina $pub.",
            'solution' => '\(x = 4\sqrt{19}/10\)',
            'points' => 1.0,
            'includeSolution' => false,
        ]],
    ]],
    'options' => ['includeSolutions' => false],
]);

$builder = new TexBuilder();

// === MODE ZIP ===
echo "=== MODE: ZIP ===\n";
$resultZip = $builder->build($sel, VersionPicker::NORMAL, [
    'mode'           => BuildResult::MODE_ZIP,
    'variant_kind'   => 'NOR',
    'docente_nome'   => '{{OPERATORE_NOME}}',
    'institute_name' => 'IIS di Esempio - Comune Esempio (XX)',
]);
foreach ($resultZip->files as $f) {
    printf("  %-50s %d bytes\n", $f['path'], strlen($f['content']));
}

echo "\n=== MODE: VSC ===\n";
$resultVsc = $builder->build($sel, VersionPicker::NORMAL, [
    'mode'           => BuildResult::MODE_VSC,
    'variant_kind'   => 'SOL',
    'docente_nome'   => '{{OPERATORE_NOME}}',
    'institute_name' => 'IIS di Esempio - Comune Esempio (XX)',
]);
foreach ($resultVsc->files as $f) {
    printf("  %-50s %d bytes\n", $f['path'], strlen($f['content']));
}

// Materializza ZIP layout in tmp + compila pdflatex
$tmp = sys_get_temp_dir() . '/g20zip';
@mkdir($tmp, 0755, true);
foreach ($resultZip->files as $f) {
    $full = "$tmp/" . $f['path'];
    @mkdir(dirname($full), 0755, true);
    file_put_contents($full, $f['content']);
}
echo "\n=== ZIP layout materializzato in: $tmp ===\n";
chdir("$tmp/versioni");
$out = [];
exec('pdflatex -interaction=nonstopmode main_NOR.tex 2>&1', $out, $rc);
$tail = array_slice($out, -8);
echo "rc=$rc, tail:\n  " . implode("\n  ", $tail) . "\n";
