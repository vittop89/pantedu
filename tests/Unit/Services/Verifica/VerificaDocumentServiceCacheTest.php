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
 * G22.S2 — Verifica il flusso cache PDF content-addressed:
 *   - saveTex persiste sha256(tex) sulla row
 *   - attachCachedPdfFor ritorna null su cache miss
 *   - attachCachedPdfFor riusa il PDF da una row con stesso sha (hit)
 *   - blob PDF cached con magic invalido → null (defensive)
 *   - eccezione su readPdf cached → null (graceful fallback)
 */
final class VerificaDocumentServiceCacheTest extends TestCase
{
    private function nullTx(): TransactionRunner
    {
        return new class implements TransactionRunner {
            public function run(callable $work): mixed { return $work(); }
        };
    }

    private function svc(
        VerificaDocumentRepository $docs,
        EncryptedBlobStore $store,
    ): VerificaDocumentService {
        return new VerificaDocumentService($docs, $store, $this->nullTx());
    }

    #[Test]
    public function saveTex_persists_sha256_on_create(): void
    {
        $docs = new CacheFakeRepo([
            'createReturn' => 1,
            'findReturn'   => ['id' => 1],
        ]);
        $store = new CacheFakeStore();

        $tex = '\\documentclass{article}\\begin{document}foo\\end{document}';
        $this->svc($docs, $store)->saveTex([
            'teacher_id' => 7,
            'materia'    => 'MAT',
            'title'      => 'T',
            'tex'        => $tex,
            'template_id'=> 99,
        ]);

        $this->assertCount(1, $docs->createCalls);
        $this->assertArrayHasKey('tex_sha256', $docs->createCalls[0]);
        $this->assertSame(hash('sha256', $tex), $docs->createCalls[0]['tex_sha256']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $docs->createCalls[0]['tex_sha256']);
    }

    #[Test]
    public function attachCachedPdfFor_returns_null_on_miss(): void
    {
        $docs = new CacheFakeRepo([
            'requireOwnReturn' => ['id' => 5, 'teacher_id' => 7],
            'findCachedReturn' => null, // miss
        ]);
        $store = new CacheFakeStore();

        $result = $this->svc($docs, $store)->attachCachedPdfFor(7, 5, 'foo');
        $this->assertNull($result);
        $this->assertCount(0, $store->getCalls, 'no decrypt su miss');
    }

    #[Test]
    public function attachCachedPdfFor_attaches_pdf_on_hit(): void
    {
        $cachedPdfPath = '7/cached.bin';
        $cachedRow = [
            'id'             => 99,
            'teacher_id'     => 7,
            'pdf_blob_path'  => $cachedPdfPath,
            'pdf_filename'   => 'cached.pdf',
        ];
        $pdfBytes = "%PDF-1.4\n" . str_repeat("\x00", 200);

        $docs = new CacheFakeRepo([
            'requireOwnReturn' => ['id' => 5, 'teacher_id' => 7, 'pdf_blob_path' => null],
            'findCachedReturn' => $cachedRow,
            'attachPdfReturn'  => ['id' => 5, 'pdf_blob_path' => '7/new.bin', 'pdf_size' => \strlen($pdfBytes)],
        ]);
        $store = new CacheFakeStore();
        $store->blobs[$cachedPdfPath] = $pdfBytes;

        $result = $this->svc($docs, $store)->attachCachedPdfFor(7, 5, 'foo');

        $this->assertNotNull($result);
        $this->assertSame(5, $result['id']);
        $this->assertCount(1, $store->getCalls, 'decrypt cached PDF');
        $this->assertSame($cachedPdfPath, $store->getCalls[0]['relPath']);
        $this->assertCount(1, $docs->attachPdfCalls, 'attach PDF alla row corrente');
    }

    #[Test]
    public function attachCachedPdfFor_returns_null_on_invalid_pdf_magic(): void
    {
        $docs = new CacheFakeRepo([
            'requireOwnReturn' => ['id' => 5, 'teacher_id' => 7],
            'findCachedReturn' => ['id' => 99, 'pdf_blob_path' => '7/bad.bin', 'pdf_filename' => 'x.pdf'],
        ]);
        $store = new CacheFakeStore();
        $store->blobs['7/bad.bin'] = 'NOT-A-PDF-DATA-AT-ALL';

        $result = $this->svc($docs, $store)->attachCachedPdfFor(7, 5, 'foo');
        $this->assertNull($result);
        $this->assertCount(0, $docs->attachPdfCalls, 'no attach con magic invalido');
    }

    #[Test]
    public function attachCachedPdfFor_returns_null_on_decrypt_exception(): void
    {
        $docs = new CacheFakeRepo([
            'requireOwnReturn' => ['id' => 5, 'teacher_id' => 7],
            'findCachedReturn' => ['id' => 99, 'pdf_blob_path' => '7/missing.bin'],
        ]);
        $store = new CacheFakeStore(['getThrow' => new RuntimeException('blob_not_found')]);

        $result = $this->svc($docs, $store)->attachCachedPdfFor(7, 5, 'foo');
        $this->assertNull($result, 'eccezione decrypt → graceful miss, niente throw');
    }

    #[Test]
    public function attachCachedPdfFor_returns_null_on_empty_tex_source(): void
    {
        $docs = new CacheFakeRepo([
            'requireOwnReturn' => ['id' => 5, 'teacher_id' => 7],
        ]);
        $store = new CacheFakeStore();

        $result = $this->svc($docs, $store)->attachCachedPdfFor(7, 5, '');
        $this->assertNull($result);
        $this->assertCount(0, $docs->findCachedCalls, 'tex vuoto → no query');
    }
}

/** Fake con tracking delle call cache-related. */
final class CacheFakeRepo extends VerificaDocumentRepository
{
    public array $createCalls    = [];
    public array $findCachedCalls= [];
    public array $attachPdfCalls = [];

    public function __construct(private readonly array $cfg = []) {}

    public function create(array $data): int
    {
        $this->createCalls[] = $data;
        return (int)($this->cfg['createReturn'] ?? 1);
    }

    public function find(int $id): ?array
    {
        // requireOwn (privato) chiama find(); rispondiamo con la row pre-cucinata
        // cosi' attachCachedPdfFor passa il check ownership.
        return $this->cfg['requireOwnReturn'] ?? $this->cfg['findReturn'] ?? null;
    }

    public function findCachedPdf(int $teacherId, string $sha256, int $excludeId): ?array
    {
        $this->findCachedCalls[] = compact('teacherId', 'sha256', 'excludeId');
        return $this->cfg['findCachedReturn'] ?? null;
    }

    public function attachPdf(
        int $id,
        string $blobPath,
        int $blobKv,
        int $size,
        string $filename
    ): void {
        $this->attachPdfCalls[] = compact('id', 'blobPath', 'size', 'filename');
    }
}

/** Fake blob store con dizionario in-memory. */
final class CacheFakeStore extends EncryptedBlobStore
{
    public array $blobs    = [];
    public array $getCalls = [];

    public function __construct(private readonly array $cfg = []) {}

    public function put(int $teacherId, string $plaintext, ?string $ulid = null): string
    {
        $relPath = $teacherId . '/' . ($ulid ?? 'fakeulid') . '.bin';
        $this->blobs[$relPath] = $plaintext;
        return $relPath;
    }

    public function readKv(string $relPath): int { return 1; }
    public function exists(string $relPath): bool { return isset($this->blobs[$relPath]); }
    public function delete(string $relPath): void { unset($this->blobs[$relPath]); }

    public function get(int $ownerTeacherId, string $relPath): string
    {
        $this->getCalls[] = compact('ownerTeacherId', 'relPath');
        if (isset($this->cfg['getThrow'])) throw $this->cfg['getThrow'];
        if (!isset($this->blobs[$relPath])) {
            throw new RuntimeException('blob_store_not_found');
        }
        return $this->blobs[$relPath];
    }
}
