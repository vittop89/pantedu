<?php

declare(strict_types=1);

namespace App\Services\Drive;

use App\Core\Config;
use App\Repositories\DriveOAuthRepository;
use GuzzleHttp\Client as GuzzleClient;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDriveService;
use RuntimeException;

/**
 * Phase G1.a — Wrapper Google API client per Drive integration.
 *
 * Responsabilita':
 *   - Build di un Google\Client configurato con le nostre credenziali
 *     OAuth + redirect URI (G1.a: OAuth flow only, NO Drive API calls).
 *   - Generare l'URL di consent (createAuthUrl) con scope iniziale e
 *     "access_type=offline" + "prompt=consent" (per garantire emissione
 *     refresh_token anche su re-connect).
 *   - Scambiare authorization_code → access_token + refresh_token.
 *   - Recuperare access_token a runtime per un teacher gia' connesso
 *     (refresh dal refresh_token salvato in DB).
 *   - Esporre Google\Service\Drive autenticato (per fasi G2-G6).
 *
 * Le chiamate Drive API specifiche (folder create, upload, etc.) vivranno
 * in service dedicati (FolderTreeBuilder, MapSyncService) che ricevono
 * l'istanza Drive da getDriveFor($teacherId).
 *
 * Configurazione runtime: app/Config/drive.php legge .env (.env.local per
 * client_secret). Vedi ADR-009 e .env.example per il setup Cloud Console.
 */
final class DriveClient
{
    private DriveOAuthRepository $repo;

    public function __construct(?DriveOAuthRepository $repo = null)
    {
        $this->repo = $repo ?? new DriveOAuthRepository();
    }

    /**
     * Genera l'URL di consent OAuth. Lo state e' un nonce CSRF firmato dal
     * caller (DriveController) e poi verificato in callback().
     *
     * @param string[] $scopes
     */
    public function buildAuthUrl(string $state, array $scopes): string
    {
        $client = $this->newGoogleClient();
        $client->setScopes($scopes);
        $client->setAccessType('offline');
        // prompt=consent forza Google a emettere un nuovo refresh_token
        // anche se l'utente aveva gia' approvato l'app (essenziale per
        // recuperare un refresh_token su re-connect dopo disconnect).
        $client->setApprovalPrompt('force');
        $client->setState($state);

        return $client->createAuthUrl();
    }

    /**
     * Scambia il code OAuth ricevuto in callback per access_token +
     * refresh_token. RuntimeException se il code e' invalido / scaduto
     * o se Google non emette refresh_token (utente ha gia' approvato +
     * abbiamo dimenticato prompt=consent → bug nostro, throw).
     *
     * @return array{access_token: string, refresh_token: string, scope: string, expires_in: int}
     */
    public function exchangeCode(string $code): array
    {
        $client = $this->newGoogleClient();
        /** @var array<string,mixed>|string $token */
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (is_string($token) || !is_array($token) || isset($token['error'])) {
            throw new RuntimeException('drive_oauth_exchange_failed');
        }

        // Phase G6 — Google NON rilascia refresh_token su re-consent se
        // l'utente ha gia' approvato l'app (caso scope upgrade migration).
        // refresh_token puo' essere null/missing in questo caso. Il caller
        // (DriveController::callback) decide se considerarlo errore o
        // aggiornare solo lo scope sulla riga esistente.
        return [
            'access_token'  => (string)$token['access_token'],
            'refresh_token' => isset($token['refresh_token']) ? (string)$token['refresh_token'] : '',
            'scope'         => (string)($token['scope'] ?? ''),
            'expires_in'    => (int)($token['expires_in'] ?? 3600),
        ];
    }

    /**
     * Recupera l'email del Google account collegato dato un access_token.
     * Usa l'endpoint userinfo (incluso negli scope OAuth standard di
     * apiclient quando si richiede 'openid email'). G1.a: opzionale, se
     * fallisce ritorna null (non blocca la connessione).
     */
    public function fetchUserEmail(string $accessToken): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents(
            'https://www.googleapis.com/oauth2/v2/userinfo',
            false,
            $ctx
        );
        if ($body === false) {
            return null;
        }
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['email'])) {
            return null;
        }
        return (string)$data['email'];
    }

    /**
     * Restituisce un Google\Service\Drive autenticato per il teacher dato.
     * Esegue il refresh dell'access_token da refresh_token in DB. Cached
     * per la durata della request (1 client riuso intra-request, ricreato
     * a request successiva — semplice e sicuro).
     *
     * G1.a: implementato come stub (ritorna istanza ma senza chiamate),
     * usato pienamente da G2 in poi.
     */
    public function getDriveFor(int $teacherId): GoogleDriveService
    {
        $refreshToken = $this->repo->getRefreshToken($teacherId);
        if ($refreshToken === null) {
            throw new RuntimeException('drive_oauth_not_connected');
        }

        $client = $this->newGoogleClient();
        $client->refreshToken($refreshToken);

        return new GoogleDriveService($client);
    }

    private function newGoogleClient(): GoogleClient
    {
        $clientId     = (string)Config::get('drive.oauth.client_id', '');
        $clientSecret = (string)Config::get('drive.oauth.client_secret', '');
        if ($clientId === '' || $clientSecret === '') {
            throw new RuntimeException('drive_oauth_credentials_missing');
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($this->resolveRedirectUri());

        // Windows + XAMPP: php.ini curl.cainfo puo' puntare a un bundle
        // obsoleto (cert Mozilla Apr 2022 senza le CA roots Google moderne).
        // Forziamo Guzzle a usare il bundle aggiornato configurato in
        // Config::get('drive.ca_bundle') (default: il file rinfrescato in
        // C:\xampp\apache\bin\curl-ca-bundle.crt). Se la path non esiste,
        // ricadiamo sul default Guzzle (cURL system CA).
        $caBundle = (string)Config::get('drive.ca_bundle', '');
        $verify   = ($caBundle !== '' && is_file($caBundle)) ? $caBundle : true;
        $client->setHttpClient(new GuzzleClient([
            'verify'  => $verify,
            'timeout' => 30,
        ]));

        return $client;
    }

    private function resolveRedirectUri(): string
    {
        $configured = (string)Config::get('drive.oauth.redirect_uri', '');
        if ($configured !== '') {
            return $configured;
        }
        $appUrl = rtrim((string)Config::get('app.url', ''), '/');
        if ($appUrl === '') {
            throw new RuntimeException('drive_oauth_redirect_uri_unresolved');
        }
        return $appUrl . '/teacher/drive/callback';
    }
}
