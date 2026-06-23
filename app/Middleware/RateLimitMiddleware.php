<?php

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\RateLimitStore;

/**
 * Sliding-window rate limiter for authenticated write endpoints.
 *
 * Soglie per-role (richieste / window):
 *   - admin:       120 req / 60 s
 *   - teacher:      60 req / 60 s
 *   - collaborator: 30 req / 60 s
 *   - altri:        15 req / 60 s
 *
 * Phase 19 — Storage tramite RateLimitStore (backend DB o session,
 * auto-selected via env RATE_LIMIT_BACKEND). DB consente monitoring
 * + revocabilità cross-session.
 *
 * Sforamento: HTTP 429 + JSON {"error":"rate limit exceeded","retry_after":N}.
 */
final class RateLimitMiddleware
{
    private const WINDOW_SECONDS = 60;

    private const LIMITS = [
        'administrator' => 120,
        'admin'         => 120,
        'teacher'       => 60,
        'collaborator'  => 30,
        'student'       => 15,
        'guest'         => 15,
    ];

    public function __construct(
        private readonly RateLimitStore $store = new RateLimitStore(),
    ) {
    }

    /**
     * Phase 25.B5 — supporta override per-route via syntax:
     *   ->middleware('rate:login,10')        // bucket=login, limit=10/min
     *   ->middleware('rate:instances,30')    // bucket=instances, limit=30/min
     *
     * Senza parametri = comportamento legacy (per-role globale, write:*).
     *
     * Bucket suffix è chiave-route (es. "login", "instances"), così endpoint
     * sensibili hanno il loro counter dedicato e NON vengono "protetti" dal
     * counter generico (un teacher che fa 60 mappe non si auto-blocca dalle
     * 30 instances/min).
     */
    public function handle(Request $req, callable $next, ?string $bucketKey = null, ?string $limitOverride = null): Response
    {
        if (!\in_array($req->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($req);
        }

        // Phase 25.B5 — bypass env-based per test/dev environment.
        // Production: var unset → middleware attivo. CI/local test: set
        // RATE_LIMIT_DISABLED=1 nel .env per evitare cascade failures
        // dovuti al counter accumulato tra test (non bug funzionale).
        // NB: usa $_ENV (popolato da Dotenv::createImmutable()->safeLoad()),
        // NON getenv() che è OS-level (non popolato da safeLoad).
        if (($_ENV['RATE_LIMIT_DISABLED'] ?? '') === '1') {
            return $next($req);
        }

        $user     = Auth::user();
        $username = $user['username'] ?? null;
        $clientIp = $this->clientIp($req) ?? 'unknown';

        // Phase 25.B5 — bucket scoped: bucketKey distingue endpoint sensibili
        // (login/instances/content/etc.) da bucket generico "write:*".
        // Per login (no auth ancora): per-IP. Per altri: per-username.
        $scope = $bucketKey ?? 'write';
        $bucket = $username !== null
            ? "{$scope}:{$username}"
            : "{$scope}:ip:{$clientIp}";

        // Limit: se override esplicito, usa quello. Altrimenti legacy per-role.
        if ($limitOverride !== null && ctype_digit($limitOverride)) {
            $limit = (int)$limitOverride;
        } else {
            $role  = (string)($user['role'] ?? 'guest');
            $limit = self::LIMITS[$role] ?? self::LIMITS['guest'];
        }

        $hits = $this->store->hits($bucket, self::WINDOW_SECONDS);
        if (\count($hits) >= $limit) {
            $oldest = (int)$hits[0];
            $retry  = \max(1, ($oldest + self::WINDOW_SECONDS) - \time());
            return Response::json([
                'error'       => 'rate limit exceeded',
                'retry_after' => $retry,
                'bucket'      => $scope,
                'limit'       => $limit,
            ], 429);
        }

        $this->store->append($bucket, $clientIp);
        return $next($req);
    }

    /**
     * IP client per il bucket rate-limit. Audit Phase 25.R.31 (HIGH): prima
     * usava HTTP_X_FORWARDED_FOR/HTTP_CLIENT_IP GREZZI → un client poteva
     * iniettare un valore arbitrario e ruotare il bucket all'infinito,
     * annullando la difesa anti-brute-force su /login. Ora valida ogni hop
     * (FILTER_VALIDATE_IP). Audit 2026-06-01: delega a EdgeContext, che si
     * fida degli header di forwarding SOLO se la connessione arriva da un proxy
     * fidato (range Cloudflare). Dietro Cloudflare questo è essenziale: senza,
     * REMOTE_ADDR sarebbe l'IP di Cloudflare e il bucket login risulterebbe
     * GLOBALE (tutti gli utenti condividono pochi IP CF) anziché per-utente.
     */
    private function clientIp(Request $req): ?string
    {
        $ip = \App\Services\Waf\EdgeContext::clientIp($req->server ?? []);
        return ($ip !== '' && $ip !== '0.0.0.0') ? $ip : null;
    }
}
