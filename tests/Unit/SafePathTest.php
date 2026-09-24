<?php

namespace Tests\Unit;

use App\Support\SafePath;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SafePathTest extends TestCase
{
    private string $root;
    private string $otherRoot;

    protected function setUp(): void
    {
        $this->root      = sys_get_temp_dir() . '/pantedu_safepath_' . uniqid();
        $this->otherRoot = sys_get_temp_dir() . '/pantedu_other_'    . uniqid();
        mkdir($this->root,      0755, true);
        mkdir($this->otherRoot, 0755, true);
        file_put_contents($this->root . '/file.txt', 'x');
        mkdir($this->root . '/sub', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/file.txt');
        @rmdir($this->root . '/sub');
        @rmdir($this->root);
        @rmdir($this->otherRoot);
    }

    #[Test]
    public function resolves_relative_into_root(): void
    {
        $p = SafePath::resolve('file.txt', [$this->root], mustExist: true);
        $this->assertStringEndsWith('/file.txt', str_replace('\\', '/', $p));
    }

    #[Test]
    public function rejects_parent_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        SafePath::resolve('../outside.txt', [$this->root]);
    }

    #[Test]
    public function rejects_absolute_path_outside_roots(): void
    {
        $this->expectException(RuntimeException::class);
        SafePath::resolve($this->otherRoot . '/x.txt', [$this->root]);
    }

    #[Test]
    public function allows_absolute_inside_root(): void
    {
        $real = SafePath::resolve($this->root . '/file.txt', [$this->root], mustExist: true);
        $this->assertStringContainsString(basename($this->root), $real);
    }

    #[Test]
    public function rejects_null_byte(): void
    {
        $this->expectException(RuntimeException::class);
        SafePath::resolve("file\x00.txt", [$this->root]);
    }

    #[Test]
    public function rejects_empty_path(): void
    {
        $this->expectException(RuntimeException::class);
        SafePath::resolve('', [$this->root]);
    }

    #[Test]
    public function allows_nonexistent_file_when_parent_inside(): void
    {
        $p = SafePath::resolve('new.txt', [$this->root], mustExist: false);
        $this->assertStringEndsWith('/new.txt', str_replace('\\', '/', $p));
    }

    #[Test]
    public function extension_whitelist(): void
    {
        $this->assertTrue(SafePath::extensionAllowed('foo.tex', ['tex']));
        $this->assertFalse(SafePath::extensionAllowed('foo.php', ['tex']));
        $this->assertTrue(SafePath::extensionAllowed('FOO.PNG', ['png']));
    }
}
