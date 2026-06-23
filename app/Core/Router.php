<?php

namespace App\Core;

final class Router
{
    private array $routes = [];
    private array $groupStack = [];

    public function get(string $pattern, mixed $handler): Route
    {
        return $this->add(['GET', 'HEAD'], $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): Route
    {
        return $this->add(['POST'], $pattern, $handler);
    }

    public function put(string $pattern, mixed $handler): Route
    {
        return $this->add(['PUT'], $pattern, $handler);
    }

    public function delete(string $pattern, mixed $handler): Route
    {
        return $this->add(['DELETE'], $pattern, $handler);
    }

    public function any(string $pattern, mixed $handler): Route
    {
        return $this->add(['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], $pattern, $handler);
    }

    public function group(array $attributes, callable $callback): void
    {
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
    }

    private function add(array $methods, string $pattern, mixed $handler): Route
    {
        $prefix     = '';
        $middleware = [];
        foreach ($this->groupStack as $g) {
            $prefix    .= $g['prefix']     ?? '';
            $middleware = array_merge($middleware, $g['middleware'] ?? []);
        }
        $route = new Route($methods, $prefix . $pattern, $handler, $middleware);
        $this->routes[] = $route;
        return $route;
    }

    /** @return Route|null */
    public function match(Request $req): ?Route
    {
        foreach ($this->routes as $r) {
            if ($r->matches($req)) {
                return $r;
            }
        }
        return null;
    }
}
