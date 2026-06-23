<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase 25.R follow-up — Grafana auth gate.
 *
 * Endpoint usato come `auth_request` da nginx per gating del path /grafana/.
 * Ritorna:
 *   - 200 + header X-Grafana-User=<username> se l'utente è super_admin loggato
 *   - 401 in tutti gli altri casi
 *
 * Nginx usa l'header X-Grafana-User per popolare X-WEBAUTH-USER verso
 * Grafana (auth.proxy in grafana.ini), così l'utente pantedu super_admin
 * è automaticamente loggato anche in Grafana (SSO via session pantedu).
 *
 * Sicurezza:
 *   - Endpoint volutamente SENZA Response::redirect: deve rispondere status
 *     code stretto (200/401) per il flow nginx auth_request.
 *   - Body vuoto (nginx non lo usa).
 *   - Auth check stretto: ENTRAMBI Auth::check() AND Auth::isSuperAdmin().
 *   - Nessuna informazione sensibile in response (nemmeno username in 401).
 */
final class GrafanaGateController
{
    /** GET /auth/grafana-gate */
    public function gate(Request $req): Response
    {
        if (!Auth::check() || !Auth::isSuperAdmin()) {
            return new Response('', 401, [
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $username = (string)(Auth::user()['username'] ?? '');
        return new Response('', 200, [
            'Cache-Control' => 'no-store',
            'X-Grafana-User' => $username,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
