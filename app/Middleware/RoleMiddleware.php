<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class RoleMiddleware
{
    public function handle(Request $req, callable $next, string ...$zones): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login?redirect=' . urlencode($req->server['REQUEST_URI'] ?? '/'));
        }

        $ok = false;
        foreach ($zones as $zone) {
            if (Auth::hasAccess($zone)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            if ($req->wantsJson()) {
                return Response::json(['error' => 'forbidden', 'role' => Auth::role()], 403);
            }
            return Response::html($this->forbiddenPage(Auth::role(), $zones), 403);
        }
        return $next($req);
    }

    private function forbiddenPage(string $role, array $zones): string
    {
        $view = View::default();
        $body = $view->render('errors/generic', [
            'code'    => 403,
            'icon'    => '⛔',
            'title'   => 'Accesso negato',
            'color'   => 'var(--fm-c-warning)',
            'message' => 'Ruolo attuale: ' . $role . ' — richiesto: ' . implode(', ', $zones) . '.',
            'showLogout' => true,
        ]);
        return $view->render('layout/shell', [
            'title' => '403 — Accesso negato',
            'body'  => $body,
        ]);
    }
}
