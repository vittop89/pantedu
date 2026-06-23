<?php

namespace Tests\Unit\Core;

use App\Core\Router;
use App\Core\Request;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function mkReq(string $method, string $path): Request
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI']    = $path;
        $_GET = []; $_POST = [];
        foreach (\array_keys($_SERVER) as $k) {
            if (\str_starts_with($k, 'HTTP_')) unset($_SERVER[$k]);
        }
        return new Request();
    }

    #[Test]
    public function matches_exact_get_route(): void
    {
        $r = new Router();
        $r->get('/foo', fn() => 'ok');
        $route = $r->match($this->mkReq('GET', '/foo'));
        $this->assertNotNull($route);
        $this->assertContains('GET', $route->methods);
    }

    #[Test]
    public function no_match_returns_null(): void
    {
        $r = new Router();
        $r->get('/foo', fn() => 'ok');
        $this->assertNull($r->match($this->mkReq('GET', '/bar')));
    }

    #[Test]
    public function extracts_named_params(): void
    {
        $r = new Router();
        $r->get('/users/{id}', fn() => 'x');
        $route = $r->match($this->mkReq('GET', '/users/42'));
        $this->assertNotNull($route);
        $this->assertSame('42', $route->params['id']);
    }

    #[Test]
    public function wildcard_star_captures_rest(): void
    {
        $r = new Router();
        $r->get('/files/{path*}', fn() => 'x');
        $route = $r->match($this->mkReq('GET', '/files/a/b/c.txt'));
        $this->assertNotNull($route);
        $this->assertSame('a/b/c.txt', $route->params['path']);
    }

    #[Test]
    public function group_accumulates_middleware(): void
    {
        $r = new Router();
        $r->group(['middleware' => ['auth']], function (Router $rr) {
            $rr->group(['middleware' => ['csrf']], function (Router $rrr) {
                $rrr->post('/x', fn() => 'ok');
            });
        });
        $route = $r->match($this->mkReq('POST', '/x'));
        $this->assertNotNull($route);
        $this->assertContains('auth', $route->middleware);
        $this->assertContains('csrf', $route->middleware);
    }

    #[Test]
    public function group_accumulates_prefix(): void
    {
        $r = new Router();
        $r->group(['prefix' => '/api'], function (Router $rr) {
            $rr->get('/users', fn() => 'ok');
        });
        $route = $r->match($this->mkReq('GET', '/api/users'));
        $this->assertNotNull($route);
    }

    #[Test]
    public function method_mismatch_returns_null(): void
    {
        $r = new Router();
        $r->get('/foo', fn() => 'ok');
        $this->assertNull($r->match($this->mkReq('POST', '/foo')));
    }

    #[Test]
    public function any_matches_all_methods(): void
    {
        $r = new Router();
        $r->any('/anything', fn() => 'ok');
        foreach (['GET','POST','PUT','DELETE','PATCH'] as $m) {
            $this->assertNotNull($r->match($this->mkReq($m, '/anything')), "method $m");
        }
    }

    #[Test]
    public function route_middleware_fluent_appends(): void
    {
        $r = new Router();
        $r->get('/foo', fn() => 'ok')->middleware('csrf', 'rate');
        $route = $r->match($this->mkReq('GET', '/foo'));
        $this->assertSame(['csrf', 'rate'], $route->middleware);
    }
}
