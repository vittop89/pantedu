<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

final class AuthMiddleware
{
    // Audit 25.R.31 (L7) — path consentiti anche con must_change_password attivo.
    private const PWCHANGE_ALLOWLIST = [
        '/me/change-password', '/logout', '/auth/csrf',
    ];

    public function handle(Request $req, callable $next): Response
    {
        if (!Auth::check()) {
            if ($req->wantsJson()) {
                return Response::json(['error' => 'unauthenticated'], 401);
            }
            $redirect = urlencode($req->server['REQUEST_URI'] ?? '/');
            return Response::redirect("/login?redirect=$redirect");
        }

        // Audit 25.R.31 (L7) — finché must_change_password è attivo, l'utente è
        // confinato alla pagina di cambio password (account one-time).
        if (Session::get('must_change_password')) {
            $path = strtok((string)($req->server['REQUEST_URI'] ?? '/'), '?');
            if (!in_array($path, self::PWCHANGE_ALLOWLIST, true)) {
                if ($req->wantsJson()) {
                    return Response::json(['error' => 'password_change_required'], 403);
                }
                return Response::redirect('/me/change-password?force=1');
            }
        }
        return $next($req);
    }
}
