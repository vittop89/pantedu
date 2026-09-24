<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';

use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\VersionPicker;

// Nowdoc: niente interpretazione escape, identico al string raw frontend.
$pub = <<<'RAW'
\(\begin{array}{|c|}\hline\small{\text{Matematica multimediale.blu}}\\[-5pt]\tiny{\text{Vol.2 Ed.3 - ZANICHELLI}}\\[-5pt]\tiny{\text{Massimo Bergamini - Graziella Barozzi}}\\[-5pt]\hline\end{array}\quad\overset{\color{red}\huge \bullet\bullet\circ\circ}{\underset{\text{P-}1171}{\bbox[border: 1px solid white; background: green,3pt]{{\mathmakebox[cm][c]{\textcolor{white}{\large 181}}}}}}\quad\)
RAW;
$itemHtml = $pub . ' Sia \(ABCD\) un trapezio. Determina \(\enclose{circle}[mathcolor=red]{x}\).';
$itemSol  = '\(\enclose{circle}[mathcolor=red]{x} = \dfrac{32 - 4\sqrt{19}}{10}\)';

$sel = Selection::fromArray([
    'version' => 'A', 'verTitle' => 'CompileTest',
    'selectedIIS' => 'sc', 'selectedCLS' => '3', 'selectedMATER' => 'MAT',
    'anno' => '2026', 'sezione' => 'NOR',
    'problems' => [[
        'filePath' => '/x', 'problemId' => 'type_Collect_x', 'position' => 1, 'type' => 'Collect',
        'text' => 'Risolvi.',
        'items' => [[
            'html' => $itemHtml,
            'solution' => $itemSol,
            'points' => 1.0,
            'includeSolution' => false,
        ]],
    ]],
    'options' => ['includeSolutions' => false],
]);

$builder = new TexBuilder();

$norDir = sys_get_temp_dir() . '/fmtex';
@mkdir($norDir, 0755, true);

$nor = $builder->build($sel, VersionPicker::NORMAL);
file_put_contents("$norDir/test_NOR.tex", $nor);

$sel->options['includeSolutions'] = true;
$sol = $builder->build($sel, VersionPicker::NORMAL);
file_put_contents("$norDir/test_SOL.tex", $sol);

echo "Generated in $norDir\n";
echo "  test_NOR.tex: " . strlen($nor) . " bytes\n";
echo "  test_SOL.tex: " . strlen($sol) . " bytes\n";
