<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Gdpr\Export;

use App\Services\Gdpr\Export\ExportSection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExportSectionTest extends TestCase
{
    #[Test]
    public function initialization_sets_metadata(): void
    {
        $s = new ExportSection('profile', 'profile', 'Profilo');
        $this->assertSame('profile', $s->key);
        $this->assertSame('profile', $s->folder);
        $this->assertSame('Profilo', $s->label);
        $this->assertSame([], $s->files);
        $this->assertSame([], $s->summary);
    }

    #[Test]
    public function addFile_prefixes_folder(): void
    {
        $s = new ExportSection('mappe', 'content/mappe');
        $s->addFile('123.drawio', '<xml/>', 'application/xml');
        $this->assertCount(1, $s->files);
        $this->assertSame('content/mappe/123.drawio', $s->files[0]->relativePath);
        $this->assertSame('application/xml', $s->files[0]->mimeType);
    }

    #[Test]
    public function addFile_strips_leading_slash(): void
    {
        $s = new ExportSection('x', 'folder');
        $s->addFile('/path/file.txt', 'data');
        $this->assertSame('folder/path/file.txt', $s->files[0]->relativePath);
    }

    #[Test]
    public function addFile_with_empty_folder(): void
    {
        $s = new ExportSection('root', '');
        $s->addFile('manifest.json', '{}');
        $this->assertSame('manifest.json', $s->files[0]->relativePath);
    }

    #[Test]
    public function addJsonFile_encodes_data(): void
    {
        $s = new ExportSection('test', 'test');
        $s->addJsonFile('data.json', ['a' => 1, 'b' => 'è']);
        $this->assertCount(1, $s->files);
        $this->assertSame('application/json', $s->files[0]->mimeType);
        // JSON_UNESCAPED_UNICODE → 'è' resta in chiaro
        $this->assertStringContainsString('"è"', $s->files[0]->content);
        $this->assertStringContainsString('"a": 1', $s->files[0]->content);
    }

    #[Test]
    public function setSummary_replaces(): void
    {
        $s = new ExportSection('x', 'x');
        $s->summary = ['old' => 1];
        $s->setSummary(['new' => 2]);
        $this->assertSame(['new' => 2], $s->summary);
    }

    #[Test]
    public function mergeSummary_preserves_existing(): void
    {
        $s = new ExportSection('x', 'x');
        $s->summary = ['a' => 1];
        $s->mergeSummary(['b' => 2]);
        $this->assertSame(['a' => 1, 'b' => 2], $s->summary);
    }

    #[Test]
    public function fileCount_and_totalSize(): void
    {
        $s = new ExportSection('x', 'x');
        $this->assertSame(0, $s->fileCount());
        $this->assertSame(0, $s->totalSize());

        $s->addFile('a.txt', '123');     // 3 bytes
        $s->addFile('b.txt', '45');      // 2 bytes
        $s->addFile('c.txt', 'hello');   // 5 bytes

        $this->assertSame(3, $s->fileCount());
        $this->assertSame(10, $s->totalSize());
    }
}
