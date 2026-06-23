<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContractRenderer;
use App\Services\TexBuilder;
use App\Services\TexBuilder\Selection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G23.fix8 — End-to-end pipeline audit per RM table.
 *
 * Verifica che TUTTI i campi rmLayout salvati lato JS abbiano effetto
 * concreto sia nel HTML rendering (ContractRenderer) sia nel TeX
 * (Sanitizer convertRmTable).
 *
 * Campi auditati:
 *   - table_count (Numero tabelle)
 *   - orientation (Orientamento)
 *   - rows / cols (matrix dimensions)
 *   - typecell (X/V/B/T/N per colonna)
 *   - mixtr / mixcol (shuffle)
 *   - mpagew / specificWidth (table width)
 */
final class RmTablePipelineAuditTest extends TestCase
{
    private function renderRmContract(array $rmLayout, array $opts = null): string
    {
        $opts ??= [
            ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'AA']]],
            ['letter' => 'b', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'BB']]],
            ['letter' => 'c', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'CC']]],
            ['letter' => 'd', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'DD']]],
        ];
        $renderer = new ContractRenderer([]);
        return $renderer->renderContract([
            'title' => 'Test',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_RMulti', 'title' => 'Grp', 'intro' => '',
                'items' => [[
                    'id' => 'q1', 'question' => [['type' => 'text', 'content' => 'Q']],
                    'options' => $opts,
                    'rmLayout' => $rmLayout,
                    'justification' => [],
                ]],
            ]],
        ]);
    }

    private function htmlToTex(string $cellsHtml, array $items = null): string
    {
        $items ??= [['html' => 'Q ' . $cellsHtml, 'points' => 1.0]];
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_1',
                'position' => 1, 'text' => 'Audit',
                'items' => $items,
            ]],
        ]);
        return (new TexBuilder())->buildFlat($sel);
    }

    /** AUDIT 1 — `rows × cols` dimensions = matching <tr> e <td> count */
    #[Test]
    public function rmLayout_rows_cols_respected_in_html(): void
    {
        // 1 riga × 4 colonne (caso user dello screenshot)
        $html = $this->renderRmContract(['rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|']);
        $this->assertStringContainsString('data-rows="1"', $html);
        $this->assertStringContainsString('data-cols="4"', $html);
        $this->assertSame(1, substr_count($html, '<tr>'));
        $this->assertSame(4, substr_count($html, '<td class="rm-option'));
    }

    /** AUDIT 2 — Typecell X/V/B/T/N → input HTML corretti */
    #[Test]
    public function typecell_all_5_types_render_correct_inputs(): void
    {
        $html = $this->renderRmContract([
            'rows' => 1, 'cols' => 5, 'typecell' => '|X|V|B|T|N|',
        ], [
            ['letter' => 'a', 'correct' => false, 'content' => []],
            ['letter' => 'b', 'correct' => false, 'content' => []],
            ['letter' => 'c', 'correct' => false, 'content' => []],
            ['letter' => 'd', 'correct' => false, 'content' => []],
            ['letter' => 'e', 'correct' => false, 'content' => []],
        ]);
        $this->assertStringContainsString('data-typecell="|X|V|B|T|N|"', $html);
        $this->assertMatchesRegularExpression('#<input type="checkbox"#', $html);
        $this->assertMatchesRegularExpression('#<input type="radio"#', $html);
        $this->assertMatchesRegularExpression('#<button[^>]*class="fm-rm-btn"#', $html);
        $this->assertMatchesRegularExpression('#<input type="text"[^>]*class="fm-rm-text"#', $html);
        $this->assertMatchesRegularExpression('#<input type="number"[^>]*class="fm-rm-num"#', $html);
    }

    /** AUDIT 3 — mpagew=true → width:100% in HTML */
    #[Test]
    public function mpagew_applies_full_width_html(): void
    {
        $html = $this->renderRmContract([
            'rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|', 'mpagew' => true,
        ]);
        $this->assertStringContainsString('data-mpagew="1"', $html);
        $this->assertStringContainsString('width:100%', $html);
    }

    /** AUDIT 4 — specificWidth=300 → width:300px in HTML */
    #[Test]
    public function specificWidth_applies_px_width_html(): void
    {
        $html = $this->renderRmContract([
            'rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|',
            'mpagew' => false, 'specificWidth' => '300',
        ]);
        $this->assertStringContainsString('data-width="300"', $html);
        $this->assertStringContainsString('width:300px', $html);
    }

    /** AUDIT 5 — mixtr/mixcol → data-attrs preserved (markup) */
    #[Test]
    public function mix_flags_emitted_as_data_attrs(): void
    {
        $html = $this->renderRmContract([
            'rows' => 2, 'cols' => 2, 'typecell' => '|X|X|',
            'mixtr' => true, 'mixcol' => true,
        ]);
        $this->assertStringContainsString('data-mixtr="1"', $html);
        $this->assertStringContainsString('data-mixcol="1"', $html);
    }

    /** AUDIT 6 — TeX render: 4 cells X get $\square$ each */
    #[Test]
    public function tex_render_4cols_X_checkbox(): void
    {
        $html = $this->renderRmContract(['rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|']);
        // Estrai la fm-rm-table HTML dall'output completo
        if (!preg_match('#<table class="fm-rm-table"[\s\S]*?</table>#', $html, $m)) {
            $this->fail('fm-rm-table non trovata nel HTML render');
        }
        $tex = $this->htmlToTex($m[0]);
        $this->assertStringContainsString('\\begin{tabular}', $tex);
        // 4 \square per le 4 X cells (default unchecked)
        $this->assertSame(4, substr_count($tex, '\\square'));
        // G23.fix9 — NO letter prefix in TeX (mirror DOM markup)
        $this->assertStringNotContainsString('\\textbf{a.}', $tex);
        $this->assertStringNotContainsString('\\textbf{b.}', $tex);
        $this->assertStringNotContainsString('\\textbf{c.}', $tex);
        $this->assertStringNotContainsString('\\textbf{d.}', $tex);
    }

    /** AUDIT 7 — Multi-table (Numero tabelle > 1): renderRmTable iterato per
     *  ogni rmLayout.tables[] con options chunked. */
    #[Test]
    public function multi_table_supported(): void
    {
        $rmLayout = [
            'table_count' => '2',
            'orientation' => 'horizontal',
            'tables' => [
                ['rows' => 1, 'cols' => 2, 'typecell' => '|X|X|'],
                ['rows' => 1, 'cols' => 2, 'typecell' => '|V|V|'],
            ],
            'rows' => 1, 'cols' => 2, 'typecell' => '|X|X|',
        ];
        $html = $this->renderRmContract($rmLayout, [
            ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'A1']]],
            ['letter' => 'b', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'B1']]],
            ['letter' => 'c', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'A2']]],
            ['letter' => 'd', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'B2']]],
        ]);
        // 2 tabelle distinte
        $this->assertSame(2, substr_count($html, '<table class="fm-rm-table"'));
        // Tabella 1: X|X, Tabella 2: V|V
        $this->assertStringContainsString('data-typecell="|X|X|"', $html);
        $this->assertStringContainsString('data-typecell="|V|V|"', $html);
        // 4 options distribuite (2 per tabella)
        $this->assertStringContainsString('A1', $html);
        $this->assertStringContainsString('B1', $html);
        $this->assertStringContainsString('A2', $html);
        $this->assertStringContainsString('B2', $html);
    }

    /** AUDIT 8 — Orientation horizontal/vertical → wrapper data-orientation
     *  + flex-direction CSS. */
    #[Test]
    public function orientation_wrapper_emitted(): void
    {
        $htmlV = $this->renderRmContract([
            'rows' => 1, 'cols' => 2, 'typecell' => '|X|X|',
            'orientation' => 'vertical',
        ]);
        $this->assertStringContainsString('data-orientation="vertical"', $htmlV);
        $this->assertStringContainsString('flex-direction:column', $htmlV);

        $htmlH = $this->renderRmContract([
            'rows' => 1, 'cols' => 2, 'typecell' => '|X|X|',
            'orientation' => 'horizontal',
        ]);
        $this->assertStringContainsString('data-orientation="horizontal"', $htmlH);
        $this->assertStringContainsString('flex-wrap:wrap', $htmlH);
    }

    /** AUDIT 9 — Correct flag preservato in HTML rm-correct class */
    #[Test]
    public function correct_option_preserves_rm_correct_class(): void
    {
        $html = $this->renderRmContract(
            ['rows' => 1, 'cols' => 2, 'typecell' => '|X|X|'],
            [
                ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'A']]],
                ['letter' => 'b', 'correct' => true,  'content' => [['type' => 'text', 'content' => 'B']]],
            ]
        );
        $this->assertMatchesRegularExpression('#<td class="rm-option rm-correct"[^>]*>#', $html);
    }

    /** AUDIT 10 — TeX cell content: typecell V → $\bigcirc$ */
    #[Test]
    public function tex_typecell_V_renders_bigcirc(): void
    {
        $html = $this->renderRmContract(['rows' => 1, 'cols' => 2, 'typecell' => '|V|V|']);
        preg_match('#<table class="fm-rm-table"[\s\S]*?</table>#', $html, $m);
        $tex = $this->htmlToTex($m[0]);
        $this->assertSame(2, substr_count($tex, '\\bigcirc'));
    }

    /** AUDIT 11 — TeX width: mpagew=1 → tabular usa \linewidth */
    #[Test]
    public function tex_mpagew_uses_linewidth(): void
    {
        $html = $this->renderRmContract([
            'rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|', 'mpagew' => true,
        ]);
        preg_match('#<table class="fm-rm-table"[\s\S]*?</table>#', $html, $m);
        $tex = $this->htmlToTex($m[0]);
        $this->assertStringContainsString('\\linewidth', $tex);
    }

    /** AUDIT 12 — TeX width: specificWidth=400 → tabular usa cm conversion */
    #[Test]
    public function tex_specificWidth_converts_to_cm(): void
    {
        $html = $this->renderRmContract([
            'rows' => 1, 'cols' => 4, 'typecell' => '|X|X|X|X|',
            'mpagew' => false, 'specificWidth' => '400',
        ]);
        preg_match('#<table class="fm-rm-table"[\s\S]*?</table>#', $html, $m);
        $tex = $this->htmlToTex($m[0]);
        // 400px ≈ 10.6cm totali; per colonna ≈ 2.52cm (0.95 * 10.6 / 4)
        $this->assertMatchesRegularExpression('#p\{\d+\.\d+cm\}#', $tex);
        $this->assertStringNotContainsString('\\linewidth', $tex);
    }
}
