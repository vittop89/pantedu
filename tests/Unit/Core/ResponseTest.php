<?php

namespace Tests\Unit\Core;

use App\Core\Response;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_SERVER['HTTP_IF_NONE_MATCH']);
    }

    #[Test]
    public function with_etag_sets_header_and_cache_control(): void
    {
        $r = Response::json(['x' => 1])->withETag('tok-v1');
        $this->assertArrayHasKey('ETag', $r->headers);
        $this->assertStringStartsWith('"', $r->headers['ETag']);
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('max-age=0', $r->headers['Cache-Control']);
    }

    #[Test]
    public function with_etag_honors_max_age(): void
    {
        $r = Response::json([])->withETag('x', maxAge: 60);
        $this->assertStringContainsString('max-age=60', $r->headers['Cache-Control']);
    }

    #[Test]
    public function with_etag_returns_304_on_match(): void
    {
        $r = Response::json(['a' => 1])->withETag('token-abc');
        $etag = $r->headers['ETag'];

        // Nuova request con lo stesso token → server deve rispondere 304
        $_SERVER['HTTP_IF_NONE_MATCH'] = $etag;
        $r2 = Response::json(['a' => 1])->withETag('token-abc');
        $this->assertSame(304, $r2->status);
        $this->assertSame('', $r2->body);
    }

    #[Test]
    public function with_etag_does_not_304_on_mismatch(): void
    {
        $_SERVER['HTTP_IF_NONE_MATCH'] = '"different-etag"';
        $r = Response::json(['a' => 1])->withETag('token-abc');
        $this->assertSame(200, $r->status);
        $this->assertNotSame('', $r->body);
    }

    #[Test]
    public function with_no_cache_sets_proper_headers(): void
    {
        $r = Response::json([])->withNoCache();
        $this->assertStringContainsString('no-store', $r->headers['Cache-Control']);
        $this->assertSame('no-cache', $r->headers['Pragma']);
    }

    #[Test]
    public function etag_is_stable_for_same_token(): void
    {
        $r1 = Response::json([])->withETag('tok');
        $r2 = Response::json([])->withETag('tok');
        $this->assertSame($r1->headers['ETag'], $r2->headers['ETag']);
    }

    #[Test]
    public function etag_differs_for_different_tokens(): void
    {
        $a = Response::json([])->withETag('token-a');
        $b = Response::json([])->withETag('token-b');
        $this->assertNotSame($a->headers['ETag'], $b->headers['ETag']);
    }
}
