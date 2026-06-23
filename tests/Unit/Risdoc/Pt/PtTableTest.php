<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToHtml;
use App\Services\Risdoc\Pt\PtToTex;
use App\Services\Risdoc\Pt\PtValidator;
use PHPUnit\Framework\TestCase;

/**
 * Test block type `table` (Phase 24.1).
 */
final class PtTableTest extends TestCase
{
    protected function setUp(): void
    {
        PtValidator::flushCache();
    }

    public function testRenderSimpleTable(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['Nome', 'Cognome', 'Età'],
            'rows' => [
                ['Mario', 'Rossi', '30'],
                ['Anna', 'Verdi', '25'],
            ],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\begin{tabular}{|l|l|l|}', $out);
        self::assertStringContainsString('Nome & Cognome & Età \\\\', $out);
        self::assertStringContainsString('Mario & Rossi & 30 \\\\', $out);
        self::assertStringContainsString('\\end{tabular}', $out);
        self::assertStringContainsString('\\hline', $out);
    }

    public function testWidthModeFullEqualUsesTabularx(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B', 'C'],
            'rows' => [['1', '2', '3']],
            'widthMode' => 'full',
        ]];
        $out = PtToTex::render($pt);
        // Colonne X uguali → larghezza piena \textwidth, no pesi \hsize.
        self::assertStringContainsString('\\begin{tabularx}{\\textwidth}{|X|X|X|}', $out);
        self::assertStringContainsString('\\end{tabularx}', $out);
        self::assertStringNotContainsString('\\begin{tabular}{', $out);
    }

    public function testWidthModeFullWeightedColumns(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B', 'C'],
            'rows' => [['1', '2', '3']],
            'widthMode' => 'full',
            'colWidths' => [50, 25, 25],
        ]];
        $out = PtToTex::render($pt);
        // k_i = colCount * pct/100 → 1.5 / 0.75 / 0.75 (somma = 3 = colonne).
        self::assertStringContainsString('\\begin{tabularx}{\\textwidth}{|>{\\hsize=1.5\\hsize}X|>{\\hsize=0.75\\hsize}X|>{\\hsize=0.75\\hsize}X|}', $out);
    }

    public function testWidthModeFullHtmlColgroup(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B', 'C'],
            'rows' => [['1', '2', '3']],
            'widthMode' => 'full',
            'colWidths' => [50, 30, 20],
        ]];
        $out = PtToHtml::render($pt, ['fields' => [], 'state' => []]);
        self::assertStringContainsString('class="fm-pt-table fm-pt-table--full"', $out);
        self::assertStringContainsString('<colgroup><col style="width:50%"><col style="width:30%"><col style="width:20%"></colgroup>', $out);
    }

    public function testWidthModeAutoUnchanged(): void
    {
        $pt = [['_type' => 'table', 'columns' => ['A', 'B'], 'rows' => [['1', '2']]]];
        self::assertStringContainsString('\\begin{tabular}{|l|l|}', PtToTex::render($pt));
        self::assertStringContainsString('<table class="fm-pt-table">', PtToHtml::render($pt, ['fields' => [], 'state' => []]));
    }

    public function testNormalizeColWidthsFallbackEqualOnPartial(): void
    {
        // Se non tutte le colonne hanno un valore valido → ripartizione equa.
        self::assertSame([50.0, 50.0], PtToHtml::normalizeColWidths([0, 0], 2));
        self::assertSame([50.0, 50.0], PtToHtml::normalizeColWidths([70], 2));
        self::assertSame([25.0, 75.0], PtToHtml::normalizeColWidths([10, 30], 2));
    }

    public function testRenderEmptyColumnsSkips(): void
    {
        $pt = [['_type' => 'table', 'columns' => [], 'rows' => []]];
        self::assertSame('', PtToTex::render($pt));
    }

    public function testRenderPadsShortRows(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B', 'C'],
            'rows' => [
                ['1', '2'], // short — padded
                ['X', 'Y', 'Z', 'Extra'], // long — truncated
            ],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('1 & 2 &  \\\\', $out);
        self::assertStringContainsString('X & Y & Z \\\\', $out);
        self::assertStringNotContainsString('Extra', $out);
    }

    public function testRenderEscapesSpecialChars(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['%', '$'],
            'rows' => [['50%', '100$']],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('50\\% & 100\\$', $out);
        self::assertStringContainsString('\\% & \\$', $out);
    }

    public function testRenderWithCaption(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['N.'],
            'rows' => [['1']],
            'caption' => 'Tab. esempio',
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\begin{table}[h]', $out);
        self::assertStringContainsString('\\caption{Tab. esempio}', $out);
        self::assertStringContainsString('\\end{table}', $out);
    }

    public function testValidatorAcceptsTable(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B'],
            'rows' => [['x', 'y']],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testValidatorRejectsMissingColumns(): void
    {
        $pt = [['_type' => 'table', 'rows' => []]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    public function testValidatorRejectsEmptyColumnsArray(): void
    {
        $pt = [['_type' => 'table', 'columns' => [], 'rows' => []]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    // Phase 24.11 — cells tipate + merge

    public function testCellAsObjectText(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B'],
            'rows' => [[
                ['text' => 'Ciao'],
                ['text' => 'mondo'],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('Ciao & mondo \\\\', $out);
    }

    public function testCellWithSelectWidget(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['Periodo', 'Ore'],
            'rows' => [[
                [
                    'text' => '',
                    'widget' => [
                        '_type' => 'select',
                        'value' => 'Trimestre',
                        'options' => [
                            ['value' => 'Trimestre', 'label' => 'Trimestre'],
                            ['value' => 'Pentamestre', 'label' => 'Pentamestre'],
                        ],
                    ],
                ],
                ['text' => '15'],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\underline{Trimestre}', $out);
        self::assertStringContainsString('15', $out);
    }

    public function testCellWithTextFieldWidget(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['Nome'],
            'rows' => [[
                ['widget' => ['_type' => 'textField', 'value' => 'Mario']],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('Mario', $out);
    }

    public function testCellEmptyWidgetUnderlinePlaceholder(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['X'],
            'rows' => [[
                ['widget' => ['_type' => 'select', 'value' => '', 'options' => []]],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\underline{\\hspace{2cm}}', $out);
    }

    public function testColspanUsesMulticolumn(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B', 'C'],
            'rows' => [[
                ['text' => 'Unificata', 'colspan' => 2],
                ['text' => 'C1'],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\multicolumn{2}{|l|}{Unificata}', $out);
        self::assertStringContainsString('C1', $out);
    }

    public function testMergedCellsSkipped(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B'],
            'rows' => [[
                ['text' => 'Grande', 'colspan' => 2],
                ['text' => 'Ignored', 'merged' => true],
            ]],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\multicolumn{2}{|l|}{Grande}', $out);
        self::assertStringNotContainsString('Ignored', $out);
    }

    public function testBackwardCompatStringCells(): void
    {
        // Vecchio formato (string) continua a funzionare
        $pt = [[
            '_type' => 'table',
            'columns' => ['A', 'B'],
            'rows' => [['str1', 'str2']],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('str1 & str2 \\\\', $out);
    }

    public function testValidatorAcceptsObjectCells(): void
    {
        $pt = [[
            '_type' => 'table',
            'columns' => ['A'],
            'rows' => [[
                ['text' => 'x', 'widget' => null, 'colspan' => 1, 'rowspan' => 1, 'merged' => false],
            ]],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testMixedDocumentWithTable(): void
    {
        $pt = [
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'Prima della tabella.', 'marks' => []],
            ]],
            ['_type' => 'table', 'columns' => ['A'], 'rows' => [['x']]],
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'Dopo la tabella.', 'marks' => []],
            ]],
        ];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('Prima della tabella.', $out);
        self::assertStringContainsString('\\begin{tabular}', $out);
        self::assertStringContainsString('Dopo la tabella.', $out);
    }
}
