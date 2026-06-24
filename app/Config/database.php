<?php

/**
 * Database configuration.
 *
 * Reads DSN components from environment. When `enabled` is false the
 * Repository layer short-circuits to legacy JSON stores — lets the app
 * keep running if MySQL is unavailable (e.g. during hosting legacy).
 */

return [
    'enabled' => filter_var($_ENV['DB_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'driver'  => $_ENV['DB_DRIVER']  ?? 'mysql',
    'host'    => $_ENV['DB_HOST']    ?? '127.0.0.1',
    'port'    => (int)($_ENV['DB_PORT'] ?? 3306),
    // ISOLAMENTO TEST: in APP_ENV=testing (impostato SOLO da phpunit.xml) si usa
    // un DB separato e usa-e-getta, così la suite non gira mai sul DB dev/prod
    // (i test crypto cancellano/rigenerano chiavi; altri vogliono stato pulito).
    // La config è valutata a Config::load (bootstrap test, prima del reload .env
    // nei setUp) e cachata → i reload di .env non la ripuntano al dev.
    // getenv() PRIMA di $_ENV: i setUp dei test ricaricano .env (Dotenv
    // createMutable) impostando $_ENV[APP_ENV]=production, ma il putenv di
    // phpunit (APP_ENV=testing) sopravvive in getenv() → rilevamento stabile.
    'name'    => ((\getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? ''))) === 'testing')
                    ? ($_ENV['DB_NAME_TEST'] ?? 'pantedu_test')
                    : ($_ENV['DB_NAME'] ?? 'pantedu_dev'),
    'user'    => $_ENV['DB_USER']    ?? 'root',
    'pass'    => $_ENV['DB_PASS']    ?? '',
    'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',

    // Dual-write durante la transizione JSON → MySQL: ogni scrittura
    // va sia nel DB sia nel file JSON legacy. Disattiva quando la
    // migrazione è consolidata e i backup DB sono attivi.
    'dual_write' => filter_var($_ENV['DB_DUAL_WRITE'] ?? true, FILTER_VALIDATE_BOOLEAN),
];
