<?php

declare(strict_types=1);

namespace Tests\Unit\Risdoc;

use App\Services\Risdoc\ReviewFlow;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit test sui guard statici di {@see ReviewFlow}: sanitizePath +
 * validateImageUpload. Non toccano DB / sessione, sicuri in unit.
 */
final class ReviewFlowGuardsTest extends TestCase
{
    #[Test]
    public function sanitizePath_accepts_clean_relative_paths(): void
    {
        $this->assertSame('', ReviewFlow::sanitizePath(''));
        $this->assertSame('a/b/c.txt', ReviewFlow::sanitizePath('a/b/c.txt'));
        $this->assertSame('img/x.png', ReviewFlow::sanitizePath('img/x.png'));
    }

    #[Test]
    public function sanitizePath_normalizes_backslashes(): void
    {
        $this->assertSame('a/b/c.txt', ReviewFlow::sanitizePath('a\\b\\c.txt'));
    }

    #[Test]
    public function sanitizePath_rejects_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/traversal/');
        ReviewFlow::sanitizePath('../etc/passwd');
    }

    #[Test]
    public function sanitizePath_rejects_traversal_in_middle(): void
    {
        $this->expectException(RuntimeException::class);
        ReviewFlow::sanitizePath('foo/../bar');
    }

    #[Test]
    public function sanitizePath_rejects_absolute(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/absolute/');
        ReviewFlow::sanitizePath('/etc/passwd');
    }

    #[Test]
    public function sanitizePath_rejects_nul_byte(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nul_byte/');
        ReviewFlow::sanitizePath("a/b\0c");
    }

    #[Test]
    public function validateImage_accepts_real_png(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'png');
        // 1x1 transparent PNG.
        \file_put_contents($tmp, \base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));
        try {
            $mime = ReviewFlow::validateImageUpload($tmp);
            $this->assertSame('image/png', $mime);
        } finally {
            @\unlink($tmp);
        }
    }

    #[Test]
    public function validateImage_rejects_non_image(): void
    {
        $tmp = \tempnam(\sys_get_temp_dir(), 'txt');
        \file_put_contents($tmp, "<?php phpinfo();\n");
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/mime_not_allowed/');
            ReviewFlow::validateImageUpload($tmp);
        } finally {
            @\unlink($tmp);
        }
    }

    #[Test]
    public function validateImage_rejects_missing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/upload_missing/');
        ReviewFlow::validateImageUpload(\sys_get_temp_dir() . '/__does_not_exist_' . \uniqid());
    }
}
