<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Services\Gdpr\Export\ExportFile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportFileTest extends TestCase
{
    #[Test]
    public function make_computes_sha256(): void
    {
        $f = ExportFile::make('test.txt', 'hello world');
        $this->assertSame('test.txt', $f->relativePath);
        $this->assertSame('hello world', $f->content);
        $this->assertSame(
            'b94d27b9934d3e08a52e52d7da7dabfac484efe37a5380ee9088f7ace2efcde9',
            $f->sha256
        );
    }

    #[Test]
    public function make_defaults_mime_to_octet_stream(): void
    {
        $f = ExportFile::make('test.bin', 'data');
        $this->assertSame('application/octet-stream', $f->mimeType);
    }

    #[Test]
    public function make_with_explicit_mime(): void
    {
        $f = ExportFile::make('test.json', '{"a":1}', 'application/json');
        $this->assertSame('application/json', $f->mimeType);
    }

    #[Test]
    public function size_returns_byte_length(): void
    {
        $f = ExportFile::make('test.txt', 'abc');
        $this->assertSame(3, $f->size());
    }

    #[Test]
    public function empty_content_is_handled(): void
    {
        $f = ExportFile::make('empty.txt', '');
        $this->assertSame(0, $f->size());
        // sha256 of empty string è valore canonico noto
        $this->assertSame(
            'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
            $f->sha256
        );
    }

    #[Test]
    public function binary_content_with_null_bytes(): void
    {
        $binary = "\x00\x01\x02\x03\xff";
        $f = ExportFile::make('binary.bin', $binary);
        $this->assertSame(5, $f->size());
        $this->assertSame($binary, $f->content);
    }
}
