<?php declare(strict_types=1);

namespace Tests\Unit\Risdoc\Pt;

use App\Services\Risdoc\Pt\PtToHtml;
use PHPUnit\Framework\TestCase;

/**
 * G23 page-doc — Unit test PtToHtml per i 5 nuovi block types
 * (ADR-020 / spec docs/specs/page-doc-block-types.md).
 *
 * Coverage:
 *   - glossaryTable: caption/th[scope]/escape entries
 *   - staticContent: heading/body/nesting items recursion
 *   - accordion: <details>/<summary>/nested body_pt rendering
 *   - linkListPdf: link + sub_items + external auto-detect
 *   - citationNorma: header/quote/href
 *   - XSS escape battery (server PtToHtml è authoritative)
 */
final class PtToHtmlPageDocTest extends TestCase
{
    // ── glossaryTable ────────────────────────────────────────

    public function testGlossaryTableRendersCaptionAndScopedHeaders(): void
    {
        $pt = [[
            '_type' => 'glossaryTable',
            'columns' => ['N.', 'Lemma', 'Definizione', 'Fonte'],
            'entries' => [
                ['n' => 1, 'lemma' => 'Abilità', 'definizione' => 'Cap. applic.', 'fonte' => 'Racc. UE'],
                ['n' => 2, 'lemma' => 'DSA', 'definizione' => 'Dist. spec.', 'fonte' => 'L. 170/2010'],
            ],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('class="pt-glossary-table"', $out);
        self::assertStringContainsString('<caption class="pt-glossary-caption">Glossario (2 voci)</caption>', $out);
        self::assertStringContainsString('<th scope="col"', $out);
        self::assertStringContainsString('data-col-key="lemma"', $out);
        self::assertStringContainsString('<td>Abilità</td>', $out);
        self::assertStringContainsString('<td>L. 170/2010</td>', $out);
        self::assertStringContainsString('class="pt-glossary-search"', $out);
    }

    public function testGlossaryTableEscapesXssInEntries(): void
    {
        $pt = [[
            '_type' => 'glossaryTable',
            'columns' => ['N.', 'Lemma', 'Definizione', 'Fonte'],
            'entries' => [
                ['n' => 1, 'lemma' => '<script>alert(1)</script>', 'definizione' => '<img onerror=alert(2)>', 'fonte' => 'javascript:alert(3)'],
            ],
        ]];
        $out = PtToHtml::render($pt);
        // Tag eseguibile: escaped come testo
        self::assertStringNotContainsString('<script>alert(1)</script>', $out, 'script not as executable tag');
        self::assertStringNotContainsString('<img onerror=', $out, 'img not as executable tag');
        // Entity-escaped (= testo, non eseguibile)
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
        self::assertStringContainsString('&lt;img onerror=alert(2)&gt;', $out);
        // javascript: in plain text (no href), OK come stringa
        self::assertStringContainsString('javascript:alert(3)', $out, 'plain text fonte ok (no link)');
    }

    public function testGlossaryTableEmptyColumnsSkips(): void
    {
        $pt = [['_type' => 'glossaryTable', 'columns' => [], 'entries' => []]];
        self::assertSame('', PtToHtml::render($pt));
    }

    public function testGlossaryTableSearchableDisabled(): void
    {
        $pt = [[
            '_type' => 'glossaryTable',
            'columns' => ['Lemma', 'Def'],
            'entries' => [],
            'searchable' => false,
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringNotContainsString('class="pt-glossary-search"', $out);
    }

    // ── staticContent ────────────────────────────────────────

    public function testStaticContentRendersHeadingAndBody(): void
    {
        $pt = [[
            '_type' => 'staticContent',
            'title' => 'Norme generali',
            'level' => 2,
            'body'  => '<p>Testo introduttivo</p>',
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('class="pt-static-content"', $out);
        self::assertStringContainsString('data-level="2"', $out);
        self::assertStringContainsString('<h2>Norme generali</h2>', $out);
        self::assertStringContainsString('<p>Testo introduttivo</p>', $out);
    }

    public function testStaticContentSanitizesBodyXss(): void
    {
        $pt = [[
            '_type' => 'staticContent',
            'level' => 2,
            'body'  => '<p>OK</p><script>alert(1)</script><img src=x onerror=alert(2)>',
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('<p>OK</p>', $out);
        self::assertStringNotContainsString('<script>', $out);
        self::assertStringNotContainsString('onerror', $out);
    }

    public function testStaticContentNestsItemsRecursively(): void
    {
        $pt = [[
            '_type' => 'staticContent',
            'title' => 'PARTE I',
            'level' => 2,
            'body'  => '<p>parent</p>',
            'items' => [
                ['_type' => 'staticContent', 'title' => 'A. Sub', 'level' => 3, 'body' => '<p>sub-text</p>'],
            ],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('<h2>PARTE I</h2>', $out);
        self::assertStringContainsString('<h3>A. Sub</h3>', $out);
        self::assertStringContainsString('<p>parent</p>', $out);
        self::assertStringContainsString('<p>sub-text</p>', $out);
    }

    public function testStaticContentLevelClampedTo2_4(): void
    {
        foreach ([1 => 2, 5 => 4] as $input => $expected) {
            $pt = [['_type' => 'staticContent', 'title' => 'X', 'level' => $input, 'body' => '']];
            $out = PtToHtml::render($pt);
            self::assertStringContainsString("data-level=\"{$expected}\"", $out, "level={$input}");
        }
    }

    // ── accordion ────────────────────────────────────────────

    public function testAccordionRendersDetailsSummary(): void
    {
        $pt = [[
            '_type' => 'accordion',
            'allow_multiple' => true,
            'items' => [
                [
                    'title' => 'A. Voti',
                    'default_open' => true,
                    'body_pt' => [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Body A', 'marks' => []]]]],
                ],
                [
                    'title' => 'B. Recuperi',
                    'default_open' => false,
                    'body_pt' => [['_type' => 'block', 'style' => 'normal', 'children' => [['_type' => 'span', 'text' => 'Body B', 'marks' => []]]]],
                ],
            ],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('class="pt-accordion"', $out);
        self::assertStringContainsString('data-multiple="true"', $out);
        self::assertStringContainsString('<details class="pt-accordion__item" open>', $out, 'first open');
        self::assertStringContainsString('<details class="pt-accordion__item">', $out, 'second closed');
        self::assertStringContainsString('<summary>A. Voti</summary>', $out);
        self::assertStringContainsString('<summary>B. Recuperi</summary>', $out);
        self::assertStringContainsString('Body A', $out);
        self::assertStringContainsString('Body B', $out);
    }

    public function testAccordionAllowMultipleFalse(): void
    {
        $pt = [[
            '_type' => 'accordion',
            'allow_multiple' => false,
            'items' => [['title' => 'X', 'body_pt' => []]],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('data-multiple="false"', $out);
    }

    public function testAccordionEmptyItemsSkips(): void
    {
        $pt = [['_type' => 'accordion', 'items' => []]];
        self::assertSame('', PtToHtml::render($pt));
    }

    public function testAccordionTitleEscapesXss(): void
    {
        $pt = [[
            '_type' => 'accordion',
            'items' => [['title' => '<script>alert(1)</script>', 'body_pt' => []]],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringNotContainsString('<summary><script>', $out);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    // ── linkListPdf ──────────────────────────────────────────

    public function testLinkListPdfRendersWithSubItems(): void
    {
        $pt = [[
            '_type' => 'linkListPdf',
            'title' => 'Primo ciclo',
            'items' => [
                [
                    'label' => 'DM 254/2012',
                    'href'  => '/strcomp_bes_altro/ALTRO/509.pdf',
                    'external' => false,
                    'description' => 'Curricolo',
                    'sub_items' => [
                        ['label' => 'Allegato A', 'href' => '/allegato.pdf'],
                    ],
                ],
                [
                    'label' => 'MIUR',
                    'href'  => 'https://www.mim.gov.it/',
                    'external' => true,
                ],
            ],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('class="pt-link-list-pdf"', $out);
        self::assertStringContainsString('<h3 class="pt-link-list-pdf__title">Primo ciclo</h3>', $out);
        self::assertStringContainsString('href="/strcomp_bes_altro/ALTRO/509.pdf"', $out);
        self::assertStringContainsString('href="https://www.mim.gov.it/"', $out);
        self::assertStringContainsString('rel="noopener noreferrer"', $out);
        self::assertStringContainsString('class="pt-link-list-pdf__sublist"', $out);
        self::assertStringContainsString('Allegato A', $out);
        self::assertStringContainsString('Curricolo', $out);
    }

    public function testLinkListPdfAutoDetectsExternalFromHttps(): void
    {
        $pt = [[
            '_type' => 'linkListPdf',
            'items' => [['label' => 'X', 'href' => 'https://example.com/']],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('target="_blank"', $out);
        self::assertStringContainsString('rel="noopener noreferrer"', $out);
    }

    public function testLinkListPdfEmptyItemsSkips(): void
    {
        $pt = [['_type' => 'linkListPdf', 'items' => []]];
        self::assertSame('', PtToHtml::render($pt));
    }

    public function testLinkListPdfEscapesLabel(): void
    {
        $pt = [[
            '_type' => 'linkListPdf',
            'items' => [['label' => '<script>x</script>', 'href' => '/safe.pdf']],
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringNotContainsString('<script>x</script>', $out);
        self::assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $out);
    }

    // ── citationNorma ────────────────────────────────────────

    public function testCitationNormaRendersAllFields(): void
    {
        $pt = [[
            '_type' => 'citationNorma',
            'tipo' => 'DM',
            'numero' => '5669',
            'anno' => 2011,
            'articolo' => 'Art. 4 c. 2',
            'title' => 'Linee Guida DSA',
            'href' => '/strcomp_bes_altro/ALTRO/prot5669_11.pdf',
            'quote' => 'Gli strumenti compensativi devono essere riconosciuti.',
        ]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('class="pt-citation-norma"', $out);
        self::assertStringContainsString('data-tipo="DM"', $out);
        self::assertStringContainsString('<strong>DM 5669 2011</strong>', $out);
        self::assertStringContainsString('Art. 4 c. 2', $out);
        self::assertStringContainsString('Linee Guida DSA', $out);
        self::assertStringContainsString('Gli strumenti compensativi', $out);
        self::assertStringContainsString('href="/strcomp_bes_altro/ALTRO/prot5669_11.pdf"', $out);
    }

    public function testCitationNormaMinimalFields(): void
    {
        $pt = [['_type' => 'citationNorma', 'tipo' => 'L', 'numero' => '170', 'anno' => 2010]];
        $out = PtToHtml::render($pt);
        self::assertStringContainsString('<strong>L 170 2010</strong>', $out);
        self::assertStringNotContainsString('pt-citation-norma__quote', $out);
        self::assertStringNotContainsString('pt-citation-norma__title', $out);
    }

    public function testCitationNormaEscapesQuoteXss(): void
    {
        $pt = [['_type' => 'citationNorma', 'tipo' => 'DM', 'quote' => '<script>alert(1)</script>']];
        $out = PtToHtml::render($pt);
        self::assertStringNotContainsString('<script>alert(1)</script>', $out);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    // ── coexistenza con block types esistenti ────────────────

    public function testMixedBlockTypesRenderInOrder(): void
    {
        $pt = [
            ['_type' => 'sectionHeader', 'title' => 'Intro', 'level' => 1],
            ['_type' => 'staticContent', 'title' => 'Sezione', 'level' => 2, 'body' => '<p>x</p>'],
            ['_type' => 'glossaryTable', 'columns' => ['Lemma', 'Def'], 'entries' => [['lemma' => 'A', 'def' => 'B']]],
        ];
        $out = PtToHtml::render($pt);
        // Ordine preservato
        $posHeader  = strpos($out, 'Intro');
        $posStatic  = strpos($out, 'Sezione');
        $posGloss   = strpos($out, 'Glossario');
        self::assertNotFalse($posHeader);
        self::assertNotFalse($posStatic);
        self::assertNotFalse($posGloss);
        self::assertLessThan($posStatic, $posHeader);
        self::assertLessThan($posGloss, $posStatic);
    }

    public function testUnknownTypeReturnsEmpty(): void
    {
        $pt = [['_type' => 'totallyUnknownType', 'foo' => 'bar']];
        self::assertSame('', PtToHtml::render($pt));
    }
}
