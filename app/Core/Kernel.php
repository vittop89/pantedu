<?php

namespace App\Core;

use Throwable;

final class Kernel
{
    /** @var array<string, class-string> */
    private array $middlewareMap = [
        'auth'        => \App\Middleware\AuthMiddleware::class,
        'role'        => \App\Middleware\RoleMiddleware::class,
        'csrf'        => \App\Middleware\CsrfMiddleware::class,
        'log'         => \App\Middleware\AccessLogMiddleware::class,
        'rate'        => \App\Middleware\RateLimitMiddleware::class,
        'legacy_gone'    => \App\Middleware\LegacyGoneMiddleware::class,
        'sadmin_audit'   => \App\Middleware\SuperAdminAuditMiddleware::class,
        // Phase 25.B4 — audit reason obbligatoria su mutazioni admin
        'audit_reason'   => \App\Middleware\RequiresAuditReasonMiddleware::class,
        // Phase 25.B6 — security headers (CSP, HSTS, X-Frame-Options, ecc.)
        'sec_headers'    => \App\Middleware\SecurityHeadersMiddleware::class,
        // Phase 25.E4 — request ID correlation (X-Request-ID per tracing)
        'request_id'     => \App\Middleware\RequestIdMiddleware::class,
        // G22.S26 — gate super-admin centralizzato (evita check inline ripetuti)
        'super_admin_required' => \App\Middleware\SuperAdminRequiredMiddleware::class,
        // Phase 25.C — WAF self-hosted (geo + fingerprinting + scoring + rules)
        // Applicato globalmente in handle() se waf_config.enabled=1.
        'waf'            => \App\Middleware\WafMiddleware::class,
        // Phase 25.P — ToS+AUP enforce (DISABILITATO di default via env
        // TOS_ENFORCE=false). Da abilitare in Scenario B/C multi-tenant.
        'tos'            => \App\Middleware\TosAcceptanceMiddleware::class,
        // Phase 25.Q — Tenant middleware (multi-istituto): set/restore
        // current_institute_id in sessione, gestisce switch via UI selector.
        'tenant'         => \App\Middleware\TenantMiddleware::class,
    ];

    public function __construct(private Router $router)
    {
    }

    public function handle(Request $req): Response
    {
        // Phase 25.E4 — Request ID correlation: imposta X-Request-ID prima
        // di TUTTO (log rotation incluso) per avere correlazione anche su
        // errori early-stage.
        if (empty($_SERVER['X_REQUEST_ID'] ?? '')) {
            $rid = $req->server['HTTP_X_REQUEST_ID'] ?? '';
            if (is_string($rid) && preg_match('/^[A-Za-z0-9-]{1,64}$/', $rid)) {
                $_SERVER['X_REQUEST_ID'] = $rid;
            } else {
                $b = random_bytes(16);
                $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
                $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
                $hex = bin2hex($b);
                $_SERVER['X_REQUEST_ID'] = substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
                    . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
            }
        }

        // G22.S20 v2.C2 — Preload curriculum catalog (cache statica per-request).
        // ~20-50 row totali in curriculum_entries → 1 query iniziale invece
        // di N lookup ripetuti dai Repository/Service durante la request.
        try {
            \App\Support\CurriculumLookup::preload();
        } catch (Throwable) {
/* fail-safe: lookup runtime farà fallback */
        }

        // Phase 19-20 — in-process log rotation throttled (1h sentinel).
        // Copre storage/logs/ + log/errors/ (legacy). Fail-safe.
        try {
            $base = \dirname(__DIR__, 2);
            \App\Services\LogRotator::maybeRotateAll([
                (string)Config::get('app.paths.logs', $base . '/storage/logs'),
                $base . '/log/errors',
            ]);
        } catch (Throwable) {
/* best-effort */
        }

        try {
            // Phase 25.C — WAF middleware applicato globalmente (gate by DB flag).
            // Wrap del pipeline router così la decisione WAF avviene PRIMA del match.
            // WafMiddleware fa early-exit se waf_config.enabled=0 (zero overhead).
            $execute = function (Request $r): Response {
                $route = $this->router->match($r);
                if (!$route) {
                    return Response::html($this->errorPage(
                        404,
                        'Not Found',
                        'La pagina richiesta non esiste.',
                        '🔎'
                    ), 404);
                }
                $pipeline = $this->buildPipeline($route, $r);
                return $pipeline($r);
            };
            try {
                $resp = (new \App\Middleware\WafMiddleware())->handle($req, $execute);
            } catch (Throwable) {
                // WAF fail-safe: in caso di errore middleware non bloccare la request
                $resp = $execute($req);
            }
            return $this->applySecurityHeaders($req, $resp);
        } catch (Throwable $e) {
            $resp = $this->renderError($e);
            return $this->applySecurityHeaders($req, $resp);
        }
    }

    /**
     * Phase 25.B6 — security headers applicati GLOBALMENTE su ogni response,
     * indipendentemente dalla route. Approccio centralized invece di
     * ->middleware('sec_headers') sparso su 100+ route.
     *
     * Skippabile per asset statici (file response, performance) — i security
     * headers sono comunque settati dal webserver (.htaccess) per static files.
     */
    private function applySecurityHeaders(Request $req, Response $response): Response
    {
        if (isset($response->headers['X-Serve-File'])) {
            // Static file response: header settati dal webserver, skip.
            return $response;
        }
        // Phase 25.E4 — echo-back X-Request-ID per debug client
        $rid = $_SERVER['X_REQUEST_ID'] ?? null;
        if ($rid) {
            $response->headers['X-Request-ID'] = $rid;
        }

        return (new \App\Middleware\SecurityHeadersMiddleware())->handle($req, fn() => $response);
    }

    private function buildPipeline(Route $route, Request $req): callable
    {
        $handler = fn(Request $r) => $this->invoke($route, $r);

        $middleware = array_reverse($route->middleware);
        foreach ($middleware as $name) {
            $mwName = $name;
            $args   = [];
            if (str_contains($name, ':')) {
                [$mwName, $arg] = explode(':', $name, 2);
                $args = explode(',', $arg);
            }
            $class = $this->middlewareMap[$mwName] ?? null;
            if (!$class) {
                continue;
            }

            $next    = $handler;
            $handler = fn(Request $r) => (new $class())->handle($r, $next, ...$args);
        }

        return $handler;
    }

    private function invoke(Route $route, Request $req): Response
    {
        $h = $route->handler;

        if (is_string($h)) {
            return Response::file($h);
        }
        if (is_callable($h)) {
            $result = $h($req, $route->params);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }
        if (is_array($h) && count($h) === 2) {
            [$class, $method] = $h;
            $result = (new $class())->{$method}($req, $route->params);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }
        return Response::html('<h1>500 Invalid handler</h1>', 500);
    }

    private function renderError(Throwable $e): Response
    {
        error_log('[KERNEL] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (Config::get('app.debug')) {
            $extra = '<pre style="text-align:left;background:#f3f4f7;padding:1rem;border-radius:4px;'
                   . 'overflow:auto;max-height:50vh;font-size:.8rem">'
                   . e($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
            return Response::html($this->errorPage(
                500,
                'Internal Server Error',
                'Si è verificato un errore interno.',
                '💥',
                $extra
            ), 500);
        }
        return Response::html($this->errorPage(
            500,
            'Internal Server Error',
            'Si è verificato un errore interno. Riprova più tardi.',
            '💥'
        ), 500);
    }

    private function errorPage(int $code, string $title, string $message, string $icon, string $extraHtml = ''): string
    {
        $view = View::default();
        $body = $view->render('errors/generic', [
            'code'      => $code,
            'title'     => $title,
            'message'   => $message,
            'icon'      => $icon,
            'extraHtml' => $extraHtml,
        ]);
        return $view->render('layout/shell', [
            'title' => "$code — $title",
            'body'  => $body,
        ]);
    }
}
