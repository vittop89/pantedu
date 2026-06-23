<?php

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class CsrfMiddleware
{
    public function handle(Request $req, callable $next): Response
    {
        if (\in_array($req->method, ['POST','PUT','PATCH','DELETE'], true)) {
            $token = $req->post['_csrf'] ?? $req->headers['x-csrf-token'] ?? null;
            if (!Csrf::verify($token)) {
                // Phase 16 — use 403 Forbidden instead of 419 (Laravel-only).
                // Apache su Windows rewrites non-standard codes (419 → 500),
                // rompendo il client auto-retry. 403 è universale.
                if ($req->wantsJson()) {
                    return Response::json(['error' => 'csrf_invalid'], 403);
                }
                $view = View::default();
                $body = $view->render('errors/generic', [
                    'code' => 403,
                    'title' => 'CSRF token invalid',
                    'message' => 'La sessione è scaduta o la richiesta non è stata autenticata. Ricarica la pagina e riprova.',
                    'icon' => '🔐',
                    'color' => 'var(--fm-c-warning)',
                ]);
                return Response::html($view->render('layout/shell', [
                    'title' => '403 — CSRF invalid',
                    'body'  => $body,
                ]), 403);
            }
        }
        return $next($req);
    }
}
