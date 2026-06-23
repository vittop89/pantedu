<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase D.2 — SPID Service Provider scaffolding.
 *
 * STATUS: scaffolding stub. Tutti i metodi ritornano 503
 * "service_not_configured" finché pantedu non sara' registrato come
 * SPID Service Provider presso AgID (vedi docs/plans/d2-spid-cie-integration.md).
 *
 * Library di riferimento (auditata pre-install separato):
 *   italia/spid-cie-php v3.19.1 (Apache-2.0)
 *
 * Endpoints SAML 2.0 (rispettano le Linee Guida SPID v2.7):
 *   GET  /auth/spid/login    -> AuthnRequest verso IdP scelto
 *   GET  /auth/spid/callback -> ACS (Assertion Consumer Service)
 *   GET  /auth/spid/metadata -> SP metadata XML
 *   GET  /auth/spid/logout   -> SLO (Single Logout) initiated by SP
 *
 * Sicurezza:
 *   - X.509 cert + key in /etc/pantedu/spid/ (perm 0600, owner root)
 *     mai committati nel repo
 *   - AuthnRequest firmata + verifica firma Assertion da IdP
 *   - Replay protection via InResponseTo + ID univoco per request
 *   - Audit log via App\Services\AccessLogger (best-effort)
 */
final class SpidController
{
    public function login(Request $req): Response
    {
        if (!$this->isEnabled()) {
            return $this->notConfigured('login');
        }
        // TODO D.2.3: implement via italia/spid-cie-php library.
        // 1. Validate $req->query['idp'] against allowed IdP list
        // 2. Build AuthnRequest with requested attributes from config
        // 3. Sign request with SP private key
        // 4. Redirect to IdP SSO URL with SAMLRequest + RelayState
        return $this->notConfigured('login (D.2.3)');
    }

    public function callback(Request $req): Response
    {
        if (!$this->isEnabled()) {
            return $this->notConfigured('callback');
        }
        // TODO D.2.3:
        // 1. Verify SAMLResponse signature
        // 2. Validate InResponseTo matches stored request ID
        // 3. Extract attributes (name, familyName, fiscalNumber, etc.)
        // 4. Link or create user via SpidIdentityService
        // 5. Start session (regenerate ID, set CSRF token)
        // 6. Redirect to original page (RelayState) or /teacher/dashboard
        return $this->notConfigured('callback (D.2.3)');
    }

    public function metadata(Request $req): Response
    {
        if (!$this->isEnabled()) {
            return $this->notConfigured('metadata');
        }
        // TODO D.2.3: generate SP metadata XML signed con SP cert.
        return $this->notConfigured('metadata (D.2.3)');
    }

    public function logout(Request $req): Response
    {
        if (!$this->isEnabled()) {
            return $this->notConfigured('logout');
        }
        // TODO D.2.3: SLO via SAML, poi pantedu session destroy.
        return $this->notConfigured('logout (D.2.3)');
    }

    private function isEnabled(): bool
    {
        return (bool)Config::get('auth.spid.enabled', false)
            || ($_ENV['SPID_ENABLED'] ?? '0') === '1';
    }

    private function notConfigured(string $action): Response
    {
        return Response::json([
            'error'   => 'spid_not_configured',
            'message' => "SPID Service Provider non ancora registrato presso AgID. "
                . "Action '{$action}' disponibile dopo certificazione "
                . "(vedi docs/plans/d2-spid-cie-integration.md).",
            'phase'   => 'D.2.5 BLOCKED — awaiting AgID SP registration',
        ], 503, ['Cache-Control' => 'no-store']);
    }
}
