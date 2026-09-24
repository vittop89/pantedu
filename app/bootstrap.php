<?php

use App\Core\Config;
use App\Core\Session;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);

// Phase 25.D — Load .env (committed, placeholder) + .env.local (gitignored,
// secrets reali). .env.local ha precedenza per consentire override di
// KMS_MASTER_KEY e altri segreti senza versionarli.
if (is_file($basePath . '/.env')) {
    Dotenv::createImmutable($basePath)->safeLoad();
}
if (is_file($basePath . '/.env.local')) {
    // createMutable: permette override di var già caricate da .env.
    Dotenv::createMutable($basePath, '.env.local')->safeLoad();
}

Config::load(__DIR__ . '/Config');

date_default_timezone_set(Config::get('app.timezone', 'Europe/Rome'));

if (Config::get('app.debug')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    // `E_STRICT` non si nomina piu': dal PHP 8.4 la costante e' deprecata e
    // citarla stampa un avviso a ogni esecuzione — visibile in cima all'output
    // di ogni comando, e potenzialmente in cima a una risposta HTTP. Dal PHP
    // 8.0 non veniva comunque piu' emessa da niente.
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', Config::get('app.paths.logs') . '/php_errors.log');
}

Session::start();
