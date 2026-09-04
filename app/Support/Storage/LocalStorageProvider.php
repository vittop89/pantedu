<?php

namespace App\Support\Storage;

use App\Support\SafePath;
use RuntimeException;

/**
 * Provider locale (filesystem). Fase hosting legacy iniziale.
 *
 * Key → path: tutte le chiavi sono sempre relative al rootDir e
 * normalizzate con SafePath per evitare traversal. La struttura
 * coincide con la key scheme consigliata
 * (institutes/{id}/{private|pool}/...).
 *
 * signedUrl ritorna una rotta interna proxy `/storage/signed/{token}`
 * il cui token encode {key, exp, hmac}. Il controller che serve quella
 * route (fase successiva) verificherà scadenza+HMAC prima di streammare
 * il file con `readfile`.
 */
final class LocalStorageProvider implements StorageProvider
{
    public function __construct(
        private readonly string $rootDir,
        private readonly string $signingSecret,
    ) {
        if ($this->signingSecret === '') {
            throw new RuntimeException('local_storage_missing_secret');
        }
        if (!is_dir($this->rootDir) && !mkdir($this->rootDir, 0755, true) && !is_dir($this->rootDir)) {
            throw new RuntimeException('local_storage_rootdir_create_failed');
        }
    }

    public function name(): string { return 'local'; }

    public function put(string $key, string $contents, string $mime = 'application/octet-stream'): PutResult
    {
        $abs = $this->absolute($key, mustExist: false);
        $dir = dirname($abs);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('local_storage_mkdir_failed');
        }
        $tmp = $abs . '.tmp';
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('local_storage_write_failed');
        }
        if (!rename($tmp, $abs)) {
            @unlink($tmp);
            throw new RuntimeException('local_storage_rename_failed');
        }
        return new PutResult(
            key:      $key,
            size:     strlen($contents),
            checksum: hash('sha256', $contents),
            provider: $this->name(),
        );
    }

    public function get(string $key): string
    {
        $abs = $this->absolute($key, mustExist: true);
        $raw = @file_get_contents($abs);
        if ($raw === false) throw new RuntimeException('local_storage_read_failed');
        return $raw;
    }

    public function delete(string $key): bool
    {
        $abs = $this->absolute($key, mustExist: true);
        return @unlink($abs);
    }

    public function exists(string $key): bool
    {
        try {
            return is_file($this->absolute($key, mustExist: false));
        } catch (RuntimeException) {
            return false;
        }
    }

    public function signedUrl(string $key, int $ttlSeconds = 300): string
    {
        $exp   = time() + max(60, min($ttlSeconds, 3600));
        $payload = base64_encode(json_encode(['k' => $key, 'e' => $exp], JSON_UNESCAPED_SLASHES));
        $sig   = hash_hmac('sha256', $payload, $this->signingSecret);
        return '/storage/signed?t=' . rawurlencode($payload) . '&s=' . $sig;
    }

    public function listPrefix(string $prefix, int $limit = 1000): array
    {
        $absRoot = $this->absolute($prefix, mustExist: false);
        if (!is_dir($absRoot)) return [];
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $rel = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($this->rootDir))), '/');
            $out[] = $rel;
            if (count($out) >= $limit) break;
        }
        sort($out);
        return $out;
    }

    private function absolute(string $key, bool $mustExist): string
    {
        $clean = ltrim(str_replace('\\', '/', $key), '/');
        if ($clean === '') throw new RuntimeException('local_storage_empty_key');
        $full = rtrim($this->rootDir, '/\\') . '/' . $clean;
        return SafePath::resolve($full, [$this->rootDir], mustExist: $mustExist);
    }
}
