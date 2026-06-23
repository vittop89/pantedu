<?php

namespace Tests\Unit;

use App\Services\LogRotator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogRotatorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/fm_logrotate_' . \uniqid();
        \mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (\is_dir($this->tmpDir)) {
            foreach (\glob($this->tmpDir . '/*') ?: [] as $f) @\unlink($f);
            @\rmdir($this->tmpDir);
        }
    }

    #[Test]
    public function skips_files_below_threshold(): void
    {
        $f = $this->tmpDir . '/small.log';
        \file_put_contents($f, 'tiny');
        $r = new LogRotator(maxBytes: 1024);
        $this->assertFalse($r->rotateIfNeeded($f));
        $this->assertFileExists($f);
    }

    #[Test]
    public function rotates_file_above_threshold(): void
    {
        $f = $this->tmpDir . '/big.log';
        \file_put_contents($f, \str_repeat('x', 2048));
        $r = new LogRotator(maxBytes: 1024, gzip: false);
        $this->assertTrue($r->rotateIfNeeded($f));
        $this->assertFileExists($f . '.1');
        $this->assertSame(2048, \filesize($f . '.1'));
        $this->assertFileExists($f);
        $this->assertSame(0, \filesize($f));
    }

    #[Test]
    public function shifts_existing_backups(): void
    {
        $f = $this->tmpDir . '/a.log';
        \file_put_contents($f . '.1', 'old1');
        \file_put_contents($f . '.2', 'old2');
        \file_put_contents($f, \str_repeat('x', 2048));
        (new LogRotator(maxBytes: 1024, gzip: false))->rotateIfNeeded($f);
        $this->assertSame(2048, \filesize($f . '.1'));
        $this->assertSame('old1', \file_get_contents($f . '.2'));
        $this->assertSame('old2', \file_get_contents($f . '.3'));
    }

    #[Test]
    public function cleans_oldest_backup_when_exceeding_max(): void
    {
        $f = $this->tmpDir . '/a.log';
        for ($i = 1; $i <= 5; $i++) \file_put_contents($f . '.' . $i, 'n' . $i);
        \file_put_contents($f, \str_repeat('x', 2048));
        (new LogRotator(maxBytes: 1024, maxBackups: 5, gzip: false))->rotateIfNeeded($f);
        $this->assertFileDoesNotExist($f . '.6');  // oldest shifted out + cleaned
        $this->assertFileExists($f . '.1');
    }

    #[Test]
    public function rotateDirectory_counts_matches(): void
    {
        \file_put_contents($this->tmpDir . '/a.log', \str_repeat('x', 2048));
        \file_put_contents($this->tmpDir . '/b.log', 'tiny');
        $n = (new LogRotator(maxBytes: 1024, gzip: false))->rotateDirectory($this->tmpDir);
        $this->assertSame(1, $n);
    }

    #[Test]
    public function maybeRotate_throttles_via_sentinel(): void
    {
        \file_put_contents($this->tmpDir . '/big.log', \str_repeat('x', 10 * 1024 * 1024));
        \touch($this->tmpDir . '/.rotated', \time());
        $n = LogRotator::maybeRotate($this->tmpDir, 3600);
        $this->assertSame(0, $n); // throttled
    }

    #[Test]
    public function maybeRotateAll_covers_multiple_dirs(): void
    {
        $dir2 = $this->tmpDir . '_2';
        \mkdir($dir2, 0755, true);
        \file_put_contents($this->tmpDir . '/a.log', \str_repeat('x', 10 * 1024 * 1024));
        \file_put_contents($dir2 . '/b.log', \str_repeat('y', 10 * 1024 * 1024));

        $n = LogRotator::maybeRotateAll([$this->tmpDir, $dir2], 3600);
        $this->assertSame(2, $n);
        $this->assertFileExists($this->tmpDir . '/a.log.1');
        $this->assertFileExists($dir2 . '/b.log.1');

        foreach (\glob($dir2 . '/*') ?: [] as $f) @\unlink($f);
        @\rmdir($dir2);
    }

    #[Test]
    public function maybeRotateAll_throttles(): void
    {
        \file_put_contents($this->tmpDir . '/big.log', \str_repeat('x', 10 * 1024 * 1024));
        \touch($this->tmpDir . '/.rotated', \time());
        $n = LogRotator::maybeRotateAll([$this->tmpDir], 3600);
        $this->assertSame(0, $n);
    }

    #[Test]
    public function maybeRotateAll_empty_dirs_returns_zero(): void
    {
        $this->assertSame(0, LogRotator::maybeRotateAll([]));
    }
}
