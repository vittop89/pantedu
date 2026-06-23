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
use App\Services\Security\TotpService;
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
            'master_enabled' => Config::get('security.totp_enabled', false),
            'pending'        => $_SESSION['totp_pending'] ?? null,
            'flash'          => $_SESSION['totp_flash'] ?? null,
        ]);
        unset($_SESSION['totp_flash']);
        return Response::html($view->render('layout/shell', [
            'title' => '2FA — Pantedu',
            'body'  => $body,
            'modal' => true,
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
                'UPDATE users
                 SET totp_secret = ?, totp_enabled = 1,
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
        $_SESSION['totp_flash'] = ['type' => 'ok', 'msg' => '2FA attivato. Conserva i backup codes offline!'];
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
