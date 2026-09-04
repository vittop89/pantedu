<?php

namespace App\Support\Storage;

use RuntimeException;

/**
 * Stub non attivo per S3-compatibile (AWS S3, Cloudflare R2, MinIO, ...).
 *
 * Implementazione reale richiederà:
 *   - composer require aws/aws-sdk-php  (oppure klimov-paul/r2 o simili)
 *   - env: STORAGE_S3_ENDPOINT, STORAGE_S3_BUCKET, STORAGE_S3_KEY, STORAGE_S3_SECRET, STORAGE_S3_REGION
 *
 * Il passaggio a questo provider NON richiede modifiche ai controller/
 * business logic: basta cambiare `storage.default_provider` in config.
 *
 * Trigger di migrazione (vedi spec §1.4):
 *   - DB MySQL oltre 70-80% quota
 *   - Backup/restore non più affidabile su hosting locale
 *   - Necessità di versioning avanzato / replica geografica
 */
final class S3CompatibleStorageProvider implements StorageProvider
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $bucket,
        private readonly string $region,
        private readonly string $accessKey,
        private readonly string $secret,
    ) {}

    public function name(): string { return 's3'; }

    public function put(string $key, string $contents, string $mime = 'application/octet-stream'): PutResult
    {
        throw new RuntimeException('s3_provider_not_implemented');
    }

    public function get(string $key): string
    {
        throw new RuntimeException('s3_provider_not_implemented');
    }

    public function delete(string $key): bool
    {
        throw new RuntimeException('s3_provider_not_implemented');
    }

    public function exists(string $key): bool
    {
        return false;
    }

    public function signedUrl(string $key, int $ttlSeconds = 300): string
    {
        throw new RuntimeException('s3_provider_not_implemented');
    }

    public function listPrefix(string $prefix, int $limit = 1000): array
    {
        return [];
    }
}
