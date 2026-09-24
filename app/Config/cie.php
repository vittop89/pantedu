<?php

/**
 * CIE Service Provider — scaffolding (Phase D.2).
 *
 * Stessa storia di `spid.php`: `CieController::isEnabled()` interrogava
 * `Config::get('auth.cie.enabled')`, chiave che `auth.php` non ha mai avuto,
 * e viveva solo del ripiego diretto su `$_ENV['CIE_ENABLED']`, vietato fuori
 * da `app/Config` (revisione architetturale del 23/9/2026, A-34;
 * wiki/decisions/ADR-049).
 *
 * `CIE_ENABLED` resta spenta finché pantedu non è registrato come Service
 * Provider presso AgID (docs/plans/d2-spid-cie-integration.md).
 */

return [
    'enabled' => \App\Core\Config::booleanoDallAmbiente('CIE_ENABLED', false),
];
