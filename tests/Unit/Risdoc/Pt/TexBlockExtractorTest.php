<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToTex;
use App\Services\Risdoc\Pt\PtValidator;
use App\Services\Risdoc\Pt\TexBlockExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Test TexBlockExtractor (Phase 22.4).
 *
 * Estrazione blocchi `%[BeginTesto]...%[EndTesto]` da template TeX legacy
 * → PT AST. Verifica:
 *   - Match sectionbox per label esatto
 *   - Parse text + fieldRef + checkbox (gruppi)
 *   - Inline marks \textbf / \textit / \underline
 *   - Output valida contro PT JSON Schema
 *   - Round-trip semantic: PT → TeX rende blocchi semantically equivalenti
 *
 * 2026-09-10 — tolto `testExtractRealPianoAnnualeProfilo`, che leggeva il
 * `.tex` del modello istituzionale «Piano annuale». Quel file nel repository
 * non c'è e non ci sarà — è un dato d'istanza — quindi la prova si saltava in
 * **ogni** ambiente, qui come in integrazione continua: non ha mai eseguito
 * una riga. Quello che verificava è coperto da prove che girano davvero:
 * `testCheckboxGroupConsecutive` per lo stato `x`, `testFieldRefInline` per i
 * campi, e soprattutto `FullCycleTest::testEndToEndTexToPtToSchemaToCompilationToTex`
 * per il giro completo TeX → PT → TeX su un sorgente costruito sulla stessa
 * forma (`[field-classe]`, `[field-sezione]`, `\xcheckbox{...}`).
 */
final class TexBlockExtractorTest extends TestCase
{
    public function testExtractReturnsNullIfSectionBoxNotFound(): void
    {
        $tex = '\\begin{sectionbox}{ALTRO}%[BeginTesto]x%[EndTesto]\\end{sectionbox}';
        self::assertNull(TexBlockExtractor::extract($tex, 'OSSERVAZIONI'));
    }

    public function testExtractReturnsNullIfBeginTestoMissing(): void
    {
        $tex = '\\begin{sectionbox}{OSSERVAZIONI}niente%[BeginTesto]/%[EndTesto]mancante\\end{sectionbox}';
        // Qui il blocco BeginTesto c'è ma non ben formato — adatta se necessario
        // Uso tex senza BeginTesto:
        $tex = '\\begin{sectionbox}{OSSERVAZIONI}niente qui\\end{sectionbox}';
        self::assertNull(TexBlockExtractor::extract($tex, 'OSSERVAZIONI'));
    }

    public function testSimpleTextBlock(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{TEST}
%[BeginTesto]
Ciao mondo.
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'TEST');
        self::assertNotNull($pt);
        self::assertCount(1, $pt);
        self::assertSame('block', $pt[0]['_type']);
        self::assertSame('Ciao mondo.', $pt[0]['children'][0]['text']);
    }

    public function testFieldRefInline(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{T}
%[BeginTesto]
Classe [field-classe] sezione [field-sezione] anno [field-anno_scolastico].
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'T');
        self::assertNotNull($pt);
        $kids = $pt[0]['children'];
        // Verifichiamo presenza fieldRef in ordine
        $fieldRefs = array_filter($kids, fn($c) => ($c['_type'] ?? '') === 'fieldRef');
        $names = array_column(array_values($fieldRefs), 'name');
        self::assertSame(['classe', 'sezione', 'anno_scolastico'], $names);
    }

    public function testCheckboxGroupConsecutive(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{T}
%[BeginTesto]
\xcheckbox{alpha}
\checkbox{beta}
\checkbox{gamma}
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'T');
        self::assertNotNull($pt);
        self::assertCount(1, $pt);
        self::assertSame('checkboxGroup', $pt[0]['_type']);
        self::assertCount(3, $pt[0]['items']);
        self::assertSame(['state' => 'x', 'label' => 'alpha'], $pt[0]['items'][0]);
        self::assertSame(['state' => '_', 'label' => 'beta'], $pt[0]['items'][1]);
    }

    public function testTextCheckboxTextAlternating(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{T}
%[BeginTesto]
Prima parte di testo.
\xcheckbox{opzione1}
\checkbox{opzione2}
Seconda parte di testo.
\checkbox{altro}
Terza parte.
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'T');
        self::assertNotNull($pt);
        self::assertCount(5, $pt);
        self::assertSame('block',         $pt[0]['_type']);
        self::assertSame('checkboxGroup', $pt[1]['_type']);
        self::assertSame('block',         $pt[2]['_type']);
        self::assertSame('checkboxGroup', $pt[3]['_type']);
        self::assertSame('block',         $pt[4]['_type']);
    }

    public function testInlineMarkTextbf(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{T}
%[BeginTesto]
Testo con \textbf{parola forte} e \textit{corsivo}.
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'T');
        self::assertNotNull($pt);
        $children = $pt[0]['children'];
        $strongs = array_filter($children, fn($c) =>
            ($c['_type'] ?? '') === 'span' && in_array('strong', $c['marks'] ?? [], true));
        $ems = array_filter($children, fn($c) =>
            ($c['_type'] ?? '') === 'span' && in_array('em', $c['marks'] ?? [], true));
        self::assertNotEmpty($strongs, 'strong span atteso');
        self::assertNotEmpty($ems, 'em span atteso');
        $strongText = reset($strongs)['text'];
        self::assertSame('parola forte', $strongText);
    }

    public function testValidatedOutput(): void
    {
        $tex = <<<'TEX'
\begin{sectionbox}{T}
%[BeginTesto]
Ciao [field-classe] \textbf{bold}.
\xcheckbox{ok}
%[EndTesto]
\end{sectionbox}
TEX;
        $pt = TexBlockExtractor::extract($tex, 'T');
        self::assertNotNull($pt);
        $result = PtValidator::validate($pt);
        self::assertTrue($result['valid'], 'PT estratto deve validare. Errori: ' . implode('; ', $result['errors']));
    }
}
