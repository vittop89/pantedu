<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContractRenderer;
use App\Services\Rendering\RmColumnTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G23 — Test centralizzazione renderer RM tables.
 *
 * Verifica:
 *   - Markup parity client/server (`.wrapCheckCell > input + label.fm-collection`)
 *   - Tipi colonna X/V/B/T/N → input HTML corretti
 *   - `rmLayout.rows/cols` rispettati (no auto-chunk se specificati)
 *   - Fallback 2x2 / auto-chunk se rmLayout assente
 *   - No `.rm-letter` markup decorativo (server)
 */
final class ContractRendererRmTest extends TestCase
{
    private function renderRmItem(array $options, array $rmLayout = []): string
    {
        $renderer = new ContractRenderer([]);
        $contract = [
            'title' => 'Test',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_RMulti_1', 'title' => 'Grp', 'intro' => '',
                'items' => [[
                    'id' => 'q1', 'question' => [['type' => 'text', 'content' => 'Q?']],
                    'options' => $options,
                    'rmLayout' => $rmLayout,
                    'justification' => [],
                ]],
            ]],
        ];
        return $renderer->renderContract($contract);
    }

    #[Test]
    public function rm_table_emits_wrapCheckCell_markup(): void
    {
        $html = $this->renderRmItem([
            ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text','content' => 'Alpha']]],
            ['letter' => 'b', 'correct' => true,  'content' => [['type' => 'text','content' => 'Beta']]],
        ], ['rows' => 1, 'cols' => 2, 'typecell' => '|X|X|']);

        $this->assertStringContainsString('class="fm-rm-table"', $html);
        $this->assertStringContainsString('data-typecell="|X|X|"', $html);
        $this->assertStringContainsString('data-rows="1"', $html);
        $this->assertStringContainsString('data-cols="2"', $html);
        $this->assertStringContainsString('class="fm-wrap-check-cell"', $html);
        $this->assertStringContainsString('class="checkbox fm-checkbox-rm', $html);
        $this->assertStringContainsString('class="fm-cell-content"', $html);
        $this->assertStringContainsString('Alpha', $html);
        $this->assertStringContainsString('Beta', $html);
    }

    #[Test]
    public function rm_table_does_not_emit_rm_letter_span(): void
    {
        $html = $this->renderRmItem([
            ['letter' => 'a', 'correct' => false, 'content' => [['type' => 'text','content' => 'X']]],
        ], ['rows' => 1, 'cols' => 1, 'typecell' => '|X|']);
        $this->assertStringNotContainsString('rm-letter', $html);
    }

    #[Test]
    public function rm_table_respects_explicit_rows_cols_layout(): void
    {
        // 4 options con rmLayout 2×2 → 2 righe da 2 celle (no auto-1×4)
        $html = $this->renderRmItem([
            ['correct' => false, 'content' => [['type' => 'text','content' => 'A']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'B']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'C']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'D']]],
        ], ['rows' => 2, 'cols' => 2, 'typecell' => '|X|X|']);
        $this->assertStringContainsString('data-rows="2"', $html);
        $this->assertStringContainsString('data-cols="2"', $html);
        // 2 <tr> attesi
        $this->assertSame(2, substr_count($html, '<tr>'));
    }

    #[Test]
    public function rm_table_BTN_types_emit_corresponding_inputs(): void
    {
        $html = $this->renderRmItem([
            ['correct' => false, 'content' => [['type' => 'text','content' => 'btn-cell']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'txt-cell']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'num-cell']]],
        ], ['rows' => 1, 'cols' => 3, 'typecell' => '|B|T|N|']);

        $this->assertStringContainsString('<button type="button" class="fm-rm-btn"', $html);
        $this->assertStringContainsString('<input type="text" class="fm-rm-text"', $html);
        $this->assertStringContainsString('<input type="number" class="fm-rm-num"', $html);
    }

    #[Test]
    public function rm_table_correct_option_gets_correct_class(): void
    {
        $html = $this->renderRmItem([
            ['correct' => false, 'content' => [['type' => 'text','content' => 'A']]],
            ['correct' => true,  'content' => [['type' => 'text','content' => 'B']]],
        ], ['rows' => 1, 'cols' => 2, 'typecell' => '|X|X|']);
        $this->assertMatchesRegularExpression('/td class="rm-option rm-correct"/', $html);
        $this->assertStringContainsString('checked', $html);
    }

    #[Test]
    public function rm_table_fallback_dimensions_when_layout_missing(): void
    {
        // No rmLayout: deve produrre comunque una tabella (default 2x2 o 1xN)
        $html = $this->renderRmItem([
            ['correct' => false, 'content' => [['type' => 'text','content' => 'A']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'B']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'C']]],
            ['correct' => false, 'content' => [['type' => 'text','content' => 'D']]],
        ]);
        $this->assertStringContainsString('class="fm-rm-table"', $html);
        $this->assertStringContainsString('data-rows="', $html);
        $this->assertStringContainsString('data-cols="', $html);
    }

    #[Test]
    public function rm_column_types_helper_consistency(): void
    {
        // Roundtrip parseTypecell ↔ enumerate
        foreach (RmColumnTypes::TYPES as $t) {
            $this->assertNotEmpty(RmColumnTypes::toHtml($t));
            $this->assertNotEmpty(RmColumnTypes::toTex($t));
        }
        $parsed = RmColumnTypes::parseTypecell('|X|V|B|T|N|', 5);
        $this->assertSame(['X','V','B','T','N'], $parsed);
        // Pad missing
        $parsed2 = RmColumnTypes::parseTypecell('|X|', 3);
        $this->assertSame(['X','X','X'], $parsed2);
    }

    /**
     * G23.fix4 — Centralizzazione field schema: group `intro` può essere
     * sia stringa (legacy) sia array di blocks (uniforme con question/answer).
     * Verifica entrambi i casi + nested list preservata.
     */
    #[Test]
    public function group_intro_as_array_of_blocks_renders_correctly(): void
    {
        $renderer = new ContractRenderer([]);
        $contract = [
            'title' => 'T',
            'groups' => [[
                'id' => 'g1',
                'type' => 'type_Collect',
                'title' => 'Grp',
                'intro' => [
                    ['type' => 'text', 'content' => 'CELLA'],
                    ['type' => 'list', 'ordered' => true, 'list_preset' => 'lower-alpha-roman',
                     'dsa_section' => 'question',
                     'items' => [
                         [
                             ['type' => 'text', 'content' => 'u'],
                             ['type' => 'list', 'ordered' => true, 'dsa_section' => 'sub',
                              'items' => [
                                  [
                                      ['type' => 'text', 'content' => 'uu'],
                                      ['type' => 'list', 'ordered' => true, 'dsa_section' => 'sub',
                                       'items' => [[['type' => 'text', 'content' => 'uuu']]]],
                                  ],
                              ]],
                         ],
                         [['type' => 'text', 'content' => 'd']],
                     ]],
                ],
                'items' => [],
            ]],
        ];
        $html = $renderer->renderContract($contract);
        $this->assertStringContainsString('CELLA', $html);
        // Nested list preservata: uuu deve apparire
        $this->assertStringContainsString('uuu', $html);
        // Multiple ol tags (outer + 2 nested)
        $this->assertGreaterThanOrEqual(3, substr_count($html, '<ol'));
    }

    #[Test]
    public function group_intro_as_string_legacy_still_works(): void
    {
        $renderer = new ContractRenderer([]);
        $contract = [
            'title' => 'T',
            'groups' => [[
                'id' => 'g1',
                'type' => 'type_Collect',
                'title' => 'Grp',
                'intro' => 'plain text intro',
                'items' => [],
            ]],
        ];
        $html = $renderer->renderContract($contract);
        $this->assertStringContainsString('plain text intro', $html);
    }

    #[Test]
    public function group_intro_as_string_with_html_preserved(): void
    {
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Collect', 'title' => 'Grp',
                'intro' => '<b>bold</b> text',
                'items' => [],
            ]],
        ]);
        $this->assertStringContainsString('<b>bold</b>', $html);
    }

    /** G23.fix16 — Field separato `g.giustifica` per VF/RM. Default hardcoded
     *  se assente. */
    #[Test]
    public function group_giustifica_field_default_when_absent(): void
    {
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_RMulti', 'title' => 'Grp',
                'intro' => '',
                // no 'giustifica' field
                'items' => [],
            ]],
        ]);
        $this->assertStringContainsString('Giustifica adeguatamente le risposte', $html);
        $this->assertMatchesRegularExpression('/<span\s+class="fm-giustifica"/', $html);
    }

    #[Test]
    public function group_giustifica_field_custom_text(): void
    {
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_VF', 'title' => 'Grp',
                'intro' => '',
                'giustifica' => 'Spiega le tue scelte con cura',
                'items' => [],
            ]],
        ]);
        $this->assertStringContainsString('Spiega le tue scelte con cura', $html);
        $this->assertStringNotContainsString('Giustifica adeguatamente le risposte', $html);
    }

    /** G24.phase1 — XSS sanitization end-to-end via ContractRenderer.
     *  NB: `data-raw` attribute conserva il sorgente HTML-escaped per
     *  round-trip editor → contiene `javascript:` LETTERAL ma escapato
     *  (innocuo, browser non lo esegue). Test verifica SOLO il visibile
     *  (HTML attivo fuori da data-raw). */
    #[Test]
    public function renderer_sanitizes_xss_in_text_block(): void
    {
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Collect', 'title' => 'Grp', 'intro' => '',
                'items' => [[
                    'id' => 'q1',
                    'question' => [['type' => 'text', 'content' => '<a href="javascript:alert(1)">click</a><script>alert(2)</script>x']],
                    'solution' => [],
                ]],
            ]],
        ]);
        // Estrai SOLO il visibile (fuori dall'attributo data-raw escapato)
        $visibleOnly = preg_replace('/data-raw="[^"]*"/', '', $html) ?? $html;
        // XSS vector strippati nel visibile
        $this->assertStringNotContainsString('<script', $visibleOnly);
        $this->assertStringNotContainsString('href="javascript:', $visibleOnly);
        // Content "click" e "x" preservati (sanitization keeps text)
        $this->assertStringContainsString('click', $visibleOnly);
        $this->assertStringContainsString('>x<', $visibleOnly);
        // <a> ancora presente ma SENZA href javascript (clean link or unwrap)
        $this->assertMatchesRegularExpression('#<a[^>]*>click</a>#', $visibleOnly);
    }

    #[Test]
    public function renderer_sanitizes_xss_in_string_intro(): void
    {
        // intro come stringa raw (legacy path, no array blocks) NON ha data-raw,
        // quindi possiamo testare $html direttamente.
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Collect', 'title' => 'Grp',
                'intro' => '<b>safe</b><span onclick="alert(1)">x</span><iframe src="evil"></iframe>',
                'items' => [],
            ]],
        ]);
        $this->assertStringNotContainsString('<iframe', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('<b>safe</b>', $html);
    }

    #[Test]
    public function group_giustifica_collect_no_giustifica(): void
    {
        // Collect type NON ha giustifica (solo VF/RM)
        $renderer = new ContractRenderer([]);
        $html = $renderer->renderContract([
            'title' => 'T',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Collect', 'title' => 'Grp',
                'intro' => 'plain intro',
                'giustifica' => 'should be ignored',
                'items' => [],
            ]],
        ]);
        // Collect non emette span giustifica
        $this->assertStringNotContainsString('class="fm-giustifica"', $html);
        $this->assertStringNotContainsString('should be ignored', $html);
    }

    #[Test]
    public function rm_column_types_normalize_unknown_to_X(): void
    {
        $this->assertSame('X', RmColumnTypes::normalize('Z'));
        $this->assertSame('X', RmColumnTypes::normalize(''));
        $this->assertSame('X', RmColumnTypes::normalize(null));
        $this->assertSame('V', RmColumnTypes::normalize('v'));
    }

    /**
     * G23.fix3 — Regression test: una option content con nested list (3 livelli)
     * deve rendere HTML con OL nidificati visibili, NON flat.
     */
    #[Test]
    public function rm_option_with_nested_list_renders_nested_OL(): void
    {
        $nestedList = [
            'type' => 'list',
            'ordered' => true,
            'list_style' => 'a',
            'dsa_section' => 'options',
            'items' => [
                // LI1: "u" + nested ol "i"
                [
                    ['type' => 'text', 'content' => 'u'],
                    [
                        'type' => 'list',
                        'ordered' => true,
                        'list_style' => 'i',
                        'dsa_section' => 'sub',
                        'items' => [
                            // LI1.1: "uu" + nested ol (decimal)
                            [
                                ['type' => 'text', 'content' => 'uu'],
                                [
                                    'type' => 'list',
                                    'ordered' => true,
                                    'dsa_section' => 'sub',
                                    'items' => [
                                        [['type' => 'text', 'content' => 'uuu']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                // LI2: "d"
                [['type' => 'text', 'content' => 'd']],
            ],
        ];
        $html = $this->renderRmItem([
            ['letter' => 'a', 'correct' => false, 'content' => [$nestedList]],
            ['letter' => 'b', 'correct' => false, 'content' => [['type' => 'text', 'content' => 'Es2']]],
        ], ['rows' => 1, 'cols' => 2, 'typecell' => '|X|X|']);

        // G23.fix6 — Top-level OL section options (LI plain, CSS native marker
        // via `.rm-option .fm-dsa-li-list > li { display: list-item }`).
        $this->assertStringContainsString('data-dsa-section="options"', $html);
        // Content "u", "uu", "uuu", "d" tutti presenti (preservata struttura)
        $this->assertStringContainsString('uuu', $html);
        $this->assertStringContainsString('uu', $html);
        $this->assertStringContainsString('>u<', $html);
        $this->assertStringContainsString('>d<', $html);
        // NO F/GF buttons in options section
        $this->assertStringNotContainsString('fm-dsa-li-F', $html);
        $this->assertStringNotContainsString('fm-dsa-li-GF', $html);
        // G23.fix6 — NO .fm-dsa-li-num spans (CSS native marker handles outer)
        $this->assertStringNotContainsString('fm-dsa-li-num', $html);
        $this->assertStringNotContainsString('fm-dsa-li-content', $html);
        // Multiple ol tags (almeno 3: outer + 2 nested)
        $this->assertGreaterThanOrEqual(3, substr_count($html, '<ol'));
    }
}
