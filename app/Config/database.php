<?php

/**
 * Database configuration.
 *
 * Reads DSN components from environment. When `enabled` is false the
 * Repository layer short-circuits to legacy JSON stores — lets the app
 * keep running if MySQL is unavailable (e.g. during hosting legacy).
 */

return [
    'enabled' => \App\Core\Config::booleanoDallAmbiente('DB_ENABLED', false),
    'driver'  => $_ENV['DB_DRIVER']  ?? 'mysql',
    'host'    => $_ENV['DB_HOST']    ?? '127.0.0.1',
    'port'    => (int)($_ENV['DB_PORT'] ?? 3306),
    // 2026-09-08 — connessione via socket Unix, per l'assetto a container.
    //
    // MariaDB sul VPS ascolta solo su loopback, e dentro un container
    // `127.0.0.1` e' il container stesso: la connessione veniva rifiutata.
    // Le alternative erano esporre il database sulla rete del bridge (piu'
    // superficie) o dare al container la rete dell'host (nessun isolamento:
    // potrebbe raggiungere Grafana, crowdsec, tutto quello che ascolta sulla
    // loopback). Il socket montato in sola lettura non espone niente e non
    // richiede di riavviare il database.
    //
    // Vuoto = si usa host e porta, come sempre.
    'socket'  => $_ENV['DB_SOCKET']  ?? '',
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
    // La doppia scrittura DB + JSON della migrazione a MySQL (DB_DUAL_WRITE)
    // e' stata tolta il 2026-09-05: i JSON restano solo come ripiego dei
    // servizi quando il DB non e' disponibile.
];
