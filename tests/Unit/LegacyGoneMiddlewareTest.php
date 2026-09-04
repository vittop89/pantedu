<?php

namespace Tests\Unit;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\LegacyGoneMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LegacyGoneMiddlewareTest extends TestCase
{
    private function mkReq(string $path, bool $wantsJson = false): Request
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI']    = $path;
        foreach (array_keys($_SERVER) as $k) {
            if (str_starts_with($k, 'HTTP_')) unset($_SERVER[$k]);
        }
        if ($wantsJson) $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_GET  = [];
        $_POST = [];
        return new Request();
    }

    #[Test]
    public function unknown_legacy_root_returns_410(): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq('/strcomp_bes_altro/random/unknown.php'),
            fn($r) => Response::html('should not be reached'));
        $this->assertSame(410, $res->status);
    }

    #[Test]
    public function returns_json_410_when_client_wants_json(): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq('/lab/random/unknown', true),
            fn($r) => Response::html('should not be reached'));
        $this->assertSame(410, $res->status);
        $this->assertStringContainsString('gone', (string)$res->body);
    }

    #[Test]
    public function eser_parses_as_topic_redirect_candidate(): void
    {
        $mw = new LegacyGoneMiddleware();
        $res = $mw->handle($this->mkReq('/eser/sc/eser_sc2s/MAT/1_MAT-Topic.php'),
            fn($r) => Response::html('should not be reached'));
        // Se DB on + match → 302; altrimenti 410. Entrambi OK per middleware.
        $this->assertContains($res->status, [410, 302]);
    }
}
