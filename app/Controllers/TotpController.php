<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Security\EmailSecondFactor;
use App\Services\Security\QrCode;
use App\Services\Security\TotpService;
use App\Services\Security\TwoFactorPolicy;
use PDO;
use Throwable;

/**
 * Phase 25.J.4 — 2FA TOTP self-service.
 *
 * Routes:
 *   GET  /me/2fa            → status + setup wizard
 *   POST /me/2fa/setup      → genera nuovo secret + backup codes (in sessione, non DB)
 *   POST /me/2fa/enable     → verifica codice + persiste secret in DB → totp_enabled=1
 *   POST /me/2fa/disable    → conferma password + totp_enabled=0
 *   POST /me/2fa/regenerate-backup → nuovi backup codes
 *
 * Flow setup:
 *   1. User GET /me/2fa → vede status (off/on) + "Configura" button
 *   2. Click → POST /me/2fa/setup → genera secret + backup codes,
 *      salvati in $_SESSION (NON in DB). Mostra QR code + codes.
 *   3. User scan QR con Authenticator → ottiene codice 6 cifre.
 *   4. User invia codice → POST /me/2fa/enable → se valido,
 *      copia secret+backups da SESSION a DB, totp_enabled=1. ELSE retry.
 *   5. Da ora in poi, login richiede password + codice TOTP.
 *
 * Disabling: richiede current password (no codice TOTP per ridurre lock-out
 * se user perde phone + non ha più backup codes).
 *
 * DISABLED di default: config 'security.totp_enabled' (env SECURITY_TOTP_ENABLED).
 * Quando OFF: GET /me/2fa mostra "Funzionalità non attiva". Utenti già
 * enrolled possono comunque accedere (il codice viene VERIFICATO al login
 * sempre per chi ha totp_enabled=1, indipendentemente dal master toggle).
 */
final class TotpController
{
    public function page(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }
        $user = Auth::user() ?? [];
        $row  = $this->loadUserTotp((string)($user['username'] ?? ''));

        $view = View::default();
        $body = $view->render('profile/totp', [
            'csrf'           => Csrf::token(),
            'user'           => $user,
            'totp_enabled'   => (bool)($row['totp_enabled'] ?? false),
            'enrolled_at'    => $row['totp_enrolled_at'] ?? null,
            // Non piu' 'master_enabled': quel flag governa l'OBBLIGO, non la
            // verifica. Il testo che ne derivava diceva che il controllo al
            // login "partira' solo quando l'admin attiva il toggle" — falso da
            // quando AuthController chiede il codice a chiunque l'abbia
            // attivata, e falso in modo pericoloso: scoraggiava dall'usarla.
            'required'       => (new TwoFactorPolicy())->requiredForRole((string)($user['role'] ?? '')),
            'metodo'         => (new TwoFactorPolicy())->methodFor((string)($user['username'] ?? '')),
            'email_pending'  => !empty($_SESSION['totp_email_pending']),
            'indirizzo'      => (new EmailSecondFactor())->maskedAddress((string)($user['username'] ?? '')),
            'pending'        => $_SESSION['totp_pending'] ?? null,
            'qr_svg'         => isset($_SESSION['totp_pending']['uri'])
                ? QrCode::svg((string)$_SESSION['totp_pending']['uri'])
                : null,
            'flash'          => $_SESSION['totp_flash'] ?? null,
        ]);
        unset($_SESSION['totp_flash']);
        return Response::html($view->render('layout/shell', [
            'title' => '2FA — Pantedu',
            'body'  => $body,
            // 2026-09-01 — non e' un modale: `fm-shell--modal` stende un velo
            // scuro sulla pagina, come se dietro ci fosse dell'altro da cui
            // l'utente e' stato interrotto. Qui e' una pagina di impostazioni,
            // raggiunta di proposito dal profilo.
            'modal' => false,
        ]));
    }

    public function setup(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $svc = new TotpService();
        $secret  = $svc->generateSecret();
        $backups = $svc->generateBackupCodes(10);
        $user    = Auth::user() ?? [];
        $_SESSION['totp_pending'] = [
            'secret'  => $secret,
            'backups' => $backups,
            'uri'     => $svc->provisioningUri($secret, (string)($user['username'] ?? 'user'), 'Pantedu'),
        ];
        return Response::redirect('/me/2fa');
    }

    public function enable(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $pending = $_SESSION['totp_pending'] ?? null;
        if (!is_array($pending) || empty($pending['secret'])) {
            return Response::redirect('/me/2fa?error=no_pending');
        }
        $code = trim((string)($req->post['code'] ?? ''));
        $svc  = new TotpService();
        if (!$svc->verifyCode($pending['secret'], $code)) {
            $_SESSION['totp_flash'] = ['type' => 'error', 'msg' => 'Codice errato. Riprova.'];
            return Response::redirect('/me/2fa');
        }
        // Persiste secret + backup codes (hashed) in DB
        $hashedBackups = $svc->hashBackupCodes($pending['backups']);
        try {
            $stmt = Database::connection()->prepare(
                // two_factor_method va scritto anche qui, non solo nel percorso
                // email. Senza, chi passasse da email ad app resterebbe con
                // metodo='email' e al login si vedrebbe spedire un codice per
                // posta mentre digita quello dell'app: un blocco silenzioso.
                'UPDATE users
                 SET totp_secret = ?, totp_enabled = 1,
                     two_factor_method = "app",
                     totp_backup_codes = ?,
                     totp_enrolled_at = NOW()
                 WHERE username = ?'
            );
            $stmt->execute([
                $pending['secret'],
                json_encode($hashedBackups, JSON_UNESCAPED_SLASHES),
                (string)(Auth::user()['username'] ?? ''),
            ]);
        } catch (Throwable $e) {
            $_SESSION['totp_flash'] = ['type' => 'error', 'msg' => 'Errore DB: ' . $e->getMessage()];
            return Response::redirect('/me/2fa');
        }
        unset($_SESSION['totp_pending']);
        // Iscrizione completata: se il ruolo la imponeva, il confinamento
        // applicato da AuthMiddleware non ha piu' ragione d'essere.
        unset($_SESSION['must_enrol_2fa']);
        $_SESSION['totp_flash'] = ['type' => 'ok', 'msg' => '2FA attivato. Conserva i backup codes offline!'];
        return Response::redirect('/me/2fa');
    }

    /**
     * POST /me/2fa/setup-email — manda un codice di prova alla casella.
     *
     * L'iscrizione via email non ha un segreto da consegnare: si verifica che
     * l'utente riceva davvero la posta, e questo e' l'unico modo di saperlo
     * prima di fargli dipendere l'accesso da quella casella.
     */
    public function setupEmail(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $username = (string)(Auth::user()['username'] ?? '');
        $svc = new EmailSecondFactor();

        if ($svc->maskedAddress($username) === null) {
            $_SESSION['totp_flash'] = ['type' => 'error',
                'msg' => 'Sul tuo account non c\'e\' un indirizzo email: non potresti ricevere il codice.'];
            return Response::redirect('/me/2fa');
        }
        if (!$svc->issue($username, 'enrol')) {
            $_SESSION['totp_flash'] = ['type' => 'error',
                'msg' => 'Non sono riuscito a spedire il codice. Riprova fra qualche minuto.'];
            return Response::redirect('/me/2fa');
        }
        $_SESSION['totp_email_pending'] = true;
        return Response::redirect('/me/2fa');
    }

    /** POST /me/2fa/enable-email — conferma il codice ricevuto e attiva. */
    public function enableEmail(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        if (empty($_SESSION['totp_email_pending'])) {
            return Response::redirect('/me/2fa?error=no_pending');
        }
        $username = (string)(Auth::user()['username'] ?? '');
        $code = trim((string)($req->post['code'] ?? ''));

        if (!(new EmailSecondFactor())->verify($username, $code, 'enrol')) {
            $_SESSION['totp_flash'] = ['type' => 'error', 'msg' => 'Codice errato o scaduto. Riprova.'];
            return Response::redirect('/me/2fa');
        }

        // Anche col metodo email si consegnano i codici di backup: se la
        // casella diventa irraggiungibile — password dimenticata del provider,
        // dominio scolastico che cambia — restano l'unica via di rientro.
        $svc     = new TotpService();
        $backups = $svc->generateBackupCodes(10);
        try {
            Database::connection()->prepare(
                'UPDATE users
                    SET totp_enabled = 1, two_factor_method = "email",
                        totp_secret = NULL, totp_backup_codes = ?, totp_enrolled_at = NOW()
                  WHERE username = ?'
            )->execute([json_encode($svc->hashBackupCodes($backups), JSON_UNESCAPED_SLASHES), $username]);
        } catch (Throwable $e) {
            $_SESSION['totp_flash'] = ['type' => 'error', 'msg' => 'Errore DB: ' . $e->getMessage()];
            return Response::redirect('/me/2fa');
        }

        unset($_SESSION['totp_email_pending'], $_SESSION['must_enrol_2fa']);
        $_SESSION['totp_backups_once'] = $backups;
        $_SESSION['totp_flash'] = ['type' => 'ok',
            'msg' => 'Verifica via email attivata. Salva i codici di backup qui sotto: non li rivedrai.'];
        return Response::redirect('/me/2fa');
    }

    public function disable(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $password = (string)($req->post['current_password'] ?? '');
        $username = (string)(Auth::user()['username'] ?? '');
        $hash = $this->loadPasswordHash($username);
        if ($hash === null || !password_verify($password, $hash)) {
            $_SESSION['totp_flash'] = ['type' => 'error', 'msg' => 'Password errata.'];
            return Response::redirect('/me/2fa');
        }
        try {
            $stmt = Database::connection()->prepare(
                'UPDATE users SET totp_secret = NULL, totp_enabled = 0,
                 two_factor_method = NULL,
                 totp_backup_codes = NULL, totp_enrolled_at = NULL
                 WHERE username = ?'
            );
            $stmt->execute([$username]);
        } catch (Throwable) {
        }
        $_SESSION['totp_flash'] = ['type' => 'ok', 'msg' => '2FA disabilitato.'];
        return Response::redirect('/me/2fa');
    }

    /** @return array{totp_enabled:int|bool, totp_enrolled_at:?string}|null */
    private function loadUserTotp(string $username): ?array
    {
        if ($username === '') {
            return null;
        }
        try {
            $stmt = Database::connection()->prepare(
                'SELECT totp_enabled, totp_enrolled_at FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    private function loadPasswordHash(string $username): ?string
    {
        try {
            $stmt = Database::connection()->prepare(
                'SELECT password_hash FROM users WHERE username = ? LIMIT 1'
            );
            $stmt->execute([$username]);
            return (string)$stmt->fetchColumn() ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
