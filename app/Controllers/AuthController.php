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
use App\Repositories\UserRepository;
use App\Services\RateLimiter;
use App\Services\Security\TwoFactorPolicy;

final class AuthController
{
    /** Quanto resta valido un login a meta' strada, in attesa del codice. */
    private const TOTP_WINDOW_SECONDS = 300;

    public function showLogin(Request $req): Response
    {
        $redirect  = $req->query['redirect'] ?? '/';
        $errorCode = $req->query['error']    ?? null;

        $section = Auth::sectionFromUrl($redirect);
        if ($section) {
            Session::put('selectedIIS', $section['indirizzo']);
            Session::put('selectedCLS', $section['classe']);
        }

        // Login a meta' strada gia' in corso: riportalo dov'era, invece di
        // ripresentare il campo password come se nulla fosse.
        if ($errorCode === null && is_array(Session::get('pending_2fa'))) {
            return Response::redirect('/login/2fa');
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

        // establishSession: false — la sessione NON si apre qui. Password
        // corretta e' solo il primo dei due passaggi: aprirla adesso e
        // richiuderla dopo avrebbe lasciato, per un istante, una sessione
        // autenticata a chi il secondo fattore non l'ha ancora superato.
        [$user, $reason] = Auth::attempt($username, $password, $section, $ip, establishSession: false);

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

        // Password corretta: azzera il contatore fallimenti per l'username.
        (new \App\Services\Waf\WafBruteforceGuard())->clearOnSuccess($user->username);
        Csrf::rotate();

        // Secondo fattore attivo: si passa alla verifica del codice, senza
        // aprire la sessione. Fino ad allora l'utente non e' autenticato.
        $policy = new TwoFactorPolicy();
        if ($policy->enabledFor($user->username)) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true); // fixation: l'id cambia comunque
            }
            Session::put('pending_2fa', [
                'username' => $user->username,
                'redirect' => $redirect,
                'section'  => $section,
                'method'   => $policy->methodFor($user->username),
                'at'       => time(),
            ]);

            // Col metodo email il codice non esiste finche' non lo si spedisce:
            // si parte qui, non su /login/2fa, perche' quella pagina puo'
            // essere ricaricata e ogni ricarica manderebbe una mail.
            if ($policy->methodFor($user->username) === 'email') {
                (new \App\Services\Security\EmailSecondFactor())->issue($user->username, 'login');
            }
            (new AccessLogger())->logAccess($user->username, $user->role, $redirect, 'login_2fa_pending');
            return Response::redirect('/login/2fa');
        }

        Auth::establishSession($user, $section);
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

        // Ruolo per cui la 2FA e' obbligatoria (security.totp_required_roles)
        // ma non ancora attivata: l'utente viene accompagnato all'iscrizione e
        // AuthMiddleware lo tiene li' finche' non l'ha completata. Prima
        // d'ora quella configurazione non era letta da nessuna riga.
        if ((new TwoFactorPolicy())->mustEnrol($user->username, $user->role)) {
            Session::put('must_enrol_2fa', true);
            return Response::redirect('/me/2fa?force=1');
        }
        return Response::redirect($this->landingFor($user, $redirect));
    }

    /**
     * Dove atterra chi ha appena effettuato l'accesso (2026-09-04).
     *
     * Un redirect esplicito e sicuro vince. «/» pero' non e' una preferenza:
     * e' il valore predefinito del campo nascosto del form, e la home pubblica
     * non e' il posto di chi amministra — dopo la separazione dei privilegi
     * l'account amministrativo non ha altro da fare che il pannello. Vale per
     * il login semplice e per quello che passa dal secondo fattore.
     */
    private function landingFor(object $user, string $redirect): string
    {
        $safe = $this->safeRedirect($redirect, '/');
        $noPreference = in_array($safe, ['/', '/?home=1', '/?home'], true);
        if ($noPreference && (Auth::isSuperAdmin() || (string)($user->role ?? '') === 'administrator')) {
            return '/admin/dashboard';
        }
        return $safe;
    }

    /** GET /login/2fa — chiede il codice del secondo fattore. */
    public function show2fa(Request $req): Response
    {
        if (!is_array(Session::get('pending_2fa'))) {
            return Response::redirect('/login');
        }
        $pending = Session::get('pending_2fa');
        $username = is_array($pending) ? (string)($pending['username'] ?? '') : '';
        $metodo   = is_array($pending) ? (string)($pending['method'] ?? 'app') : 'app';

        return Response::html($this->renderTotpChallenge(
            (string)($req->query['error'] ?? ''),
            $metodo,
            $metodo === 'email' ? (new \App\Services\Security\EmailSecondFactor())->maskedAddress($username) : null
        ));
    }

    /** POST /login/2fa — verifica il codice e apre la sessione. */
    public function verify2fa(Request $req): Response
    {
        $pending = Session::get('pending_2fa');
        if (!is_array($pending) || empty($pending['username'])) {
            return Response::redirect('/login');
        }

        // Finestra stretta di proposito: un pending dimenticato su una
        // postazione condivisa e' una password gia' indovinata che aspetta.
        if (time() - (int)($pending['at'] ?? 0) > self::TOTP_WINDOW_SECONDS) {
            Session::forget('pending_2fa');
            return Response::redirect('/login?error=' . urlencode('2fa_expired'));
        }

        $username = (string)$pending['username'];

        // Contatore dedicato: sei cifre si tirano a indovinare in fretta, e il
        // limitatore del login e' gia' stato azzerato dalla password corretta.
        $limiter = new RateLimiter('totp_attempts', 5, 300);
        if ($limiter->isBlocked()) {
            return Response::redirect('/login/2fa?error=' . urlencode('rate_limited'));
        }

        $code      = trim((string)($req->post['code'] ?? ''));
        $policy    = new TwoFactorPolicy();
        $viaBackup = false;

        if (!$policy->verifyCode($username, $code)) {
            // Un codice di backup e' l'unica via per chi ha perso il telefono.
            if (!$policy->consumeBackupCode($username, $code)) {
                $limiter->hit();
                (new AccessLogger())->logAccess($username, 'unknown', '/login/2fa', 'login_2fa_failed');
                (new \App\Services\Waf\WafBruteforceGuard())->registerFailure(
                    \App\Services\Waf\EdgeContext::clientIp($req->server ?? []),
                    $username
                );
                return Response::redirect('/login/2fa?error=' . urlencode('invalid_code'));
            }
            $viaBackup = true;
        }

        $user = (new UserRepository())->find($username);
        if (!$user || !$user->active) {
            Session::forget('pending_2fa');
            return Response::redirect('/login?error=' . urlencode(Auth::REASON_INACTIVE));
        }

        $redirect = (string)($pending['redirect'] ?? '/');
        $section  = is_array($pending['section'] ?? null) ? $pending['section'] : null;
        Session::forget('pending_2fa');
        $limiter->reset();
        Auth::establishSession($user, $section);
        Csrf::rotate();
        (new AccessLogger())->logAccess(
            $user->username,
            $user->role,
            $redirect,
            $viaBackup ? 'login_2fa_backup_code' : 'login_2fa'
        );

        if ($viaBackup) {
            // Chi entra con un codice di riserva deve sapere quanti gliene
            // restano: esauriti, e senza telefono, non entra piu'.
            Session::put('flash_2fa_backup_left', $policy->backupCodesLeft($username));
        }

        $uid = (int)(Auth::user()['id'] ?? 0);
        if ($uid > 0 && $this->mustChangePassword($uid)) {
            Session::put('must_change_password', true);
            return Response::redirect('/me/change-password?force=1');
        }
        return Response::redirect($this->landingFor($user, $redirect));
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

    private function safeRedirect(string $url, string $default = '/'): string
    {
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $default;
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
            '2fa_expired'             => 'Tempo scaduto per la verifica in due passaggi. Rifai l\'accesso.',
            null                      => '',
            default                   => 'Errore di accesso.',
        };
    }

    private function renderTotpChallenge(string $errorCode, string $metodo = 'app', ?string $indirizzo = null): string
    {
        $view = View::default();
        $body = $view->render('auth/totp_challenge', [
            'csrf'      => Csrf::token(),
            'metodo'    => $metodo,
            'indirizzo' => $indirizzo,
            'error' => match ($errorCode) {
                'invalid_code' => 'Codice non valido. Controlla l\'app di autenticazione, oppure usa uno dei codici di backup.',
                'rate_limited' => 'Troppi tentativi. Riprova fra qualche minuto.',
                default        => '',
            },
        ]);
        return $view->render('layout/shell', [
            'title' => 'Verifica in due passaggi — Pantedu',
            'body'  => $body,
            'modal' => true,
        ]);
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
            // ADR-032 — la pagina cambia con lo scenario: iscrizioni aperte o
            // chiuse, account studente o credenziale di classe, SPID/CIE.
            'scenario'         => \App\Support\DeploymentScenario::snapshot(),
        ]);
        return $view->render('layout/shell', [
            'title' => 'Login — Pantedu',
            'body'  => $body,
            'modal' => true,
        ]);
    }

    /**
     * GET /accesso-classe — ADR-032, scenari 1 e 2: gli studenti entrano con
     * la credenziale del docente. Nello scenario 3, dove esistono account
     * studente, si rimanda al login normale.
     */
    /** POST /accesso-classe/esci — chiude il grant di classe e torna alla home. */
    public function classAccessLogout(Request $req): Response
    {
        Session::put(\App\Support\ClassAccessGrant::SESSION_KEY, null);
        return Response::redirect('/', 303);
    }

    public function showClassAccess(Request $req): Response
    {
        if (\App\Support\DeploymentScenario::studentAccountsEnabled()) {
            return Response::redirect('/login', 302);
        }
        $view = View::default();
        $body = $view->render('auth/class_access', [
            'csrf'     => Csrf::token(),
            'scenario' => \App\Support\DeploymentScenario::snapshot(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Accesso per la classe — Pantedu',
            'body'  => $body,
            'modal' => true,
        ]));
    }
}
