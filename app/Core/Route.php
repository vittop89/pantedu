<?php

declare(strict_types=1);

namespace App\Core;

final class Route
{
    public array $params = [];
    private ?string $compiledRegex = null;

    public function __construct(
        public array $methods,
        public string $pattern,
        public mixed $handler,
        public array $middleware = [],
    ) {
    }

    public function middleware(string ...$names): self
    {
        $this->middleware = array_merge($this->middleware, $names);
        return $this;
    }

    /** True if the path matches; method check is separate so callers can return 405. */
    public function matchesPath(string $path, array &$params = []): bool
    {
        if ($this->compiledRegex === null) {
            $this->compiledRegex = $this->compile($this->pattern);
        }
        if (preg_match($this->compiledRegex, $path, $m)) {
            $params = [];
            foreach ($m as $k => $v) {
                if (!\is_int($k)) {
                    $params[$k] = $v;
                }
            }
            return true;
        }
        return false;
    }

    public function matches(Request $req): bool
    {
        if (!\in_array($req->method, $this->methods, true)) {
            return false;
        }
        $params = [];
        if (!$this->matchesPath($req->path, $params)) {
            return false;
        }
        $this->params = $params;
        return true;
    }

    private function compile(string $pattern): string
    {
        $regex = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(\*|\?)?\}#',
            static function ($m) {
                $name = $m[1];
                $mod  = $m[2] ?? '';
                return match ($mod) {
                    '*'     => '(?P<' . $name . '>.*)',
                    '?'     => '(?P<' . $name . '>[^/]*)',
                    default => '(?P<' . $name . '>[^/]+)',
                };
            },
            $pattern,
        );
        return '#^' . rtrim($regex, '/') . '/?$#';
    }
}
