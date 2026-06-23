<?php

// Phase S2 Fase 2 (2026-05-22) — Decoupling data dir.
// PANTEDU_DATA_PATH=/var/lib/pantedu-data fa puntare tutti i path
// dei dati app FUORI dal repo git. Backward-compat: se vuoto, i path
// restano dentro la base del repo (modello attuale per dev locale).
$_dataBase = $_ENV['PANTEDU_DATA_PATH'] ?? dirname(__DIR__, 2);

return [
    'env'        => $_ENV['APP_ENV']      ?? 'production',
    'debug'      => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url'        => $_ENV['APP_URL']      ?? '',
    'timezone'   => $_ENV['APP_TIMEZONE'] ?? 'Europe/Rome',

    // Phase S2 (ADR-017) — Deployment mode: 'single' (S1) | 'institute' (S2).
    'deployment_mode'        => in_array(($_ENV['DEPLOYMENT_MODE'] ?? 'single'), ['single', 'institute'], true)
        ? ($_ENV['DEPLOYMENT_MODE'] ?? 'single')
        : 'single',
    'institute_owner_email'  => $_ENV['INSTITUTE_OWNER_EMAIL'] ?? '',
    'institute_legal_name'   => $_ENV['INSTITUTE_LEGAL_NAME']  ?? '',

    'paths' => [
        // Code (sempre dal repo)
        'base'    => dirname(__DIR__, 2),
        'app'     => dirname(__DIR__),
        'public'  => dirname(__DIR__, 2) . '/public',
        'routes'  => dirname(__DIR__, 2) . '/routes',
        'views'   => dirname(__DIR__, 2) . '/views',
        // Phase S2 Fase 2 fix — `legacy` = entry-point PHP root (index.php
        // legacy, cron handlers, log serve, partials). E' CODICE non dati,
        // quindi deve restare nel repo. Non confondere con data_base.
        'legacy'  => dirname(__DIR__, 2),
        // Data (configurable via PANTEDU_DATA_PATH)
        'data_base' => $_dataBase,
        'storage' => $_dataBase . '/storage',
        'logs'    => $_dataBase . '/storage/logs',
    ],
];
