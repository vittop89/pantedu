<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\Gdpr\TosAcceptanceService;
use App\Support\TosEnforcement;
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
 * **DISABILITATO di default.** Si accende quando si attiva Scenario B
 * (estensione ad altri docenti).
 *
 * Configurazione: toggle in /admin/system/deployment (override runtime,
 * loggato in privileged_access_log con motivazione), oppure TOS_ENFORCE
 * in .env come default iniziale. Vedi App\Support\TosEnforcement.
 *
 * Il gate è duro e scatta solo DOPO `effective_from` della versione: il
 * preavviso dei 30 giorni promesso da ToS §8 / AUP §6 è servito prima, in
 * forma di banner non bloccante (views/partials/_legal_notice_banner.php)
 * e di email (tools/legal/notify_policy_update.php).
 *
 * Rotte ESENTI dal check (anche se enforce attivo):
 *   - /tos-acceptance (form e submit)
 *   - /logout
 *   - /login (già pre-auth)
 *   - /api/* endpoint pubblici
 *   - /favicon.ico e static assets
 *
 * Applicato GLOBALMENTE da Kernel::handle() (come il WAF), non route per
 * route: un gate legale che copre "quasi tutte" le rotte non è un gate, e
 * l'elenco delle esenzioni è più corto e più verificabile dell'elenco delle
 * rotte protette. L'alias 'tos' resta registrato in Kernel per i test.
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
        // Senza questo l'utente fermo al muro non può APRIRE i documenti che
        // gli si chiede di accettare: il link lo rimbalzerebbe al muro stesso.
        // Accettazione senza accesso al testo non è consenso valido.
        '/legal/',
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
        // Toggle runtime (override su file) > TOS_ENFORCE in .env > spento.
        // Vedi App\Support\TosEnforcement per il perché non sia solo env.
        if (! TosEnforcement::isEnabled()) {
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
            $userId = (int) (Auth::user()['id'] ?? 0);
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
