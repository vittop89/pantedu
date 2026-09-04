<?php

namespace Tests\Integration;

use App\Services\Risdoc\TemplateDefaults;
use App\Support\Storage\LocalStorageProvider;
use App\Support\Storage\StorageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Phase 20 / ADR-029 — test per la logica template-default
 * (App\Services\Risdoc\TemplateDefaults, estratta da TeacherContentController).
 *
 * Copre:
 *   - Seed default contiene VF/RM/Collect con items popolati
 *   - normalizeType mappa type_VF/type_RMulti/type_Collect → VF/RM/Collect
 *   - Template salvato nella key convention si legge correttamente
 *   - VF seed ha 3 affermazioni, RM ha 4 opzioni con 1 corretta
 */
final class TeacherTemplatesTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->sandbox = sys_get_temp_dir() . '/pantedu_tpl_' . uniqid();
        @mkdir($this->sandbox, 0755, true);

        // Config non ha set(): override manuale del memo in StorageFactory
        // con un LocalStorageProvider puntato al sandbox temporaneo.
        $refl = new ReflectionClass(StorageFactory::class);
        $memo = $refl->getProperty('memo');
        $memo->setAccessible(true);
        $memo->setValue(null, new LocalStorageProvider(rootDir: $this->sandbox, signingSecret: 'test'));
    }

    protected function tearDown(): void
    {
        $this->rm($this->sandbox);
        StorageFactory::reset();
    }

    private function rm(string $p): void
    {
        if (!file_exists($p)) {
            return;
        }
        if (is_dir($p)) {
            foreach (scandir($p) ?: [] as $e) {
                if ($e === '.' || $e === '..') {
                    continue;
                }
                $this->rm($p . '/' . $e);
            }
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }

    #[Test]
    public function hardcoded_vf_seed_has_3_affermazioni_with_alternating_answers(): void
    {
        $items = TemplateDefaults::hardcodedItems('VF');
        $this->assertCount(3, $items, 'VF seed → 3 affermazioni');
        $this->assertSame('V', $items[0]['answer']);
        $this->assertSame('F', $items[1]['answer']);
        $this->assertSame('V', $items[2]['answer']);
        foreach ($items as $it) {
            $this->assertArrayHasKey('id', $it);
            $this->assertNotEmpty($it['id']);
            $this->assertArrayHasKey('question', $it);
            $this->assertArrayHasKey('justification', $it);
        }
    }

    #[Test]
    public function hardcoded_rm_seed_has_1_item_with_4_options_1_correct(): void
    {
        $items = TemplateDefaults::hardcodedItems('RM');
        $this->assertCount(1, $items);
        $this->assertArrayHasKey('options', $items[0]);
        $this->assertCount(4, $items[0]['options']);
        $correctCount = 0;
        foreach ($items[0]['options'] as $op) {
            if (!empty($op['correct'])) {
                $correctCount++;
            }
        }
        $this->assertSame(1, $correctCount, 'RM seed → esattamente 1 opzione corretta');
    }

    #[Test]
    public function hardcoded_collect_seed_has_1_item_with_solution(): void
    {
        $items = TemplateDefaults::hardcodedItems('Collect');
        $this->assertCount(1, $items);
        $this->assertArrayHasKey('solution', $items[0]);
        $this->assertArrayHasKey('question', $items[0]);
    }

    #[Test]
    public function normalize_type_maps_type_prefix_to_family(): void
    {
        $this->assertSame('VF',      TemplateDefaults::normalizeType('type_VF'));
        $this->assertSame('VF',      TemplateDefaults::normalizeType('type_VF-1'));
        $this->assertSame('VF',      TemplateDefaults::normalizeType('VF'));
        $this->assertSame('RM',      TemplateDefaults::normalizeType('type_RMulti'));
        $this->assertSame('RM',      TemplateDefaults::normalizeType('type_RMulti-6'));
        $this->assertSame('RM',      TemplateDefaults::normalizeType('RM'));
        $this->assertSame('Collect', TemplateDefaults::normalizeType('type_Collect'));
        $this->assertSame('Collect', TemplateDefaults::normalizeType('type_Collect-1'));
        $this->assertSame('Collect', TemplateDefaults::normalizeType('Foo'));
        $this->assertSame('Collect', TemplateDefaults::normalizeType(''));
    }

    #[Test]
    public function stored_template_is_readable_via_storage_key(): void
    {
        $iid = 106;
        $tid = 77;
        $key = "institutes/$iid/private/$tid/templates.json";
        $payload = [
            'VF' => [
                'intro' => 'Intro custom',
                'items' => [
                    ['question' => 'Aff custom', 'answer' => 'V', 'justification' => 'g1'],
                ],
            ],
            'Collect' => [
                'intro' => 'Risolvi custom',
                'items' => [
                    ['question' => 'Q1', 'solution' => 'S1'],
                ],
            ],
        ];
        StorageFactory::default()->put($key, (string)json_encode($payload));

        $raw = (string)StorageFactory::default()->get($key);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Intro custom', $decoded['VF']['intro']);
        $this->assertSame('Aff custom', $decoded['VF']['items'][0]['question']);
        $this->assertSame('S1', $decoded['Collect']['items'][0]['solution']);
    }

    #[Test]
    public function read_raw_returns_null_when_file_absent(): void
    {
        // readRaw richiede firstInstituteId via DB, che nel test senza DB
        // ritorna 0 → readRaw ritorna null (fallback seed).
        $this->assertNull(TemplateDefaults::readRaw(99999), 'senza DB + file assente → null');
    }

    #[Test]
    public function hardcoded_fallback_returns_items_when_no_template(): void
    {
        // itemsForType(type, tid=0) → skip loadTeacherTemplate (tid<=0)
        // → cade su hardcodedItems.
        $items = TemplateDefaults::itemsForType('type_VF', 0);
        $this->assertCount(3, $items, 'fallback VF items');
        $this->assertSame('V', $items[0]['answer']);
    }

    #[Test]
    public function default_intro_for_type_hardcoded_variants(): void
    {
        $this->assertStringContainsString('Vero o Falso', TemplateDefaults::introForType('type_VF', 0));
        $this->assertStringContainsString('crociando',    TemplateDefaults::introForType('type_RMulti', 0));
        $this->assertStringContainsString('Risolvi',      TemplateDefaults::introForType('type_Collect', 0));
    }

    #[Test]
    public function default_title_for_type_hardcoded_variants(): void
    {
        $this->assertSame('VoF d',     TemplateDefaults::titleForType('type_VF', 0));
        $this->assertSame('RM',        TemplateDefaults::titleForType('type_RMulti', 0));
        $this->assertSame('Equazioni', TemplateDefaults::titleForType('type_Collect', 0));
    }

    #[Test]
    public function seed_default_has_vf_rm_collect_with_items(): void
    {
        $seed = TemplateDefaults::seedDefault();
        $this->assertArrayHasKey('VF', $seed);
        $this->assertArrayHasKey('RM', $seed);
        $this->assertArrayHasKey('Collect', $seed);
        $this->assertCount(3, $seed['VF']['items']);
        $this->assertCount(4, $seed['RM']['items'][0]['options']);
    }
}
