<?php

namespace Tests\Unit;

use App\Services\LogTailer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LogTailerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/pantedu_tail_' . uniqid() . '.log';
        $lines = [];
        for ($i = 1; $i <= 100; $i++) $lines[] = "line $i";
        file_put_contents($this->file, implode("\n", $lines));
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function returns_last_n_lines(): void
    {
        $t = new LogTailer([$this->file]);
        $out = $t->tail($this->file, 5);
        $this->assertSame(['line 96','line 97','line 98','line 99','line 100'], $out);
    }

    #[Test]
    public function rejects_file_outside_whitelist(): void
    {
        $t = new LogTailer(['/not/this/path.log']);
        $this->expectException(RuntimeException::class);
        $t->tail($this->file, 5);
    }

    #[Test]
    public function returns_empty_for_missing_file(): void
    {
        $missing = sys_get_temp_dir() . '/pantedu_nope_' . uniqid() . '.log';
        $t = new LogTailer([$missing]);
        $this->assertSame([], $t->tail($missing));
    }
}
