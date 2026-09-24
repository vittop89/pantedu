<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Repositories\DriveOAuthRepository;
use App\Services\Drive\DriveClient;
use App\Services\Drive\StatoDiDrive;
use Throwable;

/**
 * Phase G1.a — Google Drive OAuth flow + status endpoint.
 *
 * Endpoint:
 *   GET  /teacher/drive/connect     → redirect 302 a Google consent screen.
 *   GET  /teacher/drive/callback    → riceve code, scambia token, salva DB.
 *   POST /teacher/drive/disconnect  → DELETE row teacher_drive_oauth (CSRF).
 *   GET  /teacher/drive/status.json → JSON {istanza, connected, email, stato, …}.
 *
 * Tutti dietro middleware auth+role:teacher (registrati in routes/web.php).
 *
 * Dal 14/9/2026 (ADR-038):
 *   - con Drive spento o guasto sull'installazione collegarsi non parte: si
 *     torna al cruscotto con `drive=non_disponibile`, invece di un 500;
 *   - scollegarsi resta sempre possibile, in ogni stato: l'informativa lo
 *     promette «in qualsiasi momento»;
 *   - un consenso senza il permesso su Drive non si salva (`drive=permessi`);
 *   - lo stato risponde anche con lo stato dell'installazione e con quello
 *     del collegamento (attivo o da ricollegare, il motivo, da quando).
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
            return Response::fail('teacher_not_found', 403);
        }
        if (StatoDiDrive::attuale() !== StatoDiDrive::ACCESO) {
            return Response::redirect('/area-docente/dashboard?drive=non_disponibile');
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
            return Response::fail('drive_config_error', 500);
        }

        return Response::redirect($url);
    }

    /** GET /teacher/drive/callback?code=...&state=... */
    public function callback(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::fail('teacher_not_found', 403);
        }

        $expected = (string)Session::get('drive_oauth_state', '');
        $received = (string)($req->query['state'] ?? '');
        Session::forget('drive_oauth_state');
        Session::forget('drive_oauth_state_at');

        if ($expected === '' || !hash_equals($expected, $received)) {
            return Response::fail('oauth_state_mismatch', 400);
        }

        // Errore lato Google (utente ha negato consent, ecc.).
        if (\array_key_exists('error', $req->query)) {
            return Response::redirect('/area-docente/dashboard?drive=denied');
        }
        if (StatoDiDrive::attuale() !== StatoDiDrive::ACCESO) {
            return Response::redirect('/area-docente/dashboard?drive=non_disponibile');
        }

        $code = (string)($req->query['code'] ?? '');
        if ($code === '') {
            return Response::fail('oauth_code_missing', 400);
        }

        try {
            $tokens = $this->client->exchangeCode($code);

            // Senza il permesso su Drive il collegamento non servirebbe a niente:
            // non si salva, e il docente sa perché. Prima di chiedere l'email,
            // che è una chiamata in più a Google.
            if (!StatoDiDrive::permessoConcesso($tokens['scope'])) {
                return Response::redirect('/area-docente/dashboard?drive=permessi');
            }

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

    /** POST /teacher/drive/disconnect (csrf middleware). In ogni stato di Drive. */
    public function disconnect(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::fail('teacher_not_found', 403);
        }

        $this->repo->delete($teacherId);
        return Response::ok();
    }

    /** GET /teacher/drive/status.json — UI pill. */
    public function status(Request $req): Response
    {
        $teacherId = $this->resolveTeacherId();
        if ($teacherId === null) {
            return Response::fail('teacher_not_found', 403);
        }

        $istanza = StatoDiDrive::attuale();
        $meta = $this->repo->getMetadata($teacherId);
        if ($meta === null) {
            return Response::json([
                'ok'        => true,
                'istanza'   => $istanza,
                'connected' => false,
            ]);
        }
        return Response::json([
            'ok'           => true,
            'istanza'      => $istanza,
            'connected'    => true,
            'email'        => $meta['email'],
            'scope'        => $meta['scope'],
            'connected_at' => $meta['connected_at'],
            'last_sync_at' => $meta['last_sync_at'],
            'stato'        => $meta['stato'],
            'motivo'       => $meta['stato_motivo'],
            'dal'          => $meta['stato_dal'],
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
