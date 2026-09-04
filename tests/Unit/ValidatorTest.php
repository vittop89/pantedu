<?php

namespace Tests\Unit;

use App\Support\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ValidatorTest extends TestCase
{
    #[Test]
    public function string_trims_and_respects_bounds(): void
    {
        $v = new Validator(['x' => '  abc  ']);
        $this->assertSame('abc', $v->string('x'));
    }

    #[Test]
    public function string_required_missing_throws(): void
    {
        $v = new Validator([]);
        $this->expectException(RuntimeException::class);
        $v->string('x');
    }

    #[Test]
    public function string_optional_missing_default(): void
    {
        $v = new Validator([]);
        $this->assertSame('default', $v->string('x', required: false, default: 'default'));
    }

    #[Test]
    public function string_regex_enforced(): void
    {
        $v = new Validator(['x' => 'abc/../etc']);
        $this->expectException(RuntimeException::class);
        $v->string('x', regex: '#^[a-z]+$#');
    }

    #[Test]
    public function int_validates_range(): void
    {
        $v = new Validator(['n' => '42']);
        $this->assertSame(42, $v->int('n', min: 1, max: 100));
    }

    #[Test]
    public function int_rejects_non_numeric(): void
    {
        $v = new Validator(['n' => 'abc']);
        $this->expectException(RuntimeException::class);
        $v->int('n');
    }

    #[Test]
    public function in_enforces_whitelist(): void
    {
        $v = new Validator(['mode' => 'edit']);
        $this->assertSame('edit', $v->in('mode', ['view','edit']));
    }

    #[Test]
    public function in_rejects_unknown(): void
    {
        $v = new Validator(['mode' => 'hack']);
        $this->expectException(RuntimeException::class);
        $v->in('mode', ['view','edit']);
    }

    #[Test]
    public function filename_blocks_slashes(): void
    {
        $v = new Validator(['f' => '../evil.tex']);
        $this->expectException(RuntimeException::class);
        $v->filename('f', ['tex']);
    }

    #[Test]
    public function filename_blocks_bad_extension(): void
    {
        $v = new Validator(['f' => 'foo.php']);
        $this->expectException(RuntimeException::class);
        $v->filename('f', ['tex']);
    }

    #[Test]
    public function filename_allows_valid(): void
    {
        $v = new Validator(['f' => 'solution.tex']);
        $this->assertSame('solution.tex', $v->filename('f', ['tex']));
    }

    #[Test]
    public function webPath_accepts_valid_relative_path(): void
    {
        $v = new Validator(['p' => '/eser/sc/eser_sc1s/MAT/foo.php']);
        $this->assertSame('/eser/sc/eser_sc1s/MAT/foo.php', $v->webPath('p'));
    }

    #[Test]
    public function webPath_rejects_double_dot_traversal(): void
    {
        $v = new Validator(['p' => '/eser/../etc/passwd']);
        $this->expectException(RuntimeException::class);
        $v->webPath('p');
    }

    #[Test]
    public function webPath_rejects_null_byte(): void
    {
        $v = new Validator(['p' => "/eser/file\x00.php"]);
        $this->expectException(RuntimeException::class);
        $v->webPath('p');
    }

    #[Test]
    public function webPath_rejects_windows_absolute(): void
    {
        $v = new Validator(['p' => 'C:/Windows/system32']);
        $this->expectException(RuntimeException::class);
        $v->webPath('p');
    }

    #[Test]
    public function webPath_rejects_pipe_chars(): void
    {
        $v = new Validator(['p' => '/eser/foo|bar.php']);
        $this->expectException(RuntimeException::class);
        $v->webPath('p');
    }

    #[Test]
    public function webPath_enforces_extension_whitelist(): void
    {
        $v = new Validator(['p' => '/eser/foo.exe']);
        $this->expectException(RuntimeException::class);
        $v->webPath('p', extPattern: ['php', 'html']);
    }

    #[Test]
    public function webPath_accepts_with_valid_extension(): void
    {
        $v = new Validator(['p' => '/eser/sc/foo.html']);
        $this->assertSame('/eser/sc/foo.html', $v->webPath('p', extPattern: ['php', 'html']));
    }
}
