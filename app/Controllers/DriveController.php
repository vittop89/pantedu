<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\DriveOAuthRepository;
use App\Services\Drive\DriveClient;
use Throwable;

/**
 * Phase G1.a — Google Drive OAuth flow + status endpoint.
 *
 * Endpoint:
 *   GET  /teacher/drive/connect     → redirect 302 a Google consent screen.
 *   GET  /teacher/drive/callback    → riceve code, scambia token, salva DB.
 *   POST /teacher/drive/disconnect  → DELETE row teacher_drive_oauth (CSRF).
 *   GET  /teacher/drive/status.json → JSON {connected, email, last_sync_at}.
 *
 * Tutti dietro middleware auth+role:teacher (registrati in routes/web.php).
 *
 * Sicurezza:
 *   - state = nonce CSRF in sessione, verificato in callback (anti-CSRF
 *     OAuth + anti-replay code di un altro utente).
 *   - refresh_token MAI loggato: salvato cifrato via TeacherCryptoService
 *     dentro DriveOAuthRepository::upsert.
 *   - exception details non esposte nel body 4xx (solo error code stringa
 *     stabile, dettagli in storage/logs/php_errors.log).
 */
final class DriveController
{
    private DriveClient $client;
    private DriveOAuthRepository $repo;

    public function __construct(
        ?DriveClient $client = null,
        ?DriveOAuthRepository $repo = null
    ) {
        $this->client = $client ?? new DriveClient();
        $this->repo   = $repo   ?? new DriveOAuthRepository();
    }

    /** GET /teacher/drive/connect → redirect consent. */
    public function connect(Request $req): Response
    {
        return $this->connectWith('default');
    }

    /**
     * Phase G6 — GET /teacher/drive/connect-migration → redirect consent
     * con scope `drive.readonly` aggiunto al default. Necessario per
     * scaricare i `.drawio` legacy gia' su Drive del docente (drive.file
     * non li vede perche' non sono creati dall'app). Post-migrazione
     * tornare a /teacher/drive/connect (default) per declassare.
     */
    public function connectMigration(Request $req): Response
    {
        return $this->connectWith('migration');
    }

    private function connectWith(string $scopeProfile): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::json(['ok' => false, 'error' => 'teacher_not_found'], 403);
        }

        $state = bin2hex(random_bytes(16));
        Session::put('drive_oauth_state', $state);
        Session::put('drive_oauth_state_at', time());

        // Scope unione: default sempre, migration aggiunge drive.readonly
        // (utile per download legacy). Il refresh_token persisted con
        // scope esteso continua a funzionare per drive.file post-migrazione.
        $scopes = array_values(array_unique(array_merge(
            (array)Config::get('drive.scopes.default', []),
            $scopeProfile === 'migration'
                ? (array)Config::get('drive.scopes.migration', [])
                : []
        )));

        try {
            $url = $this->client->buildAuthUrl($state, $scopes);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'error' => 'drive_config_error'], 500);
        }

        return Response::redirect($url);
    }

    /** GET /teacher/drive/callback?code=...&state=... */
    public function callback(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::json(['ok' => false, 'error' => 'teacher_not_found'], 403);
        }

        $expected = (string)Session::get('drive_oauth_state', '');
        $received = (string)($req->query['state'] ?? '');
        Session::forget('drive_oauth_state');
        Session::forget('drive_oauth_state_at');

        if ($expected === '' || !hash_equals($expected, $received)) {
            return Response::json(['ok' => false, 'error' => 'oauth_state_mismatch'], 400);
        }

        // Errore lato Google (utente ha negato consent, ecc.).
        if (\array_key_exists('error', $req->query)) {
            return Response::redirect('/area-docente/dashboard?drive=denied');
        }

        $code = (string)($req->query['code'] ?? '');
        if ($code === '') {
            return Response::json(['ok' => false, 'error' => 'oauth_code_missing'], 400);
        }

        try {
            $tokens = $this->client->exchangeCode($code);
            $email  = $this->client->fetchUserEmail($tokens['access_token']);

            if ($tokens['refresh_token'] !== '') {
                // Caso normale: nuovo refresh_token, upsert completo.
                $this->repo->upsert(
                    $teacherId,
                    $tokens['refresh_token'],
                    $tokens['scope'],
                    $email,
                    null
                );
            } else {
                // Phase G6 — Google non emette refresh_token su re-consent
                // (utente gia' approvato). Se la row esiste in DB, il vecchio
                // refresh_token e' ancora valido + Google ha esteso lo scope
                // (drive.file → drive.readonly aggiunto) sul token internamente.
                // Aggiorniamo SOLO scope+email, preservando il ciphertext.
                if (!$this->repo->isConnected($teacherId)) {
                    error_log('DriveController.callback: drive_oauth_no_refresh_token + no existing row');
                    return Response::redirect('/area-docente/dashboard?drive=error');
                }
                $this->repo->updateScopeOnly($teacherId, $tokens['scope'], $email);
            }
        } catch (Throwable $e) {
            error_log('DriveController.callback: ' . $e->getMessage());
            return Response::redirect('/area-docente/dashboard?drive=error');
        }

        return Response::redirect('/area-docente/dashboard?drive=connected');
    }

    /** POST /teacher/drive/disconnect (csrf middleware). */
    public function disconnect(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::json(['ok' => false, 'error' => 'teacher_not_found'], 403);
        }

        $this->repo->delete($teacherId);
        return Response::json(['ok' => true]);
    }

    /** GET /teacher/drive/status.json — UI pill. */
    public function status(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::json(['ok' => false, 'error' => 'teacher_not_found'], 403);
        }

        $meta = $this->repo->getMetadata($teacherId);
        if ($meta === null) {
            return Response::json([
                'ok'        => true,
                'connected' => false,
            ]);
        }
        return Response::json([
            'ok'           => true,
            'connected'    => true,
            'email'        => $meta['email'],
            'scope'        => $meta['scope'],
            'connected_at' => $meta['connected_at'],
            'last_sync_at' => $meta['last_sync_at'],
        ]);
    }

    /**
     * Risolve user_id dal username in sessione. Restituisce null se DB
     * disabilitato o user inesistente (caso degenere: sessione viva senza
     * row in users).
     */
    private function resolveTeacherId(): ?int
    {
        $username = (string)(Auth::user()['username'] ?? '');
        if ($username === '') {
            return null;
        }
        $id = \App\Support\TeacherContextResolver::userIdFromUsername($username);
        return $id > 0 ? $id : null;
    }
}
