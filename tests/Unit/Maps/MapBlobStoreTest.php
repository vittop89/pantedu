<?php

declare(strict_types=1);

namespace Tests\Unit\Maps;

use App\Services\Crypto\TeacherCryptoService;
use App\Services\Maps\MapBlobStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase G2 — Unit tests MapBlobStore.
 *
 * Coperture:
 *   - Path traversal guard (preg validation su {teacher_id}/{ULID}.bin).
 *   - Errore esplicito quando KMS_MASTER non configurato.
 *
 * Round-trip put/get e' coperto da test integrazione (richiede DB +
 * teacher_keys row reale, non unit-friendly).
 */
final class MapBlobStoreTest extends TestCase
{
    public function testPutThrowsWhenKmsNotConfigured(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(''));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/kms_not_configured/');
        $store->put(77, 'plaintext');
    }

    public function testGetThrowsOnInvalidPathFormat(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_path/');
        $store->get(77, '../../etc/passwd');
    }

    public function testGetRejectsRelativeTraversal(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_path/');
        $store->get(77, '77/../78/01HZ6X2K4YQ8N3FKMNH9V0PJTC.bin');
    }

    public function testGetRejectsLowerCaseUlid(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_path/');
        // ULID e' uppercase Crockford [0-9A-Z]{26}; lowercase = invalid.
        $store->get(77, '77/01hz6x2k4yq8n3fkmnh9v0pjtc.bin');
    }

    public function testGetRejectsWrongExtension(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $this->expectException(RuntimeException::class);
        $store->get(77, '77/01HZ6X2K4YQ8N3FKMNH9V0PJTC.txt');
    }

    public function testExistsReturnsFalseForInvalidPath(): void
    {
        $store = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        // exists() chiama guardPath che throws → wrapper catch e' lasciato
        // al caller; qui validiamo che la guard scatti.
        $this->expectException(RuntimeException::class);
        $store->exists('77/bad-name.bin');
    }

    public function testValidPathAcceptsCorrectFormat(): void
    {
        $store  = new MapBlobStore(new TeacherCryptoService(bin2hex(random_bytes(32))));
        $tmpDir = sys_get_temp_dir() . '/pantedu_blob_test_' . bin2hex(random_bytes(4));
        $store  = new MapBlobStore(
            new TeacherCryptoService(bin2hex(random_bytes(32))),
            $tmpDir
        );
        // Path valido ma file non esiste → exists ritorna false (no throw).
        $this->assertFalse($store->exists('77/01HZ6X2K4YQ8N3FKMNH9V0PJTC.bin'));
    }
}
