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

    // 2026-09-01 — path consentiti a chi deve ancora iscriversi alla 2FA
    // obbligatoria per il proprio ruolo (security.totp_required_roles).
    private const ENROL2FA_ALLOWLIST = [
        '/me/2fa', '/me/2fa/setup', '/me/2fa/enable',
        '/me/2fa/setup-email', '/me/2fa/enable-email',
        '/logout', '/auth/csrf',
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
        // 2026-09-01 — la 2FA imposta per ruolo va completata prima di usare
        // il resto: senza questo confinamento `totp_required_roles` sarebbe
        // rimasta una preferenza scritta e mai applicata.
        if (Session::get('must_enrol_2fa')) {
            $path = strtok((string)($req->server['REQUEST_URI'] ?? '/'), '?');
            if (!in_array($path, self::ENROL2FA_ALLOWLIST, true)) {
                if ($req->wantsJson()) {
                    return Response::json(['error' => 'two_factor_enrolment_required'], 403);
                }
                return Response::redirect('/me/2fa?force=1');
            }
        }

        return $next($req);
    }
}
