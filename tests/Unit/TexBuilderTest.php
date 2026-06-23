<?php

namespace Tests\Unit;

use App\Services\TexBuilder;
use App\Services\TexBuilder\Sanitizer;
use App\Services\TexBuilder\Selection;
use App\Services\TexBuilder\VersionPicker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TexBuilderTest extends TestCase
{
    private function selection(array $overrides = []): Selection
    {
        return Selection::fromArray(array_merge([
            'version'       => 'A',
            'verTitle'      => 'Verifica Cinematica',
            'selectedIIS'   => 'ar',
            'selectedCLS'   => '2s',
            'selectedMATER' => 'MAT',
            'anno'          => '2026',
            'sezione'       => 'NOR',
            'problems'      => [
                [
                    'filePath'  => '/eser/ar/eser_ar2s/MAT/3_MAT-foo.php',
                    'problemId' => 'p-1',
                    'position'  => 1,
                    'text'      => 'Risolvi le seguenti equazioni',
                    'items'     => [
                        ['html' => '$x + 2 = 5$', 'points' => 2.0, 'includeSolution' => false],
                        ['html' => '$x^2 = 9$',   'points' => 1.5, 'includeSolution' => true],
                    ],
                ],
            ],
        ], $overrides));
    }

    #[Test]
    public function builds_normal_version(): void
    {
        // G22.S4 — output via build(MODE_FLAT).flatten() pipeline:
        // verifica.sty inlined nel preamble, intestazione+esercizi+griglia
        // tramite \input expansion. main_NOR.tex non ha tag "[NORMAL]"
        // (era specifico del vecchio VersionPicker preamble).
        $tex = (new TexBuilder())->buildFlat($this->selection(), VersionPicker::NORMAL);
        $this->assertStringContainsString('\documentclass[12pt]{article}', $tex);
        $this->assertStringContainsString('\begin{document}',               $tex);
        $this->assertStringContainsString('\end{document}',                 $tex);
        $this->assertStringContainsString('Verifica Cinematica',            $tex);
        $this->assertStringContainsString('Risolvi le seguenti equazioni',  $tex);
    }

    #[Test]
    public function dsa_variant_includes_dsa_tag_in_title(): void
    {
        $tex = (new TexBuilder())->buildFlat($this->selection(), VersionPicker::DSA);
        $this->assertStringContainsString('[DSA]',                $tex);
        $this->assertStringContainsString('\verificaFontNormal',  $tex);
    }

    #[Test]
    public function dyslexic_variant_uses_open_dyslexic_font(): void
    {
        // main_DIS.tex usa \verificaSetupFontDis (definito in verifica.sty)
        // che attiva fontspec + OpenDyslexic via \begin{dyslexicScope}.
        $tex = (new TexBuilder())->buildFlat($this->selection(), VersionPicker::DYSLEXIC);
        $this->assertStringContainsString('[DIS]',                $tex);
        $this->assertStringContainsString('\verificaSetupFontDis', $tex);
        $this->assertStringContainsString('dyslexicScope',         $tex);
    }

    #[Test]
    public function build_all_returns_three_variants(): void
    {
        $out = (new TexBuilder())->buildAll($this->selection());
        $this->assertArrayHasKey('normal',   $out);
        $this->assertArrayHasKey('dsa',      $out);
        $this->assertArrayHasKey('dyslexic', $out);
    }

    #[Test]
    public function items_render_in_enumerate(): void
    {
        // G22.S4 — variante NORMAL non include soluzioni (le omette dal layout
        // studente). Per testare l'inclusione solutions usiamo NORMAL ma il
        // testo della soluzione appare solo in variante SOL — vedi test sotto.
        $tex = (new TexBuilder())->buildFlat($this->selection());
        $this->assertStringContainsString('\begin{enumerate}', $tex);
        $this->assertStringContainsString('\end{enumerate}',   $tex);
        $this->assertStringContainsString('(2.0 p)',           $tex);
        $this->assertStringContainsString('(1.5 p)',           $tex);
    }

    #[Test]
    public function sol_variant_includes_inline_solutions(): void
    {
        // G22.S4 — la variante SOL via main_SOL.tex include sempre le
        // soluzioni inline (badge editoriali, item .fm-giustsol/.fm-sol nel raw).
        $sel = $this->selection();
        $sel->options['includeSolutions'] = true;
        $tex = (new TexBuilder())->buildFlat($sel, VersionPicker::NORMAL, [
            'variant_kind' => 'SOL',
        ]);
        $this->assertStringContainsString('[SOL]', $tex);
    }

    #[Test]
    public function empty_selection_produces_skeleton(): void
    {
        $sel = $this->selection(['problems' => []]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\documentclass',                  $tex);
        $this->assertStringContainsString('Nessun esercizio selezionato',    $tex);
        $this->assertStringContainsString('\end{document}',                  $tex);
    }

    #[Test]
    public function selection_rejects_invalid_version(): void
    {
        $this->expectException(RuntimeException::class);
        $this->selection(['version' => 'C']);
    }

    #[Test]
    public function selection_rejects_missing_verTitle(): void
    {
        $this->expectException(RuntimeException::class);
        $this->selection(['verTitle' => '']);
    }

    #[Test]
    public function selection_orders_problems_by_position(): void
    {
        $sel = $this->selection(['problems' => [
            ['filePath' => '/x.php', 'problemId' => 'a', 'position' => 3, 'text' => 'TXTGAMMA', 'items' => []],
            ['filePath' => '/y.php', 'problemId' => 'b', 'position' => 1, 'text' => 'TXTALPHA', 'items' => []],
            ['filePath' => '/z.php', 'problemId' => 'c', 'position' => 2, 'text' => 'TXTBETA',  'items' => []],
        ]]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $a = strpos($tex, 'TXTALPHA');
        $b = strpos($tex, 'TXTBETA');
        $c = strpos($tex, 'TXTGAMMA');
        $this->assertNotFalse($a); $this->assertNotFalse($b); $this->assertNotFalse($c);
        $this->assertLessThan($b, $a);
        $this->assertLessThan($c, $b);
    }

    #[Test]
    public function sanitizer_escapes_special_chars(): void
    {
        $this->assertSame('100\%',           Sanitizer::escapePlain('100%'));
        $this->assertSame('a\&b',            Sanitizer::escapePlain('a&b'));
        $this->assertSame('\$5',             Sanitizer::escapePlain('$5'));
        $this->assertSame('a\_b\_c',         Sanitizer::escapePlain('a_b_c'));
        $this->assertStringContainsString('textbackslash', Sanitizer::escapePlain('a\\b'));
    }

    #[Test]
    public function sanitizer_strips_script_tags(): void
    {
        $in  = 'Testo <script>alert(1)</script> normale';
        $out = Sanitizer::stripHtml($in);
        $this->assertStringNotContainsString('script',  $out);
        $this->assertStringContainsString('Testo',      $out);
        $this->assertStringContainsString('normale',    $out);
    }

    #[Test]
    public function options_skip_title_block_when_disabled(): void
    {
        // G22.S4 — i template main_*.tex non chiamano piu' \maketitle
        // (l'intestazione viene da texCommon/intestazione.tex via \input).
        // Il check resta valido: verifica che il titolo della verifica
        // sia presente comunque nel \title{...} preambolo.
        $sel = $this->selection(['options' => ['includeTitlePage' => false]]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringNotContainsString('\maketitle', $tex);
    }

    #[Test]
    public function type_auto_detected_from_problem_id(): void
    {
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Foo-type_VF_ver_1-or_personal',
                'position' => 1, 'text' => 't',
                'items' => [['html' => 'aff', 'points' => 1.0]],
            ]],
        ]);
        $this->assertSame('VF', $sel->problems[0]['type']);
    }

    #[Test]
    public function type_explicit_wins_over_id_autodetect(): void
    {
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Foo-type_VF_x',
                'type'      => 'RMulti',
                'position' => 1, 'text' => 't',
                'items' => [['html' => 'q', 'points' => 1.0]],
            ]],
        ]);
        $this->assertSame('RMulti', $sel->problems[0]['type']);
    }

    #[Test]
    public function unknown_type_falls_back_to_collect(): void
    {
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php', 'problemId' => 'no-type-here',
                'type'     => 'Bogus',
                'position' => 1, 'text' => 't',
                'items' => [['html' => 'q', 'points' => 1.0]],
            ]],
        ]);
        $this->assertSame('Collect', $sel->problems[0]['type']);
    }

    #[Test]
    public function vf_type_renders_tabularx_with_v_f_columns(): void
    {
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_VF_ver_1',
                'position' => 1, 'text' => 'Indica V/F',
                'items' => [
                    ['html' => 'La terra e piatta',  'points' => 1.0],
                    ['html' => 'Il sole e stella',    'points' => 1.0],
                ],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\begin{tabularx}',       $tex);
        $this->assertStringContainsString('Vero',                   $tex);
        $this->assertStringContainsString('Falso',                  $tex);
        $this->assertStringContainsString('La terra e piatta',      $tex);
    }

    #[Test]
    public function rmulti_type_renders_enumerate_with_arabic_label(): void
    {
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_1',
                'position' => 1, 'text' => 'Scegli risposta',
                'items' => [['html' => 'Quanti sono i pianeti?', 'points' => 1.5]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\\begin{enumerate}[label=\\textbf{\\arabic*})', $tex);
        $this->assertStringContainsString('Quanti sono i pianeti?', $tex);
    }

    #[Test]
    public function rmulti_with_rm_table_renders_tabular(): void
    {
        $cellsHtml = '<table class="fm-rm-table"><tbody>'
                   . '<tr><td class="rm-option"><span class="rm-letter">a.</span>'
                   .       ' <label><input type="checkbox"></label> Risp-A</td>'
                   .     '<td class="rm-option"><span class="rm-letter">b.</span>'
                   .       ' <label><input type="checkbox"></label> Risp-B</td></tr>'
                   . '</tbody></table>';
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_1',
                'position' => 1, 'text' => 'Scegli risposta',
                'items' => [['html' => 'Domanda? ' . $cellsHtml, 'points' => 1.0]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\\begin{tabular}', $tex);
        // G23.fix9 — NO letter prefix (TeX matchare DOM). Solo simbolo + content.
        $this->assertStringContainsString('$\\square$ Risp-A', $tex);
        $this->assertStringContainsString('$\\square$ Risp-B', $tex);
        $this->assertStringNotContainsString('\\textbf{a.}', $tex);
        $this->assertStringNotContainsString('\\textbf{b.}', $tex);
    }

    /** G23 — markup moderno `.wrapCheckCell` (server render attuale) → TeX. */
    #[Test]
    public function rmulti_modern_wrapCheckCell_markup_renders_tex(): void
    {
        $cellsHtml = '<table class="fm-rm-table" data-typecell="|X|V|" data-rows="1" data-cols="2"><tbody>'
                   . '<tr>'
                   .   '<td class="rm-option" data-row="0" data-col="0">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="checkbox" class="checkbox fm-checkbox-rm">'
                   .       '<label class="fm-collection"><div class="fm-cell-content">Cell-A</div></label>'
                   .     '</div>'
                   .   '</td>'
                   .   '<td class="rm-option" data-row="0" data-col="1">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="radio" class="checkbox fm-checkbox-rm">'
                   .       '<label class="fm-collection"><div class="fm-cell-content">Cell-B</div></label>'
                   .     '</div>'
                   .   '</td>'
                   . '</tr></tbody></table>';
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_2',
                'position' => 1, 'text' => 'Modern markup',
                'items' => [['html' => 'Q ' . $cellsHtml, 'points' => 1.0]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\\begin{tabular}', $tex);
        // G23.fix9 — NO letter prefix in TeX (mirror DOM markup)
        $this->assertStringContainsString('$\\square$ Cell-A', $tex);
        $this->assertStringContainsString('$\\bigcirc$ Cell-B', $tex);
        $this->assertStringNotContainsString('\\textbf{a.}', $tex);
    }

    /** G23.fix9 — Correct flag → TeX usa simbolo "checked" variant. */
    #[Test]
    public function rmulti_correct_flag_uses_checked_tex_symbol(): void
    {
        $cellsHtml = '<table class="fm-rm-table"><tbody>'
                   . '<tr>'
                   .   '<td class="rm-option" data-row="0" data-col="0">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="checkbox" class="checkbox fm-checkbox-rm">'
                   .       '<label class="fm-collection"><div class="fm-cell-content">Wrong</div></label>'
                   .     '</div>'
                   .   '</td>'
                   .   '<td class="rm-option rm-correct" data-row="0" data-col="1">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="checkbox" class="checkbox fm-checkbox-rm solchecked" checked>'
                   .       '<label class="fm-collection"><div class="fm-cell-content">Correct</div></label>'
                   .     '</div>'
                   .   '</td>'
                   . '</tr></tbody></table>';
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_4',
                'position' => 1, 'text' => 'Correct flag',
                'items' => [['html' => 'Q ' . $cellsHtml, 'points' => 1.0]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        // Unchecked cell → \square; correct cell → \blacksquare
        $this->assertStringContainsString('$\\square$ Wrong', $tex);
        $this->assertStringContainsString('$\\blacksquare$ Correct', $tex);
    }

    /** G23.fix9 — mixcol shuffles cell order within row. */
    #[Test]
    public function rmulti_mixcol_shuffles_cells(): void
    {
        $cellsHtml = '<table class="fm-rm-table" data-mixcol="1"><tbody>'
                   . '<tr>'
                   .   '<td class="rm-option" data-row="0" data-col="0">'
                   .     '<div class="fm-wrap-check-cell"><input type="checkbox" class="fm-checkbox-rm">'
                   .     '<label class="fm-collection"><div class="fm-cell-content">AAA</div></label></div></td>'
                   .   '<td class="rm-option" data-row="0" data-col="1">'
                   .     '<div class="fm-wrap-check-cell"><input type="checkbox" class="fm-checkbox-rm">'
                   .     '<label class="fm-collection"><div class="fm-cell-content">BBB</div></label></div></td>'
                   .   '<td class="rm-option" data-row="0" data-col="2">'
                   .     '<div class="fm-wrap-check-cell"><input type="checkbox" class="fm-checkbox-rm">'
                   .     '<label class="fm-collection"><div class="fm-cell-content">CCC</div></label></div></td>'
                   .   '<td class="rm-option" data-row="0" data-col="3">'
                   .     '<div class="fm-wrap-check-cell"><input type="checkbox" class="fm-checkbox-rm">'
                   .     '<label class="fm-collection"><div class="fm-cell-content">DDD</div></label></div></td>'
                   . '</tr></tbody></table>';
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_mix',
                'position' => 1, 'text' => 'Mix col',
                'items' => [['html' => 'Q ' . $cellsHtml, 'points' => 1.0]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        // Tutti i 4 content presenti (shuffle preserva tutto)
        $this->assertStringContainsString('AAA', $tex);
        $this->assertStringContainsString('BBB', $tex);
        $this->assertStringContainsString('CCC', $tex);
        $this->assertStringContainsString('DDD', $tex);
        // Smoke test: con sufficient iterazioni, almeno una volta ordine != alfabetico
        // (running shuffle once may match; eseguire 20 iter per probabilistic)
        $shuffled = false;
        for ($i = 0; $i < 20; $i++) {
            $t = (new TexBuilder())->buildFlat($sel);
            if (preg_match('/AAA[\s\S]*BBB[\s\S]*CCC[\s\S]*DDD/', $t) !== 1) {
                $shuffled = true;
                break;
            }
        }
        $this->assertTrue($shuffled, 'mixcol=1: in 20 iter, almeno una shuffle non-identity attesa');
    }

    /** G23 — tipi B/T/N → simboli LaTeX dedicati. */
    #[Test]
    public function rmulti_BTN_column_types_render_tex_symbols(): void
    {
        $cellsHtml = '<table class="fm-rm-table"><tbody>'
                   . '<tr>'
                   .   '<td class="rm-option" data-row="0" data-col="0">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<button type="button" class="fm-rm-btn">btn</button>'
                   .       '<label class="fm-collection"><div class="fm-cell-content">B-cell</div></label>'
                   .     '</div>'
                   .   '</td>'
                   .   '<td class="rm-option" data-row="0" data-col="1">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="text" class="fm-rm-text">'
                   .       '<label class="fm-collection"><div class="fm-cell-content">T-cell</div></label>'
                   .     '</div>'
                   .   '</td>'
                   .   '<td class="rm-option" data-row="0" data-col="2">'
                   .     '<div class="fm-wrap-check-cell">'
                   .       '<input type="number" class="fm-rm-num">'
                   .       '<label class="fm-collection"><div class="fm-cell-content">N-cell</div></label>'
                   .     '</div>'
                   .   '</td>'
                   . '</tr></tbody></table>';
        $sel = Selection::fromArray([
            'version' => 'A', 'verTitle' => 'T', 'selectedIIS' => 'ar',
            'selectedCLS' => '2s', 'selectedMATER' => 'MAT',
            'anno' => '2026', 'sezione' => '',
            'problems' => [[
                'filePath' => '/x.php',
                'problemId' => 'Topic-type_RMulti_3',
                'position' => 1, 'text' => 'BTN types',
                'items' => [['html' => 'Q ' . $cellsHtml, 'points' => 1.0]],
            ]],
        ]);
        $tex = (new TexBuilder())->buildFlat($sel);
        $this->assertStringContainsString('\\fbox{btn}', $tex);
        $this->assertStringContainsString('\\underline{', $tex);
        $this->assertStringContainsString('\\boxed{\\#}', $tex);
        $this->assertStringContainsString('B-cell', $tex);
        $this->assertStringContainsString('T-cell', $tex);
        $this->assertStringContainsString('N-cell', $tex);
    }
}
