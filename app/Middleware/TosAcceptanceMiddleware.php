<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\TosAcceptanceService;
use Throwable;

/**
 * Phase 25.P — Middleware ToS+AUP enforcement per multi-tenancy (Scenario B/C).
 *
 * Verifica che l'utente autenticato abbia accettato la versione corrente
 * di Terms of Service + Acceptable Use Policy prima di accedere a rotte
 * autenticate (escluse le rotte di accept/logout).
 *
 * Se NON accettato → redirect a /tos-acceptance form.
 *
 * **DISABILITATO di default** via flag env `TOS_ENFORCE=false`. Abilitare
 * a:`true` quando si attiva Scenario B (estensione ad altri docenti).
 *
 * Configurazione:
 *   .env: TOS_ENFORCE=true (o config('multitenancy.tos_enforce'))
 *
 * Rotte ESENTI dal check (anche se enforce attivo):
 *   - /tos-acceptance (form e submit)
 *   - /logout
 *   - /login (già pre-auth)
 *   - /api/* endpoint pubblici
 *   - /favicon.ico e static assets
 *
 * Usage in routes/web.php (commentato fino ad attivazione):
 *   $router->any('{path:.*}', $handler)->middleware('tos');
 *
 * Registration in Kernel (TODO quando si attiva):
 *   'tos' => TosAcceptanceMiddleware::class
 */
final class TosAcceptanceMiddleware
{
    /** Path da escludere dal check (sempre accessibili). */
    private const EXEMPT_PATHS = [
        '/tos-acceptance',
        '/tos-acceptance/submit',
        '/logout',
        '/login',
        '/favicon.ico',
    ];

    /** Prefix da escludere. */
    private const EXEMPT_PREFIXES = [
        '/api/public/',
        '/_hooks/',
        '/segnalazione-contenuti',
        '/dpo-contact',
        '/privacy/',
        '/security',
        '/static/',
        '/build/',
        '/css/',
        '/js/',
        '/img/',
    ];

    private TosAcceptanceService $service;

    public function __construct(?TosAcceptanceService $service = null)
    {
        $this->service = $service ?? new TosAcceptanceService();
    }

    public function handle(Request $req, callable $next): Response
    {
        // Feature flag: se disabilitato, passa attraverso
        $enforce = (bool) (Config::get('multitenancy.tos_enforce')
            ?? ($_ENV['TOS_ENFORCE'] ?? 'false') === 'true');
        if (! $enforce) {
            return $next($req);
        }

        // Se non autenticato, lascia gestire a AuthMiddleware
        if (! Auth::check()) {
            return $next($req);
        }

        // Phase 25.R.1.2 — super_admin sempre esente (operatore tecnico
        // del sistema: non deve mai poter restare bloccato dalla propria
        // toggle TOS_ENFORCE; serve a mantenere l'accesso ai pannelli
        // /admin/* per gestire la policy).
        if (Auth::isSuperAdmin()) {
            return $next($req);
        }

        // Path esenti
        $path = $req->server['REQUEST_URI'] ?? '/';
        // strip query string
        if (($qs = strpos($path, '?')) !== false) {
            $path = substr($path, 0, $qs);
        }

        if (in_array($path, self::EXEMPT_PATHS, true)) {
            return $next($req);
        }
        foreach (self::EXEMPT_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $next($req);
            }
        }

        // Check accettazione
        try {
            $userId = (int) Auth::userId();
            if ($userId <= 0) {
                return $next($req);
            }
            if ($this->service->hasAccepted($userId)) {
                return $next($req);
            }
        } catch (Throwable $e) {
            // Errore DB: log + lascia passare (non bloccare app per problema infra)
            error_log('[TosAcceptanceMiddleware] check failed: ' . $e->getMessage());
            return $next($req);
        }

        // Non accettato → redirect (escludi JSON requests)
        if ($req->wantsJson()) {
            return Response::json([
                'error' => 'tos_acceptance_required',
                'redirect' => '/tos-acceptance',
                'tos_version' => $this->service->getCurrentTosVersion(),
                'aup_version' => $this->service->getCurrentAupVersion(),
            ], 403);
        }

        return Response::redirect('/tos-acceptance?redirect=' . urlencode($path));
    }
}
