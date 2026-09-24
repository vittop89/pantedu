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

    /**
     * Il caso per cui `withETagFromBody()` esiste: due salvataggi nello stesso
     * secondo. Il segnalibro `id:updated_at` non li distingue — le date del
     * database contano i secondi — quindi il client riceveva un 304 su un
     * documento che era cambiato. Sulla risposta serializzata non succede.
     */
    #[Test]
    public function etag_from_body_distinguishes_two_saves_in_the_same_second(): void
    {
        $segnalibro = '42:2026-09-10 00:39:29';
        $prima  = ['ok' => true, 'content' => ['id' => 42, 'title' => 'Introduzione']];
        $dopo   = ['ok' => true, 'content' => ['id' => 42, 'title' => 'Introduzione [modificato]']];

        $vecchio = [
            Response::json($prima)->withETag($segnalibro)->headers['ETag'],
            Response::json($dopo)->withETag($segnalibro)->headers['ETag'],
        ];
        $this->assertSame($vecchio[0], $vecchio[1], 'il segnalibro a secondi non vedeva la differenza');

        $nuovo = [
            Response::json($prima)->withETagFromBody()->headers['ETag'],
            Response::json($dopo)->withETagFromBody()->headers['ETag'],
        ];
        $this->assertNotSame($nuovo[0], $nuovo[1], 'sul corpo la differenza si vede');
    }

    #[Test]
    public function etag_from_body_defaults_to_no_freshness_window(): void
    {
        // Zero vuol dire «chiedi sempre»: il risparmio resta nel 304, non in
        // una finestra durante la quale il browser risponde da solo.
        $r = Response::json(['x' => 1])->withETagFromBody();
        $this->assertStringContainsString('max-age=0', $r->headers['Cache-Control']);
    }

    #[Test]
    public function etag_from_body_keeps_an_explicit_window(): void
    {
        $r = Response::json(['x' => 1])->withETagFromBody(maxAge: 30);
        $this->assertStringContainsString('max-age=30', $r->headers['Cache-Control']);
    }

    #[Test]
    public function etag_from_body_returns_304_on_identical_content(): void
    {
        $dati = ['ok' => true, 'rows' => [['id' => 1], ['id' => 2]]];
        $_SERVER['HTTP_IF_NONE_MATCH'] = Response::json($dati)->withETagFromBody()->headers['ETag'];

        $r = Response::json($dati)->withETagFromBody();
        $this->assertSame(304, $r->status);
        $this->assertSame('', $r->body);
    }

    #[Test]
    public function etag_from_body_refuses_a_response_served_from_disk(): void
    {
        // Lì il corpo in memoria è vuoto: un ETag calcolato su di esso sarebbe
        // identico per file diversi. Meglio dirlo che sbagliarlo in silenzio.
        $this->expectException(\LogicException::class);
        Response::file(__FILE__)->withETagFromBody();
    }
}
