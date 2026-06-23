<?php

namespace Tests\Unit\Contract;

use App\Repositories\TeacherContentRepository;
use App\Services\Contract\ContractNotFoundException;
use App\Services\Contract\ContractRepository;
use App\Services\Contract\ContractVersionMismatchException;
use App\Services\Contract\ContractItemNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test per `ContractRepository` con stub DB (TeacherContentRepository)
 * e storage in-memory. Nessuna connessione MySQL/file-system richiesta.
 */
final class ContractRepositoryTest extends TestCase
{
    private InMemoryStorageProvider $storage;

    protected function setUp(): void
    {
        $this->storage = new InMemoryStorageProvider();
    }

    /** Crea un repo con una row DB stubbata + contract JSON in storage. */
    private function makeRepo(int $contentId, int $teacherId, array $contract, string $storageKey = 'c/test.json'): ContractRepository
    {
        $this->storage->put($storageKey, (string)json_encode($contract));
        // Stub TeacherContentRepository: find() ritorna la row con contract_key.
        // update() memorizza in-memory il metadata per l'assert sulle stats.
        $stub = new class($contentId, $teacherId, $storageKey) extends TeacherContentRepository {
            public array $metadata;
            public function __construct(
                private int $stubId,
                private int $stubTeacher,
                private string $stubKey,
            ) {
                $this->metadata = ['contract_key' => $stubKey];
            }
            public function find(int $id): ?array
            {
                if ($id !== $this->stubId) return null;
                return [
                    'id' => $this->stubId,
                    'teacher_id' => $this->stubTeacher,
                    'metadata_json' => (string)json_encode($this->metadata),
                ];
            }
            public function update(int $id, int $teacherId, array $data): bool
            {
                if ($id !== $this->stubId || $teacherId !== $this->stubTeacher) return false;
                if (isset($data['metadata'])) $this->metadata = $data['metadata'];
                return true;
            }
        };
        return new ContractRepository($stub, $this->storage);
    }

    #[Test]
    public function load_returns_null_for_missing_content(): void
    {
        $repo = $this->makeRepo(1, 77, ['version' => 0]);
        $this->assertNull($repo->load(9999));
    }

    #[Test]
    public function load_returns_aggregate_for_existing_content(): void
    {
        $contract = ['version' => 2, 'title' => 'X', 'groups' => []];
        $repo = $this->makeRepo(7, 77, $contract);
        $agg = $repo->load(7);
        $this->assertNotNull($agg);
        $this->assertSame(7, $agg->contentId);
        $this->assertSame(2, $agg->version());
        $this->assertSame('X', $agg->title());
    }

    #[Test]
    public function load_for_teacher_enforces_ownership(): void
    {
        $repo = $this->makeRepo(7, 77, ['version' => 0]);
        $this->assertNull($repo->loadForTeacher(7, 42));  // wrong teacher
        $this->assertNotNull($repo->loadForTeacher(7, 77)); // owner
    }

    #[Test]
    public function save_persists_to_storage(): void
    {
        $repo = $this->makeRepo(7, 77, ['version' => 1, 'title' => 'old']);
        $agg = $repo->load(7);
        $agg->patchMeta(['source_citation' => 'NEW']);
        $agg->bumpVersion();
        $saved = $repo->save($agg);
        $this->assertSame(2, $saved->version());
        // Verifica che sia persistito raw
        $raw = json_decode($this->storage->raw('c/test.json'), true);
        $this->assertSame(2, $raw['version']);
        $this->assertSame('NEW', $raw['meta']['source_citation']);
    }

    #[Test]
    public function patch_item_atomic(): void
    {
        $contract = [
            'version' => 0,
            'groups' => [[
                'id' => 'g1',
                'items' => [['id' => '101', 'origin' => 'old']],
            ]],
        ];
        $repo = $this->makeRepo(7, 77, $contract);
        $agg = $repo->patchItem(7, 77, '101', ['origin' => 'new_src']);
        $this->assertSame(1, $agg->version()); // bump automatico
        $raw = json_decode($this->storage->raw('c/test.json'), true);
        $this->assertSame('new_src', $raw['groups'][0]['items'][0]['origin']);
    }

    #[Test]
    public function patch_item_unauthorized_teacher_throws(): void
    {
        $contract = ['version' => 0, 'groups' => [['id' => 'g1', 'items' => [['id' => '1']]]]];
        $repo = $this->makeRepo(7, 77, $contract);
        $this->expectException(ContractNotFoundException::class);
        $repo->patchItem(7, 999, '1', ['origin' => 'x']);
    }

    #[Test]
    public function patch_item_unknown_item_throws(): void
    {
        $contract = ['version' => 0, 'groups' => [['id' => 'g1', 'items' => [['id' => '1']]]]];
        $repo = $this->makeRepo(7, 77, $contract);
        $this->expectException(ContractItemNotFoundException::class);
        $repo->patchItem(7, 77, 'ghost', ['x' => 1]);
    }

    #[Test]
    public function save_with_matching_expected_version_succeeds(): void
    {
        $repo = $this->makeRepo(7, 77, ['version' => 5]);
        $agg = $repo->load(7);
        $agg->bumpVersion();
        $saved = $repo->save($agg, expectedVersion: 5);
        $this->assertSame(6, $saved->version());
    }

    #[Test]
    public function save_with_stale_version_throws_conflict(): void
    {
        $repo = $this->makeRepo(7, 77, ['version' => 5]);
        $agg = $repo->load(7);
        $agg->bumpVersion();
        $this->expectException(ContractVersionMismatchException::class);
        // expected=2 ma storage ha version=5 → conflitto
        $repo->save($agg, expectedVersion: 2);
    }

    #[Test]
    public function patch_item_with_stale_version_throws(): void
    {
        $contract = ['version' => 3, 'groups' => [['id' => 'g1', 'items' => [['id' => '1']]]]];
        $repo = $this->makeRepo(7, 77, $contract);
        $this->expectException(ContractVersionMismatchException::class);
        $repo->patchItem(7, 77, '1', ['origin' => 'x'], expectedVersion: 0);
    }

    #[Test]
    public function delete_item_removes_and_bumps_version(): void
    {
        $contract = [
            'version' => 0,
            'groups' => [[
                'id' => 'g1',
                'items' => [['id' => '1'], ['id' => '2'], ['id' => '3']],
            ]],
        ];
        $repo = $this->makeRepo(7, 77, $contract);
        $agg = $repo->deleteItem(7, 77, '2');
        $this->assertSame(1, $agg->version());
        $raw = json_decode($this->storage->raw('c/test.json'), true);
        $this->assertCount(2, $raw['groups'][0]['items']);
        $ids = array_map(fn($i) => $i['id'], $raw['groups'][0]['items']);
        $this->assertSame(['1', '3'], $ids);
    }

    #[Test]
    public function move_item_reorders_and_persists(): void
    {
        $contract = [
            'version' => 0,
            'groups' => [[
                'id' => 'g1',
                'items' => [['id' => 'a'], ['id' => 'b'], ['id' => 'c']],
            ]],
        ];
        $repo = $this->makeRepo(7, 77, $contract);
        $repo->moveItem(7, 77, 'c', 0);
        $raw = json_decode($this->storage->raw('c/test.json'), true);
        $ids = array_map(fn($i) => $i['id'], $raw['groups'][0]['items']);
        $this->assertSame(['c', 'a', 'b'], $ids);
    }

    #[Test]
    public function roundtrip_preserves_unknown_fields(): void
    {
        // I campi non noti al repository non devono essere persi nel roundtrip.
        $contract = [
            'version' => 0,
            'custom_field' => ['opaque' => 'value'],
            'groups' => [['id' => 'g1', 'items' => [['id' => '1', '$extra' => 42]]]],
        ];
        $repo = $this->makeRepo(7, 77, $contract);
        $repo->patchItem(7, 77, '1', ['color' => 'red']);
        $raw = json_decode($this->storage->raw('c/test.json'), true);
        $this->assertSame('value', $raw['custom_field']['opaque']);
        $this->assertSame(42, $raw['groups'][0]['items'][0]['$extra']);
        $this->assertSame('red', $raw['groups'][0]['items'][0]['color']);
    }

    #[Test]
    public function put_with_retry_recovers_from_transient_failure(): void
    {
        $flaky = new class extends InMemoryStorageProvider {
            public int $failsLeft = 0;  // seed phase: no fail
            public function put(string $key, string $contents, string $mime = 'application/octet-stream'): \App\Support\Storage\PutResult
            {
                if ($this->failsLeft-- > 0) throw new \RuntimeException('transient');
                return parent::put($key, $contents, $mime);
            }
        };
        $flaky->put('c/test.json', (string)json_encode(['version' => 0]));
        $flaky->failsLeft = 2; // ora le prossime 2 put falliscono, la 3ª va
        $stub = new class(5, 77, 'c/test.json') extends TeacherContentRepository {
            public function __construct(private int $sid, private int $stid, private string $sk) {}
            public function find(int $id): ?array
            {
                return $id === $this->sid ? [
                    'id' => $this->sid, 'teacher_id' => $this->stid,
                    'metadata_json' => (string)json_encode(['contract_key' => $this->sk]),
                ] : null;
            }
            public function update(int $id, int $teacherId, array $data): bool { return true; }
        };
        $repo = new ContractRepository($stub, $flaky);
        $agg = $repo->load(5);
        $repo->save($agg);
        $this->assertNotNull($flaky->raw('c/test.json'));
    }

    #[Test]
    public function put_with_retry_gives_up_after_max_attempts(): void
    {
        $alwaysFail = new class extends InMemoryStorageProvider {
            public function put(string $key, string $contents, string $mime = 'application/octet-stream'): \App\Support\Storage\PutResult
            {
                throw new \RuntimeException('permanent');
            }
        };
        // Seed via parent pre-construct trick: usa un InMemory normale per seed
        $seed = new InMemoryStorageProvider();
        $seed->put('c/test.json', (string)json_encode(['version' => 0]));
        // Copia il raw nel flaky
        // Simpler: skip seed, load ritornerà null. Test solo che save throw.
        $stub = new class extends TeacherContentRepository {
            public function __construct() {}
            public function find(int $id): ?array { return null; }
        };
        $agg = new \App\Services\Contract\ContractAggregate(
            9, 'c/nope.json', ['version' => 1], ['id' => 9, 'teacher_id' => 77, 'metadata_json' => '{}']
        );
        $repo = new ContractRepository($stub, $alwaysFail);
        $this->expectException(\RuntimeException::class);
        $repo->save($agg);
    }

    #[Test]
    public function save_syncs_stats_to_db_metadata(): void
    {
        $contract = [
            'version' => 0,
            'groups' => [
                ['id' => 'g1', 'type' => 'VF', 'items' => [
                    ['id' => '1', 'origin' => 'src_a', 'difficulty' => 2],
                    ['id' => '2', 'origin' => 'src_b', 'difficulty' => 4],
                ]],
                ['id' => 'g2', 'type' => 'RM', 'items' => [['id' => '3']]],
            ],
        ];
        // Serve accesso al stub per leggere la metadata post-update
        $storageKey = 'c/test.json';
        $this->storage->put($storageKey, (string)json_encode($contract));
        $stub = new class(7, 77, $storageKey) extends TeacherContentRepository {
            public array $metadata;
            public function __construct(
                private int $stubId, private int $stubTeacher, private string $stubKey,
            ) { $this->metadata = ['contract_key' => $stubKey]; }
            public function find(int $id): ?array
            {
                if ($id !== $this->stubId) return null;
                return [
                    'id' => $this->stubId,
                    'teacher_id' => $this->stubTeacher,
                    'metadata_json' => (string)json_encode($this->metadata),
                ];
            }
            public function update(int $id, int $teacherId, array $data): bool
            {
                if ($id !== $this->stubId || $teacherId !== $this->stubTeacher) return false;
                if (isset($data['metadata'])) $this->metadata = $data['metadata'];
                return true;
            }
        };
        $repo = new ContractRepository($stub, $this->storage);

        $agg = $repo->load(7);
        $repo->save($agg);

        // Dopo save: stub->metadata deve contenere `stats` denormalizzate
        $stats = $stub->metadata['stats'] ?? null;
        $this->assertIsArray($stats);
        $this->assertSame(3, $stats['item_count']);
        $this->assertSame(2, $stats['group_count']);
        $this->assertSame(['VF' => 1, 'RM' => 1], $stats['group_types']);
        $this->assertTrue($stats['has_vf']);
        $this->assertTrue($stats['has_rm']);
        $this->assertSame(4, $stats['difficulty_max']);
        sort($stats['source_codes']);
        $this->assertSame(['src_a', 'src_b'], $stats['source_codes']);
        // Il contract_key originale deve sopravvivere al merge
        $this->assertSame($storageKey, $stub->metadata['contract_key']);
    }
}
