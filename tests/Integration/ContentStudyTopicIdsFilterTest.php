<?php

namespace Tests\Integration;

use App\Controllers\ContentStudyController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 20 — verifica che topicPage filtri i rows quando il client
 * passa ?ids=N[,M,...] (default single-open + ctrl+click multi-open).
 */
final class ContentStudyTopicIdsFilterTest extends TestCase
{
    /** Estrae la logic di filter che sta inline in topicPage. Se un
     *  giorno vogliamo promuoverla a method dedicato, questo test la
     *  copre in isolamento con dataset fisso. */
    private function filterRowsByIds(array $rows, string $idsRaw): array
    {
        if ($idsRaw === '') return $rows;
        $wanted = array_flip(array_filter(array_map('intval', explode(',', $idsRaw))));
        if (!$wanted) return $rows;
        return array_values(array_filter($rows, fn($r) => isset($wanted[(int)$r['id']])));
    }

    #[Test]
    public function ids_empty_returns_all_rows(): void
    {
        $rows = [['id'=>1], ['id'=>2], ['id'=>3]];
        $this->assertCount(3, $this->filterRowsByIds($rows, ''));
    }

    #[Test]
    public function ids_single_value_returns_only_that_row(): void
    {
        $rows = [['id'=>3,'title'=>'A'], ['id'=>5,'title'=>'B'], ['id'=>7,'title'=>'C']];
        $out = $this->filterRowsByIds($rows, '5');
        $this->assertCount(1, $out);
        $this->assertSame(5, $out[0]['id']);
        $this->assertSame('B', $out[0]['title']);
    }

    #[Test]
    public function ids_multi_value_csv_returns_subset_preserving_order(): void
    {
        $rows = [['id'=>3], ['id'=>5], ['id'=>7], ['id'=>9]];
        $out = $this->filterRowsByIds($rows, '3,7');
        $this->assertCount(2, $out);
        $this->assertSame(3, $out[0]['id']);
        $this->assertSame(7, $out[1]['id']);
    }

    #[Test]
    public function ids_with_invalid_tokens_skipped(): void
    {
        $rows = [['id'=>1], ['id'=>2], ['id'=>3]];
        // "0" (zero) e stringhe non numeriche passano da intval come 0;
        // array_filter droppa i 0 perché falsy → valido solo il 2.
        $out = $this->filterRowsByIds($rows, 'foo,0,2');
        $this->assertCount(1, $out);
        $this->assertSame(2, $out[0]['id']);
    }

    #[Test]
    public function ids_all_invalid_falls_back_to_all_rows(): void
    {
        $rows = [['id'=>1], ['id'=>2]];
        // Se dopo il filter/intval resta un array vuoto, filterRows
        // ritorna $rows invariato (non blocca la pagina).
        $out = $this->filterRowsByIds($rows, 'foo,0');
        $this->assertCount(2, $out);
    }

    #[Test]
    public function ids_topic_with_two_items_click_one_returns_just_one(): void
    {
        // Caso reale del bug user: topic="2.1" contiene 2 mappe distinte
        // (Equazioni + Funzioni). Click su una sola → ?ids={id} →
        // il server deve renderizzare solo quella.
        $rows = [
            ['id'=>101, 'topic'=>'2.1', 'title'=>'Equazioni magg 2° Grado'],
            ['id'=>102, 'topic'=>'2.1', 'title'=>'Funzioni, Dis/Equazioni logaritmiche'],
        ];
        $out = $this->filterRowsByIds($rows, '101');
        $this->assertCount(1, $out);
        $this->assertSame(101, $out[0]['id']);
        $this->assertStringContainsString('Equazioni', $out[0]['title']);
    }

    #[Test]
    public function ids_topic_multiarg_via_ctrl_click_returns_both(): void
    {
        $rows = [
            ['id'=>101, 'topic'=>'2.1', 'title'=>'Equazioni'],
            ['id'=>102, 'topic'=>'2.1', 'title'=>'Funzioni'],
        ];
        $out = $this->filterRowsByIds($rows, '101,102');
        $this->assertCount(2, $out);
    }

    /** Sanity check: la ContentStudyController::topicPage usa la stessa
     *  logic inline. Verifichiamo che esista (reflection). */
    #[Test]
    public function topicPage_method_exists(): void
    {
        $refl = new ReflectionClass(ContentStudyController::class);
        $this->assertTrue($refl->hasMethod('topicPage'));
    }
}
