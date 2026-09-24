<?php

namespace Tests\Unit\Contract;

use App\Services\Contract\ContractAggregate;
use App\Services\Contract\ContractItemNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContractAggregateTest extends TestCase
{
    private function sampleContract(): array
    {
        return [
            'title' => 'Parabola',
            'version' => 3,
            'meta' => ['source_citation' => 'MMB vol.2'],
            'groups' => [
                [
                    'id' => 'g_vf',
                    'type' => 'VF',
                    'title' => 'Vero o Falso',
                    'items' => [
                        ['id' => '101', 'question' => 'A', 'answer' => 'V', 'origin' => 'mmb_v1'],
                        ['id' => '102', 'question' => 'B', 'answer' => 'F'],
                    ],
                ],
                [
                    'id' => 'g_rm',
                    'type' => 'RM',
                    'items' => [
                        ['question' => 'Q1'],
                        ['question' => 'Q2'],
                        ['question' => 'Q3'],
                    ],
                ],
            ],
        ];
    }

    private function make(): ContractAggregate
    {
        return new ContractAggregate(42, 'contracts/c42.json', $this->sampleContract());
    }

    #[Test]
    public function exposes_basic_accessors(): void
    {
        $a = $this->make();
        $this->assertSame(42, $a->contentId);
        $this->assertSame('contracts/c42.json', $a->storageKey);
        $this->assertSame(3, $a->version());
        $this->assertSame('Parabola', $a->title());
        $this->assertCount(2, $a->groups());
        $this->assertSame('MMB vol.2', $a->meta()['source_citation']);
    }

    #[Test]
    public function find_item_by_numeric_id(): void
    {
        $a = $this->make();
        $this->assertSame([0, 1], $a->findItemIndex('102'));
        $this->assertSame([0, 0], $a->findItemIndex('101'));
    }

    #[Test]
    public function find_item_by_synthetic_group_prefix(): void
    {
        $a = $this->make();
        // g_rm_q2 → group index 1, item index 2
        $this->assertSame([1, 2], $a->findItemIndex('g_rm_q2'));
    }

    #[Test]
    public function find_item_by_group_index_fallback(): void
    {
        $a = $this->make();
        // g1_q0 → group index 1, item index 0 (se l'id del gruppo non matcha)
        $this->assertSame([1, 0], $a->findItemIndex('g1_q0'));
    }

    #[Test]
    public function returns_null_when_item_not_found(): void
    {
        $a = $this->make();
        $this->assertNull($a->findItemIndex('inexistent'));
        $this->assertNull($a->findItemIndex(''));
    }

    #[Test]
    public function patch_item_merges_fields(): void
    {
        $a = $this->make();
        $a->patchItem('101', ['origin' => 'cdm_v3', 'color' => 'red']);
        $item = $a->getItem('101');
        $this->assertSame('cdm_v3', $item['origin']);   // overridden
        $this->assertSame('red', $item['color']);       // new
        $this->assertSame('A', $item['question']);      // preserved
    }

    #[Test]
    public function patch_unknown_item_throws(): void
    {
        $this->expectException(ContractItemNotFoundException::class);
        $this->make()->patchItem('ghost', ['x' => 1]);
    }

    #[Test]
    public function delete_item_reindexes_siblings(): void
    {
        $a = $this->make();
        $a->deleteItem('g_rm_q1');
        $items = $a->groups()[1]['items'];
        $this->assertCount(2, $items);
        $this->assertSame('Q1', $items[0]['question']);
        $this->assertSame('Q3', $items[1]['question']);
        // Dopo reindex, g_rm_q1 ora punta all'ex-Q3
        $this->assertSame('Q3', $a->getItem('g_rm_q1')['question']);
    }

    #[Test]
    public function move_item_reorders_within_group(): void
    {
        $a = $this->make();
        $a->moveItem('g_rm_q2', 0); // Q3 in posizione 0
        $items = $a->groups()[1]['items'];
        $this->assertSame('Q3', $items[0]['question']);
        $this->assertSame('Q1', $items[1]['question']);
        $this->assertSame('Q2', $items[2]['question']);
    }

    #[Test]
    public function move_item_clamps_to_bounds(): void
    {
        $a = $this->make();
        $a->moveItem('g_rm_q0', 999); // clamp a count-1 = 2
        $items = $a->groups()[1]['items'];
        $this->assertSame('Q2', $items[0]['question']);
        $this->assertSame('Q3', $items[1]['question']);
        $this->assertSame('Q1', $items[2]['question']);
    }

    #[Test]
    public function bump_version_increments_by_one(): void
    {
        $a = $this->make();
        $a->bumpVersion();
        $this->assertSame(4, $a->version());
        $a->bumpVersion()->bumpVersion();
        $this->assertSame(6, $a->version());
    }

    #[Test]
    public function patch_meta_merges(): void
    {
        $a = $this->make();
        $a->patchMeta(['topic' => 'Parabola', 'source_citation' => 'updated']);
        $this->assertSame('Parabola', $a->meta()['topic']);
        $this->assertSame('updated', $a->meta()['source_citation']);
    }

    #[Test]
    public function fluent_api_allows_chaining(): void
    {
        $a = $this->make();
        $result = $a->patchItem('101', ['points' => 2])
                    ->moveItem('101', 1)
                    ->bumpVersion();
        $this->assertSame($a, $result);
        $this->assertSame(4, $a->version());
        $this->assertSame(2, $a->getItem('101')['points']);
    }

    #[Test]
    public function ensure_item_ids_fills_missing_ids_with_uuid(): void
    {
        $a = $this->make();
        // Il sample ha items in g_rm senza id. Devono ricevere UUID.
        $this->assertTrue($a->ensureItemIds());
        $items = $a->groups()[1]['items'];
        foreach ($items as $it) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                (string)$it['id']
            );
        }
        // I numerici esistenti restano invariati
        $this->assertSame('101', $a->groups()[0]['items'][0]['id']);
    }

    #[Test]
    public function ensure_item_ids_is_idempotent(): void
    {
        $a = $this->make();
        $this->assertTrue($a->ensureItemIds());
        $firstPass = json_encode($a->data());
        $this->assertFalse($a->ensureItemIds()); // nessuna modifica seconda volta
        $this->assertSame($firstPass, json_encode($a->data()));
    }

    #[Test]
    public function ensure_item_ids_generates_unique_values(): void
    {
        $a = new ContractAggregate(99, 'c/99.json', [
            'groups' => [
                ['id' => 'g', 'items' => [[], [], [], [], []]],
            ],
        ]);
        $a->ensureItemIds();
        $ids = array_map(fn($i) => $i['id'], $a->groups()[0]['items']);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    #[Test]
    public function compute_stats_counts_items_and_types(): void
    {
        $s = $this->make()->computeStats();
        $this->assertSame(5, $s['item_count']);  // 2 VF + 3 RM
        $this->assertSame(2, $s['group_count']);
        $this->assertSame(['VF' => 1, 'RM' => 1], $s['group_types']);
        $this->assertTrue($s['has_vf']);
        $this->assertTrue($s['has_rm']);
        $this->assertFalse($s['has_collect']);
    }

    #[Test]
    public function compute_stats_aggregates_sources_uniquely(): void
    {
        $a = new ContractAggregate(1, 'c/1.json', [
            'groups' => [[
                'items' => [
                    ['id' => '1', 'origin' => 'mmb_v1'],
                    ['id' => '2', 'origin' => 'mmb_v1'],  // dup
                    ['id' => '3', 'source' => 'cdm_v2'],
                    ['id' => '4'],  // niente source
                ],
            ]],
        ]);
        $s = $a->computeStats();
        sort($s['source_codes']);
        $this->assertSame(['cdm_v2', 'mmb_v1'], $s['source_codes']);
    }

    #[Test]
    public function compute_stats_calculates_difficulty_max_avg(): void
    {
        $a = new ContractAggregate(1, 'c/1.json', [
            'groups' => [[
                'items' => [
                    ['id' => '1', 'difficulty' => 1],
                    ['id' => '2', 'difficulty' => 3],
                    ['id' => '3', 'difficulty' => 4],
                ],
            ]],
        ]);
        $s = $a->computeStats();
        $this->assertSame(4, $s['difficulty_max']);
        $this->assertSame(round(8 / 3, 2), $s['difficulty_avg']);
    }

    #[Test]
    public function compute_stats_detects_tikz(): void
    {
        $a = new ContractAggregate(1, 'c/1.json', [
            'groups' => [[
                'items' => [
                    ['id' => '1', 'question' => [['type' => 'text', 'raw' => 'hi']]],
                    ['id' => '2', 'question' => [['type' => 'tikz', 'raw' => '...']]],
                ],
            ]],
        ]);
        $s = $a->computeStats();
        $this->assertTrue($s['has_tikz']);
    }

    #[Test]
    public function duplicate_item_inserts_copy_after_source(): void
    {
        $a = new ContractAggregate(1, 'c/1.json', [
            'groups' => [[
                'id' => 'g1',
                'items' => [
                    ['id' => 'a1', 'question' => 'Q1'],
                    ['id' => 'a2', 'question' => 'Q2'],
                ],
            ]],
        ]);
        $newId = $a->duplicateItem('a1');
        $items = $a->groups()[0]['items'];
        $this->assertCount(3, $items);
        $this->assertSame('a1', $items[0]['id']);
        $this->assertSame($newId, $items[1]['id']);
        $this->assertSame('Q1', $items[1]['question']);  // content copiato
        $this->assertSame('a2', $items[2]['id']);
        $this->assertNotSame('a1', $newId);              // id diverso
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $newId
        );
    }

    #[Test]
    public function duplicate_unknown_item_throws(): void
    {
        $this->expectException(ContractItemNotFoundException::class);
        $this->make()->duplicateItem('ghost');
    }

    #[Test]
    public function find_group_by_title_case_insensitive(): void
    {
        $a = $this->make();
        $this->assertSame(0, $a->findGroupByTitle('vero o falso'));
        $this->assertSame(0, $a->findGroupByTitle('  VERO o FALSO  '));
        $this->assertNull($a->findGroupByTitle('inexistent'));
        $this->assertNull($a->findGroupByTitle(''));
    }

    #[Test]
    public function append_item_to_group_assigns_uuid_if_missing(): void
    {
        $a = $this->make();
        $id = $a->appendItemToGroup(0, ['question' => 'NEW']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
        $items = $a->groups()[0]['items'];
        $this->assertSame('NEW', end($items)['question']);
    }

    #[Test]
    public function append_item_to_group_preserves_explicit_id(): void
    {
        $a = $this->make();
        $id = $a->appendItemToGroup(0, ['id' => 'custom-xyz', 'question' => 'Q']);
        $this->assertSame('custom-xyz', $id);
    }

    #[Test]
    public function append_group_adds_new_group_with_uuid(): void
    {
        $a = $this->make();
        $before = count($a->groups());
        $id = $a->appendGroup([
            'title' => 'Nuovo gruppo',
            'type' => 'Collect',
            'items' => [['question' => 'X'], ['question' => 'Y']],
        ]);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->assertSame($before + 1, count($a->groups()));
        $last = end($a->data()['groups']);
        $this->assertSame('Nuovo gruppo', $last['title']);
        // Items hanno UUID assegnati
        foreach ($last['items'] as $it) {
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $it['id']);
        }
    }

    #[Test]
    public function compute_stats_empty_contract_safe(): void
    {
        $a = new ContractAggregate(1, 'c/1.json', []);
        $s = $a->computeStats();
        $this->assertSame(0, $s['item_count']);
        $this->assertSame(0, $s['group_count']);
        $this->assertSame(0, $s['difficulty_max']);
        $this->assertSame(0.0, $s['difficulty_avg']);
        $this->assertSame([], $s['source_codes']);
    }
}
