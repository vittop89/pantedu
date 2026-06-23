<?php

namespace Tests\Unit;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CsrfMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = []; $_POST = [];
        foreach (\array_keys($_SERVER) as $k) {
            if (\str_starts_with($k, 'HTTP_')) unset($_SERVER[$k]);
        }
    }

    private function mkReq(string $method, array $post = [], array $headers = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/x';
        $_POST = $post;
        foreach ($headers as $k => $v) {
            $_SERVER['HTTP_' . \strtoupper(\str_replace('-', '_', $k))] = $v;
        }
        return new Request();
    }

    #[Test]
    public function get_requests_pass_without_token(): void
    {
        $mw = new CsrfMiddleware();
        $called = false;
        $mw->handle($this->mkReq('GET'), function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called);
    }

    #[Test]
    public function post_without_token_is_blocked_with_403(): void
    {
        $mw = new CsrfMiddleware();
        $res = $mw->handle($this->mkReq('POST'), fn() => Response::json(['ok' => true]));
        $this->assertSame(403, $res->status);
    }

    #[Test]
    public function post_with_valid_body_token_passes(): void
    {
        $token = Csrf::token();
        $mw = new CsrfMiddleware();
        $called = false;
        $mw->handle($this->mkReq('POST', ['_csrf' => $token]), function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called);
    }

    #[Test]
    public function post_with_valid_header_token_passes(): void
    {
        $token = Csrf::token();
        $mw = new CsrfMiddleware();
        $called = false;
        $mw->handle($this->mkReq('POST', [], ['X-CSRF-Token' => $token]), function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called);
    }

    #[Test]
    public function post_with_wrong_token_blocked(): void
    {
        Csrf::token();
        $mw = new CsrfMiddleware();
        $res = $mw->handle($this->mkReq('POST', ['_csrf' => 'wrong']), fn() => Response::json(['ok' => true]));
        $this->assertSame(403, $res->status);
    }

    #[Test]
    public function json_request_gets_json_error_body(): void
    {
        $mw = new CsrfMiddleware();
        $res = $mw->handle($this->mkReq('POST', [], ['Accept' => 'application/json']),
            fn() => Response::json(['ok' => true]));
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('csrf_invalid', (string)$res->body);
    }

    #[Test]
    public function rotate_invalidates_previous_token(): void
    {
        $t1 = Csrf::token();
        Csrf::rotate();
        $this->assertFalse(Csrf::verify($t1));
    }

    #[Test]
    public function put_and_delete_also_checked(): void
    {
        $mw = new CsrfMiddleware();
        foreach (['PUT', 'PATCH', 'DELETE'] as $m) {
            $res = $mw->handle($this->mkReq($m), fn() => Response::json(['ok' => true]));
            $this->assertSame(403, $res->status, "method $m");
        }
    }
}
