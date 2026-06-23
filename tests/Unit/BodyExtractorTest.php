<?php

namespace Tests\Unit;

use App\Support\BodyExtractor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BodyExtractorTest extends TestCase
{
    #[Test]
    public function extracts_body_from_full_document(): void
    {
        $html = "<!DOCTYPE html>\n<html><head><title>x</title></head><body><p>hello</p></body></html>";
        $this->assertSame('<p>hello</p>', BodyExtractor::extract($html));
    }

    #[Test]
    public function preserves_inner_tags_and_attributes(): void
    {
        $html = '<html><body class="x"><div id="a">A</div><script type="text/tikz">\draw (0,0);</script></body></html>';
        $out  = BodyExtractor::extract($html);
        $this->assertStringContainsString('<div id="a">A</div>', $out);
        $this->assertStringContainsString('text/tikz', $out);
    }

    #[Test]
    public function handles_body_with_attributes(): void
    {
        $html = '<html><body data-route="/x" class="full"><main>Z</main></body></html>';
        $this->assertSame('<main>Z</main>', BodyExtractor::extract($html));
    }

    #[Test]
    public function returns_fragment_unchanged_when_no_body(): void
    {
        $frag = '<section><p>frag</p></section>';
        $this->assertSame($frag, BodyExtractor::extract($frag));
    }

    #[Test]
    public function strips_head_and_doctype_when_no_body_wrap(): void
    {
        $src = "<!doctype html><html><head><title>x</title></head><div>inner</div></html>";
        $this->assertSame('<div>inner</div>', BodyExtractor::extract($src));
    }

    #[Test]
    public function extracts_assets_from_head(): void
    {
        $html = '<html><head>'
              . '<link rel="stylesheet" href="/x.css">'
              . '<script src="/y.js"></script>'
              . '<meta charset="utf-8">'
              . '</head><body><p>b</p></body></html>';
        [$body, $assets] = BodyExtractor::extractWithAssets($html);
        $this->assertSame('<p>b</p>', $body);
        $this->assertCount(2, $assets);
        $this->assertStringContainsString('x.css', $assets[0]);
        $this->assertStringContainsString('y.js',  $assets[1]);
    }
}
