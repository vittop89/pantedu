<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Security\PasswordPolicy;
use RuntimeException;
use Throwable;

/**
 * Phase 14 — profilo utente: change password self-service.
 *
 *   GET  /me/change-password
 *   POST /me/change-password   (csrf + rate)
 *
 * Ogni utente autenticato può cambiare la propria password. Verifica:
 *   - password attuale corretta (via bcrypt verify sul password_hash)
 *   - nuova password ≥ 8 e ≤ 4096 char
 *   - conferma = nuova
 * Aggiorna password_hash in DB (primary) + users.json (se presente).
 */
final class UserProfileController
{
    public function showChangePassword(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::redirect('/login');
        }

        $view = View::default();
        $body = $view->render('profile/change_password', [
            'csrf'         => Csrf::token(),
            'errorMessage' => $this->errorMessage((string)($req->query['error'] ?? '')),
            'done'         => isset($req->query['ok']),
            'user'         => Auth::user(),
        ]);
        return Response::html($view->render('layout/shell', [
            'title' => 'Cambia password — Pantedu',
            'body'  => $body,
            // 2026-09-01 — non e' un modale: `fm-shell--modal` stende un velo
            // scuro sulla pagina, come se dietro ci fosse dell'altro da cui
            // l'utente e' stato interrotto. Qui e' una pagina di impostazioni,
            // raggiunta di proposito dal profilo.
            'modal' => false,
        ]));
    }

    public function changePassword(Request $req): Response
    {
        if (!Auth::check()) {
            return Response::json(['error' => 'unauthorized'], 401);
        }

        try {
            $current = (string)($req->post['current_password'] ?? '');
            $new     = (string)($req->post['new_password']     ?? '');
            $confirm = (string)($req->post['confirm_password'] ?? '');

            if ($current === '') {
                throw new RuntimeException('current_required');
            }
            // Lunghezza, coincidenza e Have I Been Pwned: regole condivise con
            // il recupero password via email (2026-09-01). Vivevano qui, ma
            // due punti che scrivono la stessa colonna non possono avere due
            // soglie diverse — e la piu' debole diventerebbe quella vera.
            PasswordPolicy::validate($new, $confirm, $current);

            $username = (string)(Auth::user()['username'] ?? '');
            if ($username === '') {
                throw new RuntimeException('no_user');
            }

            $hash = $this->currentHash($username);
            if ($hash === null || !password_verify($current, $hash)) {
                throw new RuntimeException('wrong_current');
            }

            $newHash = PasswordPolicy::hash($new);
            $this->persistHash($username, $newHash);
            // Audit 25.R.31 (L7) — cambio completato: azzera l'obbligo (DB + sessione).
            try {
                \App\Core\Database::connection()
                    ->prepare('UPDATE users SET must_change_password=0 WHERE username=?')
                    ->execute([$username]);
            } catch (\Throwable $e) {
/* best-effort */
            }
            \App\Core\Session::forget('must_change_password');
            Csrf::rotate();

            return Response::redirect('/me/change-password?ok=1');
        } catch (Throwable $e) {
            return Response::redirect('/me/change-password?error=' . urlencode($e->getMessage()));
        }
    }

    // ───────────── helpers ─────────────

    private function currentHash(string $username): ?string
    {
        if (Database::isAvailable()) {
            $stmt = Database::connection()->prepare('SELECT password_hash FROM users WHERE username = ? LIMIT 1');
            $stmt->execute([$username]);
            $h = $stmt->fetchColumn();
            if ($h !== false && $h !== '') {
                return (string)$h;
            }
        }
        $path = (string)Config::get('auth.paths.registered_users', '');
        if ($path !== '' && is_file($path)) {
            $j = json_decode((string)file_get_contents($path), true) ?: [];
            foreach ($j['users'] ?? [] as $u) {
                if (($u['username'] ?? '') === $username) {
                    return (string)($u['password_hash'] ?? '');
                }
            }
        }
        return null;
    }

    private function persistHash(string $username, string $newHash): void
    {
        $wrote = false;
        if (Database::isAvailable()) {
            $stmt = Database::connection()->prepare('UPDATE users SET password_hash = ? WHERE username = ?');
            $stmt->execute([$newHash, $username]);
            if ($stmt->rowCount() > 0) {
                $wrote = true;
            }
        }
        // Aggiorna JSON (se l'utente vive anche lì) per consistenza dual-store
        $path = (string)Config::get('auth.paths.registered_users', '');
        if ($path !== '' && is_file($path)) {
            $j = json_decode((string)file_get_contents($path), true) ?: ['users' => []];
            $changed = false;
            foreach (($j['users'] ?? []) as $i => $u) {
                if (($u['username'] ?? '') === $username) {
                    $j['users'][$i]['password_hash'] = $newHash;
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $tmp = $path . '.tmp';
                file_put_contents($tmp, json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
                @rename($tmp, $path);
                $wrote = true;
            }
        }
        if (!$wrote) {
            throw new RuntimeException('persist_failed');
        }
    }

    private function errorMessage(string $code): ?string
    {
        if ($code === '') {
            return null;
        }
        // Special case: pwned_password:N → password compromessa
        if (str_starts_with($code, 'pwned_password:')) {
            $n = (int)substr($code, strlen('pwned_password:'));
            return sprintf(
                '⚠️ Questa password è apparsa in %s data breach pubblici (HaveIBeenPwned). '
                . 'Scegli una password diversa, preferibilmente generata casualmente.',
                number_format($n, 0, ',', '.')
            );
        }
        return match ($code) {
            'current_required' => 'Inserisci la password attuale.',
            'new_too_short'    => 'La nuova password deve essere di almeno 8 caratteri.',
            'new_too_long'     => 'Password troppo lunga.',
            'mismatch'         => 'La conferma non corrisponde.',
            'same_as_old'      => 'La nuova password deve essere diversa da quella attuale.',
            'wrong_current'    => 'Password attuale errata.',
            'no_user'          => 'Sessione scaduta. Riaccedi.',
            default            => 'Errore: ' . $code,
        };
    }
}
