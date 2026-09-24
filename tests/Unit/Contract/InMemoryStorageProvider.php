<?php

namespace Tests\Unit\Contract;

use App\Support\Storage\PutResult;
use App\Support\Storage\StorageProvider;

/**
 * Phase 16 — In-memory StorageProvider per unit tests. Niente filesystem,
 * niente S3. Serve per isolare i test del ContractRepository dalle
 * implementazioni reali (Local/S3).
 */
class InMemoryStorageProvider implements StorageProvider
{
    /** @var array<string,string> */
    private array $store = [];

    public function put(string $key, string $contents, string $mime = 'application/octet-stream'): PutResult
    {
        $this->store[$key] = $contents;
        return new PutResult($key, strlen($contents), hash('sha256', $contents), $mime);
    }

    public function get(string $key): string
    {
        if (!isset($this->store[$key])) {
            throw new \RuntimeException("Key not found: $key");
        }
        return $this->store[$key];
    }

    public function delete(string $key): bool
    {
        $had = isset($this->store[$key]);
        unset($this->store[$key]);
        return $had;
    }

    public function exists(string $key): bool { return isset($this->store[$key]); }
    public function signedUrl(string $key, int $ttlSeconds = 300): string { return "mem://$key"; }

    /** @return list<string> */
    public function listPrefix(string $prefix, int $limit = 1000): array
    {
        $out = [];
        foreach ($this->store as $k => $_) {
            if (str_starts_with($k, $prefix)) $out[] = $k;
            if (count($out) >= $limit) break;
        }
        return $out;
    }

    public function name(): string { return 'memory'; }

    /** Test helper: accesso raw (non nell'interface). */
    public function raw(string $key): ?string { return $this->store[$key] ?? null; }
}
