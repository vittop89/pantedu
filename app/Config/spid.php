<?php

/**
 * SPID Service Provider — scaffolding (Phase D.2).
 *
 * `SpidController::isEnabled()` interrogava `Config::get('auth.spid.enabled')`,
 * ma `auth.php` non ha mai avuto una chiave `spid`: quella lettura tornava
 * sempre il default `false`, e l'unica cosa che decideva davvero era il
 * ripiego diretto su `$_ENV['SPID_ENABLED']`, vietato fuori da `app/Config`
 * (revisione architetturale del 23/9/2026, A-34; wiki/decisions/ADR-049).
 *
 * `SPID_ENABLED` resta spenta finché pantedu non è registrato come Service
 * Provider presso AgID (docs/plans/d2-spid-cie-integration.md).
 */

return [
    'enabled' => \App\Core\Config::booleanoDallAmbiente('SPID_ENABLED', false),
];
