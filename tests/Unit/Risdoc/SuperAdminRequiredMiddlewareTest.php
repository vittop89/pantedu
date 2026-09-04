<?php

declare(strict_types=1);

namespace Tests\Unit\Risdoc;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\SuperAdminRequiredMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test del gate super-admin. Manipola $_SESSION direttamente
 * (pattern coerente con CsrfMiddlewareTest e altri unit middleware).
 */
final class SuperAdminRequiredMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        foreach (\array_keys($_SERVER) as $k) {
            if (\str_starts_with($k, 'HTTP_')) {
                unset($_SERVER[$k]);
            }
        }
    }

    private function mkReq(string $method = 'GET', array $headers = []): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = '/admin/test';
        foreach ($headers as $k => $v) {
            $_SERVER['HTTP_' . \strtoupper(\str_replace('-', '_', $k))] = $v;
        }
        return new Request();
    }

    private function loginAs(string $role, bool $superAdmin = false): void
    {
        // Username unico per test → bypassa cache memory statica AclPolicy
        // (i test in random_order non si interferiscono fra loro).
        $username = 'tester_' . \bin2hex(\random_bytes(4));
        $_SESSION['autenticato'] = true;
        $_SESSION['username']    = $username;
        $_SESSION['user_id']     = 999;
        $_SESSION['user_role']   = $role;
        // AclPolicy::isSuperAdmin() check session cache 'fm_super_admin_cache'
        // prima del DB lookup → seed la cache per bypassare DB in unit test.
        $_SESSION['fm_super_admin_cache'] = [
            'username' => $username,
            'v'        => $superAdmin,
            't'        => \time(),
        ];
    }

    #[Test]
    public function super_admin_passes(): void
    {
        $this->loginAs('administrator', superAdmin: true);
        $mw = new SuperAdminRequiredMiddleware();
        $called = false;
        $res = $mw->handle($this->mkReq(), function () use (&$called) {
            $called = true;
            return Response::json(['ok' => true]);
        });
        $this->assertTrue($called, 'next handler must be invoked for super_admin');
        $this->assertSame(200, $res->status);
    }

    #[Test]
    public function teacher_is_blocked_with_403_json(): void
    {
        $this->loginAs('teacher');
        $mw = new SuperAdminRequiredMiddleware();
        $res = $mw->handle(
            $this->mkReq('GET', ['Accept' => 'application/json']),
            fn() => Response::json(['ok' => true]),
        );
        $this->assertSame(403, $res->status);
        $this->assertStringContainsString('forbidden', (string)$res->body);
    }

    #[Test]
    public function anonymous_is_blocked(): void
    {
        // no login
        $mw = new SuperAdminRequiredMiddleware();
        $res = $mw->handle(
            $this->mkReq('GET', ['Accept' => 'application/json']),
            fn() => Response::json(['ok' => true]),
        );
        $this->assertSame(403, $res->status);
    }

    #[Test]
    public function html_response_for_non_json_request(): void
    {
        $this->loginAs('student');
        $mw = new SuperAdminRequiredMiddleware();
        $res = $mw->handle($this->mkReq(), fn() => Response::json(['ok' => true]));
        $this->assertSame(403, $res->status);
        // wantsJson=false → body HTML, non JSON
        $this->assertStringNotContainsString('"error"', (string)$res->body);
    }
}
