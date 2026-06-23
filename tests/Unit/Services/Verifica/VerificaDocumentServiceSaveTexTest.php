<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Verifica;

use App\Repositories\VerificaDocumentRepository;
use App\Services\Crypto\EncryptedBlobStore;
use App\Services\Verifica\VerificaDocumentService;
use App\Support\TransactionRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * G22.S1 — Verifica che saveTex non lasci blob orfani sul filesystem
 * quando la INSERT in DB fallisce dopo il put cifrato.
 *
 * Il test isola il path "rollback DB → cleanup blob" usando:
 *   - FakeRepository: throw a comando o ritorna null da find()
 *   - FakeBlobStore: tracking calls a put/delete (no I/O reale)
 *   - NullTransactionRunner: esegue il callback senza begin/commit
 *
 * Il flusso integrato (PDO transaction concreta) e' coperto da
 * PdoTransactionRunnerTest.
 */
final class VerificaDocumentServiceSaveTexTest extends TestCase
{
    private function nullTx(): TransactionRunner
    {
        return new class implements TransactionRunner {
            public function run(callable $work): mixed
            {
                return $work();
            }
        };
    }

    private function svc(
        VerificaDocumentRepository $docs,
        EncryptedBlobStore $store,
    ): VerificaDocumentService {
        return new VerificaDocumentService($docs, $store, $this->nullTx());
    }

    private function payload(): array
    {
        return [
            'teacher_id'   => 42,
            'materia'      => 'MAT',
            'title'        => 'Test verifica',
            'tex'          => '\\documentclass{article}\\begin{document}foo\\end{document}',
            'fm_db_section'=> 'VERIFICHE',
        ];
    }

    #[Test]
    public function saveTex_success_persists_blob_and_row(): void
    {
        $docs = new FakeDocsRepo([
            'createReturn' => 7,
            'findReturn'   => ['id' => 7, 'tex_blob_path' => '42/abc.bin'],
        ]);
        $store = new FakeBlobStore();

        $doc = $this->svc($docs, $store)->saveTex($this->payload());

        $this->assertSame(7, $doc['id']);
        $this->assertCount(1, $store->putCalls, 'put deve essere chiamato 1x');
        $this->assertCount(0, $store->deleteCalls, 'no delete in success path');
        $this->assertCount(1, $docs->createCalls);
    }

    #[Test]
    public function saveTex_rollback_deletes_orphan_blob_on_create_failure(): void
    {
        $docs = new FakeDocsRepo([
            'createThrow'  => new RuntimeException('db_constraint'),
        ]);
        $store = new FakeBlobStore();

        $caught = null;
        try {
            $this->svc($docs, $store)->saveTex($this->payload());
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('db_constraint', $caught->getMessage());
        $this->assertCount(1, $store->putCalls, 'put deve essere stato chiamato');
        $this->assertCount(1, $store->deleteCalls, 'cleanup blob deve essere triggerato');
        $this->assertSame($store->putCalls[0]['relPath'], $store->deleteCalls[0]);
    }

    #[Test]
    public function saveTex_rollback_when_find_returns_null(): void
    {
        // create() ok, ma find() ritorna null → service throw verifica_save_failed
        // e blob deve essere ripulito.
        $docs = new FakeDocsRepo([
            'createReturn' => 11,
            'findReturn'   => null,
        ]);
        $store = new FakeBlobStore();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verifica_save_failed');

        try {
            $this->svc($docs, $store)->saveTex($this->payload());
        } finally {
            $this->assertCount(1, $store->putCalls);
            $this->assertCount(1, $store->deleteCalls);
        }
    }
}

/**
 * Fake non-final per testing. Estende il repository reale ma sostituisce
 * tutti i metodi DB-touching con behavior configurabile.
 */
final class FakeDocsRepo extends VerificaDocumentRepository
{
    public array $createCalls = [];
    public array $findCalls   = [];
    public array $deleteCalls = [];

    /** @param array{createReturn?:int, createThrow?:\Throwable, findReturn?:?array} $cfg */
    public function __construct(private readonly array $cfg = [])
    {
        // Bypass parent constructor (nessuno stato necessario).
    }

    public function create(array $data): int
    {
        $this->createCalls[] = $data;
        if (isset($this->cfg['createThrow'])) throw $this->cfg['createThrow'];
        return (int)($this->cfg['createReturn'] ?? 1);
    }

    public function find(int $id): ?array
    {
        $this->findCalls[] = $id;
        return $this->cfg['findReturn'] ?? null;
    }

    public function delete(int $id): void
    {
        $this->deleteCalls[] = $id;
    }
}

final class FakeBlobStore extends EncryptedBlobStore
{
    public array $putCalls    = [];
    public array $deleteCalls = [];
    public array $existing    = [];

    public function __construct()
    {
        // Bypass parent: no KMS, no filesystem.
    }

    public function put(int $teacherId, string $plaintext, ?string $ulid = null): string
    {
        $relPath = $teacherId . '/' . ($ulid ?? 'fakeulid') . '.bin';
        $this->putCalls[] = ['teacherId' => $teacherId, 'relPath' => $relPath, 'size' => \strlen($plaintext)];
        $this->existing[$relPath] = true;
        return $relPath;
    }

    public function readKv(string $relPath): int
    {
        return 1;
    }

    public function exists(string $relPath): bool
    {
        return !empty($this->existing[$relPath]);
    }

    public function delete(string $relPath): void
    {
        $this->deleteCalls[] = $relPath;
        unset($this->existing[$relPath]);
    }

    public function get(int $ownerTeacherId, string $relPath): string
    {
        return '';
    }
}
