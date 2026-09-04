<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ContractRenderer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AI Act art. 50(2) — marcatura leggibile da macchina degli output sintetici.
 *
 * La verifica che conta di più è quella NEGATIVA: un contenuto scritto a mano
 * dal docente non deve emettere nulla. Una marcatura falsa positiva è essa
 * stessa un difetto di trasparenza.
 */
final class ContractRendererAiMarkingTest extends TestCase
{
    private function render(?array $ai): string
    {
        $item = [
            'id'            => 'q1',
            'question'      => [['type' => 'text', 'content' => 'Calcola il limite.']],
            'justification' => [],
            'difficulty'    => 2,
        ];
        if ($ai !== null) {
            $item['ai'] = $ai;
        }
        return (new ContractRenderer([]))->renderContract([
            'title'  => 'Scheda esercizi',
            'groups' => [[
                'id' => 'g1', 'type' => 'type_Collect', 'title' => 'Grp', 'intro' => '',
                'items' => [$item],
            ]],
        ]);
    }

    private function generated(array $fields = ['solution']): array
    {
        return [
            'generated'      => true,
            'fields'         => $fields,
            'ops'            => [['op' => 'solutions', 'model' => 'm', 'at' => '2026-08-26T10:00:00+00:00']],
            'human_reviewed' => true,
        ];
    }

    #[Test]
    public function human_authored_content_emits_no_marking_at_all(): void
    {
        $html = $this->render(null);

        $this->assertStringNotContainsString('data-ai-generated', $html);
        $this->assertStringNotContainsString('fm-aimark', $html);
        $this->assertStringNotContainsString('ld+json', $html);
    }

    #[Test]
    public function generated_false_is_treated_as_not_generated(): void
    {
        $html = $this->render(['generated' => false, 'fields' => ['solution']]);
        $this->assertStringNotContainsString('data-ai-generated', $html);
    }

    #[Test]
    public function item_carries_machine_readable_attributes(): void
    {
        $html = $this->render($this->generated());

        $this->assertStringContainsString('data-ai-generated="true"', $html);
        $this->assertStringContainsString('data-ai-fields="solution"', $html);
    }

    #[Test]
    public function page_level_meta_and_jsonld_are_emitted(): void
    {
        $html = $this->render($this->generated(['solution', 'category_label']));

        $this->assertStringContainsString('<meta name="fm-ai-generated" content="partial">', $html);
        $this->assertStringContainsString('fm-ai-generated-fields', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('EU 2024/1689 art. 50(2)', $html);
    }

    #[Test]
    public function jsonld_payload_is_valid_json_and_lists_the_item(): void
    {
        $html = $this->render($this->generated());

        $this->assertSame(1, preg_match(
            '#<script type="application/ld\+json">(.+?)</script>#s',
            $html,
            $m
        ));
        $data = json_decode($m[1], true);
        $this->assertIsArray($data);
        $this->assertSame('CreativeWork', $data['@type']);
        $this->assertTrue($data['pantedu:aiGenerated']['humanReviewed']);
        $this->assertSame(['solution'], $data['pantedu:aiGenerated']['fields']);
        $this->assertContains('q1', $data['pantedu:aiGenerated']['itemIds']);
    }

    #[Test]
    public function visible_marker_is_rendered_with_an_accessible_label(): void
    {
        $html = $this->render($this->generated());

        $this->assertStringContainsString('class="fm-aimark"', $html);
        $this->assertStringContainsString('>IA<', $html);
        // WCAG 1.4.1 — il significato non è affidato al solo colore/sigla.
        $this->assertStringContainsString('aria-label="Generato da IA (soluzione), rivisto dal docente"', $html);
    }

    #[Test]
    public function marker_appears_even_when_the_item_has_no_badge(): void
    {
        // La badge-row esiste solo se c'è un badge: senza questo caso la
        // marcatura visibile sparirebbe sugli esercizi senza numero.
        $html = $this->render($this->generated());
        $this->assertStringContainsString('fm-badge-row', $html);
        $this->assertStringContainsString('fm-aimark', $html);
    }

    #[Test]
    public function jsonld_item_ids_match_the_dom_data_ids(): void
    {
        // Senza questa corrispondenza il JSON-LD non è correlabile al DOM e la
        // marcatura leggibile da macchina perde di utilità. Il caso critico è
        // il gruppo SENZA id esplicito, dove entrambi i lati usano un fallback.
        $html = (new ContractRenderer([]))->renderContract([
            'title'  => 'Scheda',
            'groups' => [[
                'type' => 'type_Collect', 'title' => 'Grp', 'intro' => '',
                'items' => [[
                    // niente 'id' né sull'item né sul gruppo
                    'question'      => [['type' => 'text', 'content' => 'Q?']],
                    'justification' => [],
                    'ai'            => $this->generated(),
                ]],
            ]],
        ]);

        preg_match('/<div class="fm-collection__item[^>]*data-id="([^"]+)"/', $html, $dom);
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $ld);
        $ids = json_decode($ld[1], true)['pantedu:aiGenerated']['itemIds'];

        $this->assertSame([$dom[1]], $ids);
    }

    #[Test]
    public function explicit_ids_are_used_verbatim(): void
    {
        $html = $this->render($this->generated());
        $ld = [];
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $ld);
        $this->assertSame(['q1'], json_decode($ld[1], true)['pantedu:aiGenerated']['itemIds']);
    }

    #[Test]
    public function field_names_are_escaped_into_the_attribute(): void
    {
        $html = $this->render($this->generated(['sol"ution']));
        $this->assertStringNotContainsString('data-ai-fields="sol"ution"', $html);
        $this->assertStringContainsString('&quot;', $html);
    }
}
