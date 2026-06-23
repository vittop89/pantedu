<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/**
 * Phase 25.Q — Tenant middleware per scoping multi-istituto.
 *
 * Responsabilità:
 *  1. Inizializzare `$_SESSION['current_institute_id']` per ogni utente
 *     autenticato che ne sia privo (lazy via Auth::currentInstitute()).
 *  2. Gestire switch esplicito via query `?switch_institute=N` (UI selector
 *     per docenti multi-istituto o super-admin globale). La validazione
 *     dell'accesso avviene in Auth::setCurrentInstitute().
 *  3. Persistere la scelta del docente in cookie `fm_current_institute`
 *     così che sopravviva al logout/login (last-selected).
 *
 * Ordering raccomandato in Kernel:
 *   auth → tos → tenant → log → controller
 *
 * Usage in routes/web.php (gruppo autenticato):
 *   $router->group(['middleware' => ['auth', 'tenant']], function (...) { ... });
 *
 * Registrazione in Kernel:
 *   'tenant' => TenantMiddleware::class
 */
final class TenantMiddleware
{
    private const COOKIE_NAME = 'fm_current_institute';
    private const COOKIE_TTL_DAYS = 365;

    public function handle(Request $req, callable $next): Response
    {
        if (!Auth::check()) {
            return $next($req);
        }

        // 1. Switch esplicito via query string (selettore UI)
        $switch = $req->query['switch_institute'] ?? null;
        if ($switch !== null && ctype_digit((string)$switch)) {
            $iid = (int)$switch;
            if (Auth::setCurrentInstitute($iid)) {
                $this->setCookie($iid);
            }
        }

        // 2. Restore da cookie se la sessione è "fresca" e l'utente è docente
        // (gli student/admin hanno scope fisso da DB, no restore necessario).
        if (Session::get('current_institute_id') === null && Auth::role() === 'teacher') {
            $cookie = $_COOKIE[self::COOKIE_NAME] ?? null;
            if ($cookie !== null && ctype_digit((string)$cookie)) {
                Auth::setCurrentInstitute((int)$cookie);
            }
        }

        // 3. Lazy-init se ancora null (forza chiamata)
        if (Session::get('current_institute_id') === null) {
            Auth::currentInstitute();
        }

        return $next($req);
    }

    private function setCookie(int $iid): void
    {
        $expires = time() + (self::COOKIE_TTL_DAYS * 86400);
        setcookie(self::COOKIE_NAME, (string)$iid, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
