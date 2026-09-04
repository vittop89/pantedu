<?php

namespace App\Support\Storage;

/**
 * Phase 14 — Storage provider abstraction.
 *
 * Contratto minimo per storage oggetti (materiali docenti, allegati, etc).
 * Implementazioni: LocalStorageProvider (filesystem hosting legacy),
 * S3CompatibleStorageProvider (stub pronto per cloud).
 *
 * Key scheme:
 *   institutes/{institute_id}/private/{teacher_id}/{category}/{resource_id}/{version}/{filename}
 *   institutes/{institute_id}/pool/{category}/{resource_id}/{version}/{filename}
 *
 * Metadati (checksum, size, mime, visibility) persistono in MySQL
 * `storage_objects` — indipendenti dal provider.
 */
interface StorageProvider
{
    public function put(string $key, string $contents, string $mime = 'application/octet-stream'): PutResult;

    /** @throws \RuntimeException se non trovato. */
    public function get(string $key): string;

    public function delete(string $key): bool;

    public function exists(string $key): bool;

    /**
     * URL breve-scadente per accesso diretto. LocalStorageProvider può
     * tornare una route interna proxy; S3 userà presigned URL.
     *
     * @param int $ttlSeconds time-to-live consigliato
     */
    public function signedUrl(string $key, int $ttlSeconds = 300): string;

    /**
     * Elenco chiavi sotto un prefisso (non ricorsivo tra virgolette:
     * la key scheme è a livelli, il provider può restituire tutti i
     * descendant).
     *
     * @return list<string>
     */
    public function listPrefix(string $prefix, int $limit = 1000): array;

    /** Nome identificativo del provider (es. "local", "s3"). */
    public function name(): string;
}
