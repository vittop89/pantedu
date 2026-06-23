<?php

namespace App\Controllers;

use App\Core\AccessLogger;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\RateLimiter;

final class AuthController
{
    public function showLogin(Request $req): Response
    {
        $redirect  = $req->query['redirect'] ?? '/';
        $errorCode = $req->query['error']    ?? null;

        $section = Auth::sectionFromUrl($redirect);
        if ($section) {
            Session::put('selectedIIS', $section['indirizzo']);
            Session::put('selectedCLS', $section['classe']);
        }

        return Response::html($this->renderLoginForm($redirect, $errorCode));
    }

    public function login(Request $req): Response
    {
        $username = trim((string)($req->post['username'] ?? ''));
        $password = (string)($req->post['password'] ?? '');
        $redirect = (string)($req->post['redirect'] ?? $req->query['redirect'] ?? '/');
        $section  = Auth::sectionFromUrl($redirect);
        $ip       = $this->clientIp($req);

        [$user, $reason] = Auth::attempt($username, $password, $section, $ip);

        if (!$user) {
            (new AccessLogger())->logAccess(
                $username ?: 'anonymous',
                'unknown',
                $redirect,
                'login_failed:' . ($reason ?? 'unknown')
            );
            // Ponte brute-force → auto-ban (lockout username + ban IP su
            // credential stuffing). Usa l'IP reale risolto via edge.
            if ($reason !== Auth::REASON_RATE_LIMITED) {
                $realIp = \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
                (new \App\Services\Waf\WafBruteforceGuard())->registerFailure($realIp, $username);
            }
            return Response::redirect('/login?error=' . urlencode($reason ?? 'unknown')
                . '&redirect=' . urlencode($redirect));
        }

        // Login riuscito: azzera il contatore fallimenti per l'username.
        (new \App\Services\Waf\WafBruteforceGuard())->clearOnSuccess($user->username);
        Csrf::rotate();
        (new AccessLogger())->logAccess($user->username, $user->role, $redirect, 'login');

        // Audit 25.R.31 (L7) — account con password one-time (es. admin iniziale
        // di un nuovo istituto): forza il cambio password al primo login. Il flag
        // resta in sessione → AuthMiddleware blocca le altre pagine finché non
        // viene cambiata; changePassword azzera flag DB + sessione.
        $uid = (int)(Auth::user()['id'] ?? 0);
        if ($uid > 0 && $this->mustChangePassword($uid)) {
            Session::put('must_change_password', true);
            return Response::redirect('/me/change-password?force=1');
        }
        return Response::redirect($this->safeRedirect($redirect));
    }

    /** Legge il flag must_change_password per l'utente (best-effort). */
    private function mustChangePassword(int $uid): bool
    {
        try {
            $st = Database::connection()->prepare('SELECT must_change_password FROM users WHERE id=? LIMIT 1');
            $st->execute([$uid]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable $e) {
            return false; // colonna assente / DB down: non bloccare il login
        }
    }

    public function logout(Request $req): Response
    {
        if (Auth::check()) {
            $u = Auth::user();
            (new AccessLogger())->logAccess(
                $u['username'] ?? 'unknown',
                $u['role']     ?? 'unknown',
                $req->server['HTTP_REFERER'] ?? null,
                'logout'
            );
        }
        Auth::logout();
        // Phase 25.R.1.3 — post-logout: redirect a /login (anziché home guest
        // confusionaria). L'utente esplicita la transizione di stato.
        $redirect = $this->safeRedirect((string)($req->query['redirect'] ?? '/login'));
        return Response::redirect($redirect);
    }

    /** GET /auth/csrf — return current token so the SPA can refresh forms */
    public function csrf(Request $req): Response
    {
        return Response::json(['token' => Csrf::token()]);
    }

    public function userInfo(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['authenticated' => false, 'message' => 'Utente non autenticato']);
        }
        $u = Auth::user();
        $section = $u['section'] ?? null;

        $response = [
            'authenticated'  => true,
            'username'       => $u['username'],
            'role'           => $u['role'],
            // Phase 24.63 — esposto al client per condizionare UI (es.
            // sidepage risdoc mostra template istituzionali base solo a
            // super_admin; teacher normali vedono solo le proprie istanze).
            'is_super_admin' => Auth::isSuperAdmin(),
            'login_time'     => Session::get('login_time'),
            'section'        => $section,
        ];

        if ($section) {
            // Uppercase canonico + legacy lowercase per back-compat
            $map = [
                'SCI' => 'Scientifico', 'sc' => 'Scientifico',
                'CLA' => 'Classico',    'cl' => 'Classico',
                'LIN' => 'Linguistico', 'ling' => 'Linguistico', 'li' => 'Linguistico',
                'ART' => 'Artistico',   'ar' => 'Artistico',
                'AFM' => 'AFM',         'af' => 'AFM',
            ];
            $response['section_display'] = [
                'address' => $map[$section['indirizzo']] ?? strtoupper($section['indirizzo']),
                'class'   => $section['classe'],
            ];
        }
        return Response::json($response);
    }

    private function clientIp(Request $req): string
    {
        return $req->server['HTTP_CLIENT_IP']
            ?? $req->server['HTTP_X_FORWARDED_FOR']
            ?? $req->server['REMOTE_ADDR']
            ?? 'unknown';
    }

    private function safeRedirect(string $url): string
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return '/';
        }
        return $url;
    }

    private function errorMessage(?string $code): string
    {
        return match ($code) {
            Auth::REASON_INVALID      => 'Username o password non validi.',
            Auth::REASON_INACTIVE     => 'Account non attivo. Contatta l\'amministratore.',
            Auth::REASON_BLOCKED      => '🚫 Account temporaneamente sospeso per motivi di sicurezza.',
            Auth::REASON_IP_BLOCKED   => '🚫 Il tuo IP è stato sospeso per questa sezione.',
            Auth::REASON_UNAUTHORIZED => 'Non sei autorizzato ad accedere a questa sezione.',
            Auth::REASON_RATE_LIMITED => 'Troppi tentativi falliti. Riprova tra qualche minuto.',
            null                      => '',
            default                   => 'Errore di accesso.',
        };
    }

    private function renderLoginForm(string $redirect, ?string $errorCode): string
    {
        $view    = View::default();
        $limiter = new RateLimiter();
        $body    = $view->render('auth/login', [
            'csrf'             => Csrf::token(),
            'redirect'         => $redirect,
            'error'            => $this->errorMessage($errorCode),
            'rateLimitSeconds' => $limiter->isBlocked() ? $limiter->remainingSeconds() : 0,
        ]);
        return $view->render('layout/shell', [
            'title' => 'Login — Pantedu',
            'body'  => $body,
            'modal' => true,
        ]);
    }
}
