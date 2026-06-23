<?php

namespace App\Services;

/**
 * Phase 19 — log rotation size-based.
 *
 * Ruota file di log in `storage/logs/*` quando superano `maxBytes`.
 * Mantiene max 5 backup: `{name}.log` → `{name}.log.1` → `{name}.log.2` → ...
 * `{name}.log.5` è il più vecchio e viene cancellato quando un nuovo rotate
 * spinge il 4 a 5.
 *
 * Compressione gzip opzionale su .log.2+ (se `gzopen` disponibile).
 *
 * Pattern di uso:
 *   - CLI: `php tools/log_rotate.php` (cron daily)
 *   - In-process: `LogRotator::maybeRotate()` con sentinel mtime check
 *     chiamato dal Kernel (auto-rotate if ultima rotate > 1h fa).
 */
final class LogRotator
{
    public function __construct(
        private readonly int $maxBytes = 5 * 1024 * 1024,   // 5MB
        private readonly int $maxBackups = 5,
        private readonly bool $gzip = true,
    ) {
    }

    /**
     * Rotate un singolo file se supera maxBytes.
     * @return bool true se rotated, false se skipped
     */
    public function rotateIfNeeded(string $path): bool
    {
        if (!\is_file($path)) {
            return false;
        }
        $size = @\filesize($path);
        if ($size === false || $size < $this->maxBytes) {
            return false;
        }

        // Shift: .log.5 deleted, .log.N → .log.(N+1)
        for ($i = $this->maxBackups; $i >= 1; $i--) {
            $src = $path . '.' . $i;
            $srcGz = $src . '.gz';
            $dst = $path . '.' . ($i + 1);
            if ($i === $this->maxBackups) {
                // Delete the oldest
                if (\is_file($src)) {
                    @\unlink($src);
                }
                if (\is_file($srcGz)) {
                    @\unlink($srcGz);
                }
                continue;
            }
            if (\is_file($src)) {
                @\rename($src, $dst);
            }
            if (\is_file($srcGz)) {
                @\rename($srcGz, $dst . '.gz');
            }
        }

        // Move current to .log.1 then truncate current
        @\rename($path, $path . '.1');
        @\touch($path);

        // Compress .log.2 (newly demoted) con gzip
        if ($this->gzip && $this->canGzip()) {
            $demoted = $path . '.2';
            if (\is_file($demoted)) {
                $this->gzipFile($demoted);
            }
        }
        return true;
    }

    /**
     * Rotate tutti i file in una directory che matchano pattern.
     * @return int numero di file ruotati
     */
    public function rotateDirectory(string $dir, string $pattern = '*.log'): int
    {
        if (!\is_dir($dir)) {
            return 0;
        }
        $count = 0;
        foreach (\glob($dir . '/' . $pattern) ?: [] as $f) {
            if ($this->rotateIfNeeded($f)) {
                $count++;
            }
        }
        // JSON logs (access_log.json) stesso flow
        foreach (\glob($dir . '/*.json') ?: [] as $f) {
            if ($this->rotateIfNeeded($f)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * In-process throttled rotation: rotate solo se ultima rotate > interval sec.
     * Sentinel file: `{dir}/.rotated` mtime.
     */
    public static function maybeRotate(string $dir, int $interval = 3600): int
    {
        $sentinel = $dir . '/.rotated';
        if (\is_file($sentinel) && (\time() - @\filemtime($sentinel)) < $interval) {
            return 0;
        }
        $n = (new self())->rotateDirectory($dir);
        @\touch($sentinel);
        return $n;
    }

    /**
     * Phase 20 — Rotate multiple log directories in un unico pass throttled.
     * Sentinel unico nella prima dir (default storage/logs). Estende la
     * copertura a log/errors/ (legacy) oltre a storage/logs/ (moderno).
     *
     * @param list<string> $dirs lista directory da scan
     */
    public static function maybeRotateAll(array $dirs, int $interval = 3600): int
    {
        if (!$dirs) {
            return 0;
        }
        $sentinel = $dirs[0] . '/.rotated';
        if (\is_file($sentinel) && (\time() - @\filemtime($sentinel)) < $interval) {
            return 0;
        }
        $rotator = new self();
        $total = 0;
        foreach ($dirs as $d) {
            $total += $rotator->rotateDirectory($d);
        }
        @\touch($sentinel);
        return $total;
    }

    private function canGzip(): bool
    {
        return \function_exists('gzopen');
    }

    private function gzipFile(string $path): bool
    {
        $src = @\fopen($path, 'rb');
        if ($src === false) {
            return false;
        }
        $gz = @\gzopen($path . '.gz', 'wb9');
        if ($gz === false) {
            @\fclose($src);
            return false;
        }
        while (!\feof($src)) {
            \gzwrite($gz, (string)\fread($src, 8192));
        }
        \fclose($src);
        \gzclose($gz);
        @\unlink($path);
        return true;
    }
}
