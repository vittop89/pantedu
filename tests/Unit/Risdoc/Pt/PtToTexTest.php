<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToTex;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests per il walker Portable Text → LaTeX (Phase 22.1 POC).
 *
 * Copre:
 *  - block + span (testo puro, marks strong/em/underline/code)
 *  - fieldRef inline → [field-name] placeholder
 *  - checkboxGroup "all" → itemize colonna con \item[\xcheckbox]/\item[\checkbox]
 *  - rawTex → content as-is
 *  - escape LaTeX special chars
 *  - blocchi multipli separati da \n\n
 *  - fixture "profilo_classe" (replica TeX legacy)
 */
final class PtToTexTest extends TestCase
{
    public function testEmptyArrayRendersEmptyString(): void
    {
        self::assertSame('', PtToTex::render([]));
    }

    public function testSingleBlockWithPlainSpan(): void
    {
        $pt = [
            [
                '_type' => 'block',
                'style' => 'normal',
                'children' => [
                    ['_type' => 'span', 'text' => 'Ciao mondo', 'marks' => []],
                ],
            ],
        ];
        self::assertSame('Ciao mondo', PtToTex::render($pt));
    }

    public function testMarksStrong(): void
    {
        $pt = [[
            '_type' => 'block', 'style' => 'normal',
            'children' => [
                ['_type' => 'span', 'text' => 'bold', 'marks' => ['strong']],
            ],
        ]];
        self::assertSame('\\textbf{bold}', PtToTex::render($pt));
    }

    public function testMarksAllStandard(): void
    {
        $cases = [
            'strong'    => '\\textbf{x}',
            'em'        => '\\textit{x}',
            'underline' => '\\underline{x}',
            'code'      => '\\texttt{x}',
        ];
        foreach ($cases as $mark => $expected) {
            $pt = [[
                '_type' => 'block', 'style' => 'normal',
                'children' => [['_type' => 'span', 'text' => 'x', 'marks' => [$mark]]],
            ]];
            self::assertSame($expected, PtToTex::render($pt), "mark=$mark");
        }
    }

    public function testMarksNesting(): void
    {
        $pt = [[
            '_type' => 'block', 'style' => 'normal',
            'children' => [
                ['_type' => 'span', 'text' => 'x', 'marks' => ['strong', 'em']],
            ],
        ]];
        // marks[0] (strong) esterno, marks[1] (em) interno
        self::assertSame('\\textbf{\\textit{x}}', PtToTex::render($pt));
    }

    public function testUnknownMarkPassesThrough(): void
    {
        $pt = [[
            '_type' => 'block', 'style' => 'normal',
            'children' => [['_type' => 'span', 'text' => 'x', 'marks' => ['foobar']]],
        ]];
        self::assertSame('x', PtToTex::render($pt));
    }

    public function testTexEscape(): void
    {
        $pt = [[
            '_type' => 'block', 'style' => 'normal',
            'children' => [
                ['_type' => 'span', 'text' => '10% di $100 & ~more_', 'marks' => []],
            ],
        ]];
        $out = PtToTex::render($pt);
        self::assertStringContainsString('\\%', $out);
        self::assertStringContainsString('\\$', $out);
        self::assertStringContainsString('\\&', $out);
        self::assertStringContainsString('\\textasciitilde{}', $out);
        self::assertStringContainsString('\\_', $out);
    }

    public function testFieldRefInline(): void
    {
        $pt = [[
            '_type' => 'block', 'style' => 'normal',
            'children' => [
                ['_type' => 'span', 'text' => 'Classe ', 'marks' => []],
                ['_type' => 'fieldRef', 'name' => 'classe'],
                ['_type' => 'span', 'text' => ' sezione ', 'marks' => []],
                ['_type' => 'fieldRef', 'name' => 'sezione'],
            ],
        ]];
        self::assertSame(
            'Classe [field-classe] sezione [field-sezione]',
            PtToTex::render($pt),
        );
    }

    public function testCheckboxGroupMixedStates(): void
    {
        $pt = [[
            '_type' => 'checkboxGroup',
            'items' => [
                ['state' => 'x', 'label' => 'corretto'],
                ['state' => '_', 'label' => 'adeguato'],
                ['state' => '_', 'label' => 'poco corretto non'],
            ],
        ]];
        $expected = "\\begin{itemize}\n"
                  . "  \\item[\\xcheckbox] corretto\n"
                  . "  \\item[\\checkbox] adeguato\n"
                  . "  \\item[\\checkbox] poco corretto non\n"
                  . "\\end{itemize}";
        self::assertSame($expected, PtToTex::render($pt));
    }

    public function testCheckboxGroupEmptyItems(): void
    {
        $pt = [['_type' => 'checkboxGroup', 'items' => []]];
        self::assertSame('', PtToTex::render($pt));
    }

    public function testRawTexInjectedVerbatim(): void
    {
        $pt = [[
            '_type' => 'rawTex',
            'content' => '\\begin{equation}x = y^2\\end{equation}',
        ]];
        self::assertSame('\\begin{equation}x = y^2\\end{equation}', PtToTex::render($pt));
    }

    public function testMultipleBlocksSeparatedByDoubleNewline(): void
    {
        $pt = [
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'primo', 'marks' => []],
            ]],
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'secondo', 'marks' => []],
            ]],
        ];
        self::assertSame("primo\n\nsecondo", PtToTex::render($pt));
    }

    public function testUnknownBlockTypeSkipped(): void
    {
        $pt = [
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'ok', 'marks' => []],
            ]],
            ['_type' => 'alienBlock', 'payload' => 'ignored'],
            ['_type' => 'block', 'style' => 'normal', 'children' => [
                ['_type' => 'span', 'text' => 'still', 'marks' => []],
            ]],
        ];
        self::assertSame("ok\n\nstill", PtToTex::render($pt));
    }

    /**
     * Fixture profilo_classe: replica il TeX legacy usando PT.
     * Serve come contract test end-to-end per l'architettura v2.
     */
    public function testFixtureProfiloClasse(): void
    {
        $fixturePath = dirname(__DIR__, 3) . '/../schemas/risdoc/_pt/fixture-profilo.pt.json';
        self::assertFileExists($fixturePath, 'fixture file richiesto');
        $pt = json_decode((string)file_get_contents($fixturePath), true, flags: JSON_THROW_ON_ERROR);

        $expected = "Gli alunni della classe [field-classe] [field-sezione] adottano un comportamento"
                  . "\n\n"
                  . "\\begin{itemize}\n"
                  . "  \\item[\\xcheckbox] corretto\n"
                  . "  \\item[\\checkbox] adeguato\n"
                  . "  \\item[\\checkbox] poco corretto non\n"
                  . "\\end{itemize}"
                  . "\n\n"
                  . "permettendo un \\textbf{regolare svolgimento} delle lezioni.";

        self::assertSame($expected, PtToTex::render($pt));
    }
}
