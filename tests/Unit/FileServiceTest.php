<?php

namespace Tests\Unit;

use App\Services\FileService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileServiceTest extends TestCase
{
    private string $sandbox;
    private FileService $svc;

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/pantedu_fs_' . uniqid();
        mkdir($this->sandbox . '/temp', 0755, true);
        mkdir($this->sandbox . '/eser', 0755, true);

        $this->svc = new FileService([
            'roots' => [
                'temp' => $this->sandbox . '/temp',
                'eser' => $this->sandbox . '/eser',
            ],
            'allowed_extensions' => [
                'tex' => ['tex'],
                'any' => ['tex','json','txt'],
            ],
            'max_sizes' => ['tex' => 1024, 'any' => 2048],
        ]);
    }

    protected function tearDown(): void
    {
        $this->rm($this->sandbox);
    }

    private function rm(string $path): void
    {
        if (!file_exists($path)) return;
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $e) {
                if ($e === '.' || $e === '..') continue;
                $this->rm($path . '/' . $e);
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    #[Test]
    public function saves_tex_inside_temp_root(): void
    {
        $path = $this->svc->save('temp', 'solution.tex', 'content', 'tex');
        $this->assertFileExists($path);
        $this->assertSame('content', file_get_contents($path));
    }

    #[Test]
    public function rejects_save_outside_roots(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->save('temp', '../evil.tex', 'x', 'tex');
    }

    #[Test]
    public function rejects_unknown_root(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->save('nope', 'x.tex', 'x', 'tex');
    }

    #[Test]
    public function rejects_disallowed_extension(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->save('temp', 'shell.php', '<?php', 'tex');
    }

    #[Test]
    public function rejects_oversized_content(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->save('temp', 'big.tex', str_repeat('a', 2000), 'tex');
    }

    #[Test]
    public function deletes_file(): void
    {
        file_put_contents($this->sandbox . '/temp/victim.txt', 'x');
        $this->assertTrue($this->svc->delete('temp', 'victim.txt'));
        $this->assertFileDoesNotExist($this->sandbox . '/temp/victim.txt');
    }

    #[Test]
    public function deletes_folder_recursively(): void
    {
        mkdir($this->sandbox . '/temp/nested/deep', 0755, true);
        file_put_contents($this->sandbox . '/temp/nested/deep/a.txt', 'x');
        $this->assertTrue($this->svc->deleteFolder('temp', 'nested'));
        $this->assertDirectoryDoesNotExist($this->sandbox . '/temp/nested');
    }

    #[Test]
    public function rejects_delete_outside_roots(): void
    {
        $this->expectException(RuntimeException::class);
        $this->svc->delete('temp', '../etc/passwd');
    }

    #[Test]
    public function clears_root_contents(): void
    {
        file_put_contents($this->sandbox . '/temp/a.txt', 'x');
        file_put_contents($this->sandbox . '/temp/b.txt', 'y');
        mkdir($this->sandbox . '/temp/sub', 0755, true);
        file_put_contents($this->sandbox . '/temp/sub/c.txt', 'z');

        $removed = $this->svc->clearRootContents('temp');
        $this->assertSame(3, $removed);
        $this->assertDirectoryExists($this->sandbox . '/temp');
    }

    #[Test]
    public function list_directory_filters_by_extension(): void
    {
        file_put_contents($this->sandbox . '/eser/a.tex', 'x');
        file_put_contents($this->sandbox . '/eser/b.php', 'x');
        file_put_contents($this->sandbox . '/eser/c.tex', 'x');

        $list = $this->svc->listDirectory('eser', '', 'tex');
        $this->assertSame(['a.tex', 'c.tex'], $list);
    }

    #[Test]
    public function read_enforces_extension(): void
    {
        file_put_contents($this->sandbox . '/eser/raw.php', '<?php');
        $this->expectException(RuntimeException::class);
        $this->svc->read('eser', 'raw.php', 'tex');
    }
}
