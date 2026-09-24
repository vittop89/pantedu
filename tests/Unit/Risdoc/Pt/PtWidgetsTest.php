<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToTex;
use App\Services\Risdoc\Pt\PtValidator;
use PHPUnit\Framework\TestCase;

/**
 * Test nuovi block types Phase 24.2-5: select, textField, formCheckbox, sectionHeader.
 */
final class PtWidgetsTest extends TestCase
{
    protected function setUp(): void
    {
        PtValidator::flushCache();
    }

    // ── select ──

    public function testSelectWithLabelAndValue(): void
    {
        $pt = [[
            '_type' => 'select',
            'label' => 'Periodo',
            'value' => 'Trimestre',
            'options' => [
                ['value' => 'Trimestre', 'label' => 'Trimestre'],
                ['value' => 'Pentamestre', 'label' => 'Pentamestre'],
            ],
        ]];
        $out = PtToTex::render($pt);
        self::assertSame('Periodo: \\underline{Trimestre}', $out);
    }

    public function testSelectEmptyValuePlaceholder(): void
    {
        $pt = [[
            '_type' => 'select',
            'label' => 'Scegli',
            'value' => '',
            'options' => [['value' => 'a', 'label' => 'A']],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\underline{\\hspace{3cm}}', $out);
    }

    public function testSelectValidatorAcceptsNoOptionsIfOptionsSource(): void
    {
        // Phase 24.11b — select può avere options OR options_source (OR entrambi vuoti,
        // runtime fetch). Validator JSON Schema non enforce uno dei due required.
        $pt = [[
            '_type' => 'select',
            'options_source' => ['file' => 'x.json'],
        ]];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testSelectValidatorAcceptsEmpty(): void
    {
        // POC: select standalone senza options né options_source è comunque
        // valido (usato quando inserito da toolbar, popolato via edit).
        $pt = [['_type' => 'select']];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    // ── textField ──

    public function testTextFieldWithValue(): void
    {
        $pt = [[
            '_type' => 'textField',
            'label' => 'Nome',
            'value' => 'Mario',
        ]];
        self::assertSame('Nome: Mario', PtToTex::render($pt));
    }

    public function testTextFieldKindsIgnoredInTex(): void
    {
        foreach (['text', 'number', 'date'] as $kind) {
            $pt = [['_type' => 'textField', 'value' => '42', 'kind' => $kind]];
            self::assertSame('42', PtToTex::render($pt), "kind=$kind");
        }
    }

    public function testTextFieldValidatorAcceptsMinimal(): void
    {
        $pt = [['_type' => 'textField']];
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }

    public function testTextFieldRejectsInvalidKind(): void
    {
        $pt = [['_type' => 'textField', 'kind' => 'color']];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    // ── formCheckbox ──

    public function testFormCheckboxChecked(): void
    {
        $pt = [['_type' => 'formCheckbox', 'label' => 'Confermo', 'checked' => true]];
        self::assertSame('\\xcheckbox{Confermo}', PtToTex::render($pt));
    }

    public function testFormCheckboxUnchecked(): void
    {
        $pt = [['_type' => 'formCheckbox', 'label' => 'No', 'checked' => false]];
        self::assertSame('\\checkbox{No}', PtToTex::render($pt));
    }

    public function testFormCheckboxValidatorRequiresLabel(): void
    {
        $pt = [['_type' => 'formCheckbox']];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    // ── sectionHeader ──

    /**
     * Comandi NON numerati: i titoli del body_pt portano gia' la numerazione
     * manuale ("1.", "2.1"…), e il livello 1 senza selettori e' una sezione
     * top come il 2 (il titolo-documento e' il caso con selettori, sotto).
     * Riallineato il 2026-09-04 al renderer (era \section, \subsection…).
     */
    public function testSectionHeaderLevelsToLatexCmd(): void
    {
        $cases = [
            1 => '\\section*{Titolo}',
            2 => '\\section*{Titolo}',
            3 => '\\subsection*{Titolo}',
            4 => '\\subsubsection*{Titolo}',
        ];
        foreach ($cases as $level => $expected) {
            $pt = [['_type' => 'sectionHeader', 'title' => 'Titolo', 'level' => $level]];
            self::assertSame($expected, PtToTex::render($pt), "level=$level");
        }
    }

    /**
     * Livello 1 con selettori anagrafici = intestazione del documento
     * (titolo centrato + tabella Prof./Classe/Sezione…), non una sezione.
     */
    public function testSectionHeaderWithSelectors(): void
    {
        $pt = [[
            '_type' => 'sectionHeader',
            'title' => 'Piano',
            'level' => 1,
            'selectors' => ['classe', 'sezione'],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\begin{center}', $out);
        self::assertStringContainsString('Piano\\par', $out);
        self::assertStringContainsString('\\begin{tabularx}', $out);
        self::assertStringContainsString('\\textbf{Classe:}', $out);
        self::assertStringContainsString('\\textbf{Sezione:}', $out);
        self::assertStringNotContainsString('\\section', $out);
    }

    /** Selettori su una sezione interna: suffisso con i segnaposto [field-*]. */
    public function testSectionHeaderSelectorsBelowDocLevelKeepPlaceholders(): void
    {
        $pt = [[
            '_type' => 'sectionHeader',
            'title' => 'Profilo',
            'level' => 2,
            'selectors' => ['classe', 'sezione'],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\section*{Profilo}', $out);
        self::assertStringContainsString('[field-classe] [field-sezione]', $out);
    }

    public function testSectionHeaderValidatorRejectsInvalidLevel(): void
    {
        $pt = [['_type' => 'sectionHeader', 'title' => 'X', 'level' => 99]];
        self::assertFalse(PtValidator::validate($pt)['valid']);
    }

    // ── doc misto ──

    public function testFullRisdocDocumentMixed(): void
    {
        $pt = [
            ['_type' => 'sectionHeader', 'title' => 'Piano Annuale', 'level' => 1,
                'selectors' => ['classe', 'sezione']],
            ['_type' => 'textField', 'label' => 'Docente', 'value' => 'Mario Rossi'],
            ['_type' => 'select', 'label' => 'Classe', 'value' => '3',
                'options' => [['value'=>'1','label'=>'1a'],['value'=>'3','label'=>'3a']]],
            ['_type' => 'formCheckbox', 'label' => 'DSA', 'checked' => true],
            ['_type' => 'table', 'columns' => ['N', 'UDA'],
                'rows' => [['1', 'Sistemi']]],
        ];
        $out = PtToTex::render($pt);
        // Titolo-documento (livello 1 + selettori) → intestazione centrata.
        self::assertStringContainsString('\\begin{center}', $out);
        self::assertStringContainsString('Piano Annuale\\par', $out);
        self::assertStringContainsString('Docente: Mario Rossi', $out);
        self::assertStringContainsString('Classe: \\underline{3}', $out);
        self::assertStringContainsString('\\xcheckbox{DSA}', $out);
        self::assertStringContainsString('\\begin{tabular}', $out);
        // Blocchi separati da \n\n
        self::assertStringContainsString("\n\n", $out);
        // Validator accetta intero doc
        self::assertTrue(PtValidator::validate($pt)['valid']);
    }
}
