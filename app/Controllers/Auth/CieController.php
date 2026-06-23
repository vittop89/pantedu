<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Config;
use App\Core\Request;
use App\Core\Response;

/**
 * Phase D.2 — CIE (Carta d'Identità Elettronica) Service Provider scaffolding.
 *
 * STATUS: scaffolding stub. Vedi note SpidController e
 * docs/plans/d2-spid-cie-integration.md.
 *
 * Library: italia/spid-cie-php v3.19.1 (Apache-2.0) supporta entrambi.
 *
 * CIE specifics vs SPID:
 *   - Single IdP (Ministero Interno) anziche' multi-IdP list
 *   - Auth levels: L1 (CIE+PIN+app), L2 (CIE+PIN+NFC), L3 (cert qualif)
 *   - Test environment: demo.cartaidentita.interno.gov.it
 *
 * Endpoints SAML 2.0:
 *   GET  /auth/cie/login    -> AuthnRequest verso IdP CIE
 *   GET  /auth/cie/callback -> ACS
 *   GET  /auth/cie/metadata -> SP metadata XML
 *   GET  /auth/cie/logout   -> SLO
 */
final class CieController
{
    public function login(Request $req): Response
    {
        return $this->notConfigured('login');
    }

    public function callback(Request $req): Response
    {
        return $this->notConfigured('callback');
    }

    public function metadata(Request $req): Response
    {
        return $this->notConfigured('metadata');
    }

    public function logout(Request $req): Response
    {
        return $this->notConfigured('logout');
    }

    private function isEnabled(): bool
    {
        return (bool)Config::get('auth.cie.enabled', false)
            || ($_ENV['CIE_ENABLED'] ?? '0') === '1';
    }

    private function notConfigured(string $action): Response
    {
        return Response::json([
            'error'   => 'cie_not_configured',
            'message' => "CIE Service Provider non ancora registrato presso AgID. "
                . "Action '{$action}' disponibile dopo certificazione "
                . "(vedi docs/plans/d2-spid-cie-integration.md).",
            'phase'   => 'D.2.5 BLOCKED — awaiting AgID SP registration',
        ], 503, ['Cache-Control' => 'no-store']);
    }
}
