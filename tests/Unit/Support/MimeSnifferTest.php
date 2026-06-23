<?php

namespace Tests\Unit\Support;

use App\Support\MimeSniffer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MimeSnifferTest extends TestCase
{
    #[Test]
    public function detects_png(): void
    {
        $png = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 20);
        $this->assertSame('png', MimeSniffer::detect($png));
    }

    #[Test]
    public function detects_jpeg(): void
    {
        $jpg = "\xff\xd8\xff\xe0" . str_repeat("\x00", 20);
        $this->assertSame('jpeg', MimeSniffer::detect($jpg));
    }

    #[Test]
    public function detects_gif(): void
    {
        $this->assertSame('gif', MimeSniffer::detect("GIF89a......."));
        $this->assertSame('gif', MimeSniffer::detect("GIF87a......."));
    }

    #[Test]
    public function detects_pdf(): void
    {
        $this->assertSame('pdf', MimeSniffer::detect("%PDF-1.4\nsome data"));
    }

    #[Test]
    public function detects_webp(): void
    {
        $webp = "RIFF\x00\x00\x00\x00WEBPVP8 ";
        $this->assertSame('webp', MimeSniffer::detect($webp));
    }

    #[Test]
    public function detects_svg_with_xml_declaration(): void
    {
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><circle/></svg>';
        $this->assertSame('svg', MimeSniffer::detect($svg));
    }

    #[Test]
    public function detects_svg_without_declaration(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"/>';
        $this->assertSame('svg', MimeSniffer::detect($svg));
    }

    #[Test]
    public function unknown_for_non_matching(): void
    {
        $this->assertSame('unknown', MimeSniffer::detect('hello world'));
        $this->assertSame('unknown', MimeSniffer::detect('<?php phpinfo(); ?>'));
        $this->assertSame('unknown', MimeSniffer::detect('<html><body/></html>'));
    }

    #[Test]
    public function unknown_for_tiny_input(): void
    {
        $this->assertSame('unknown', MimeSniffer::detect(''));
        $this->assertSame('unknown', MimeSniffer::detect('ab'));
    }

    #[Test]
    public function assert_allowed_passes_for_match(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $this->assertSame('svg', MimeSniffer::assertAllowed($svg, ['svg', 'png']));
    }

    #[Test]
    public function assert_allowed_throws_on_mismatch(): void
    {
        $this->expectExceptionMessageMatches('/mime_mismatch/');
        MimeSniffer::assertAllowed('<?php echo 1; ?>', ['svg', 'png']);
    }

    #[Test]
    public function assert_allowed_blocks_php_masquerading_as_svg(): void
    {
        // Classic attack: PHP code named .svg — magic bytes detect not-svg.
        $phpPayload = '<?php system($_GET["c"]); ?>';
        $this->expectException(\RuntimeException::class);
        MimeSniffer::assertAllowed($phpPayload, ['svg']);
    }

    #[Test]
    public function extension_for_maps_types(): void
    {
        $this->assertSame('jpg',  MimeSniffer::extensionFor('jpeg'));
        $this->assertSame('png',  MimeSniffer::extensionFor('png'));
        $this->assertSame('svg',  MimeSniffer::extensionFor('svg'));
        $this->assertSame('bin',  MimeSniffer::extensionFor('whatever'));
    }
}
