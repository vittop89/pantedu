<?php

namespace App\Core;

final class Request
{
    public readonly string $method;
    public readonly string $path;
    public readonly array $query;
    public readonly array $post;
    public readonly array $server;
    public readonly array $headers;

    public function __construct()
    {
        $this->method  = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri           = $_SERVER['REQUEST_URI'] ?? '/';
        $this->path    = '/' . trim(parse_url($uri, PHP_URL_PATH) ?? '/', '/');
        $this->query   = $_GET  ?? [];
        $this->post    = $_POST ?? [];
        $this->server  = $_SERVER;
        $this->headers = self::parseHeaders();
    }

    private static function parseHeaders(): array
    {
        $h = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = str_replace('_', '-', strtolower(substr($k, 5)));
                $h[$name] = $v;
            }
        }
        return $h;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function wantsJson(): bool
    {
        $accept = $this->headers['accept'] ?? '';
        return str_contains($accept, 'application/json')
            || ($this->headers['x-requested-with'] ?? '') === 'XMLHttpRequest';
    }
}
