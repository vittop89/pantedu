<?php

namespace App\Services;

use App\Core\Config;
use App\Support\SafePath;
use RuntimeException;

/**
 * Filesystem operations hardened with path + extension + size checks.
 * Callers identify the destination via (rootLabel, relative) pairs.
 * Root labels come from app/Config/filesystem.php.
 */
final class FileService
{
    /** @var array<string,string> label => absolute path */
    private array $roots;
    /** @var array<string,list<string>> */
    private array $allowedExtensions;
    /** @var array<string,int> */
    private array $maxSizes;

    public function __construct(?array $config = null)
    {
        $cfg                      = $config ?? (Config::get('filesystem') ?? []);
        $this->roots              = $cfg['roots']              ?? [];
        $this->allowedExtensions  = $cfg['allowed_extensions'] ?? [];
        $this->maxSizes           = $cfg['max_sizes']          ?? [];
    }

    /** Saves content at $rootLabel/$relative. Returns absolute path. */
    public function save(string $rootLabel, string $relative, string $content, string $kind = 'any'): string
    {
        $target = $this->resolve($rootLabel, $relative, mustExist: false);
        $this->enforceExtension($target, $kind);
        $this->enforceSize($content, $kind);

        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('cannot_create_directory');
        }
        if (file_put_contents($target, $content, LOCK_EX) === false) {
            throw new RuntimeException('write_failed');
        }
        return $target;
    }

    public function delete(string $rootLabel, string $relative): bool
    {
        $target = $this->resolve($rootLabel, $relative, mustExist: true);
        if (is_dir($target)) {
            return $this->removeTree($target);
        }
        return @unlink($target);
    }

    public function deleteFolder(string $rootLabel, string $relative): bool
    {
        $target = $this->resolve($rootLabel, $relative, mustExist: true);
        if (!is_dir($target)) {
            throw new RuntimeException('not_a_directory');
        }
        return $this->removeTree($target);
    }

    public function clearRootContents(string $rootLabel): int
    {
        $root = $this->roots[$rootLabel] ?? null;
        if (!$root || !is_dir($root)) {
            return 0;
        }
        $count = 0;
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if ($this->removeTree($path)) {
                    $count++;
                }
            } else {
                if (@unlink($path)) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @return list<string> */
    /**
     * Phase 18 — filesystem listing puro (no fallback legacy DB).
     * I contenuti legacy (eser/verifiche/lab/mappe/...) ora non sono
     * più root autorizzati; questa funzione serve solo per temp/tex_pdf/
     * img/log_data/storage_temp.
     */
    public function listDirectory(string $rootLabel, string $relative = '', ?string $extension = null): array
    {
        $target = $this->resolve($rootLabel, $relative, mustExist: true);
        $out = [];
        if (is_dir($target)) {
            foreach (scandir($target) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (
                    $extension !== null
                    && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) !== strtolower($extension)
                ) {
                    continue;
                }
                $out[] = $entry;
            }
        }
        sort($out);
        return $out;
    }

    public function read(string $rootLabel, string $relative, string $kind = 'any'): string
    {
        $target = $this->resolve($rootLabel, $relative, mustExist: true);
        $this->enforceExtension($target, $kind);
        $content = file_get_contents($target);
        if ($content === false) {
            throw new RuntimeException('read_failed');
        }
        return $content;
    }

    /**
     * Inverse of save's root-relative addressing — useful when legacy
     * callers supply an absolute-ish path and we need to figure out which
     * root it belongs to.
     */
    public function rootForAbsolute(string $absolute): ?string
    {
        foreach ($this->roots as $label => $root) {
            if (SafePath::isInside($absolute, $root)) {
                return $label;
            }
        }
        return null;
    }

    /** @return array<string,string> */
    public function roots(): array
    {
        return $this->roots;
    }

    // ───────────── internals ─────────────

    private function resolve(string $rootLabel, string $relative, bool $mustExist): string
    {
        if (!isset($this->roots[$rootLabel])) {
            throw new RuntimeException("unknown_root:$rootLabel");
        }
        $root  = $this->roots[$rootLabel];
        $input = ltrim(str_replace('\\', '/', $relative), '/');
        $full  = rtrim($root, '/\\') . ($input === '' ? '' : '/' . $input);

        return SafePath::resolve($full, [$root], mustExist: $mustExist);
    }

    private function enforceExtension(string $path, string $kind): void
    {
        $allowed = $this->allowedExtensions[$kind] ?? null;
        if ($allowed === null) {
            return;
        }
        if (!SafePath::extensionAllowed($path, $allowed)) {
            throw new RuntimeException('extension_not_allowed');
        }
    }

    private function enforceSize(string $content, string $kind): void
    {
        $max = $this->maxSizes[$kind] ?? null;
        if ($max !== null && strlen($content) > $max) {
            throw new RuntimeException('content_too_large');
        }
    }

    private function removeTree(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                if (!$this->removeTree($path)) {
                    return false;
                }
            } else {
                if (!@unlink($path)) {
                    return false;
                }
            }
        }
        return @rmdir($dir);
    }
}
