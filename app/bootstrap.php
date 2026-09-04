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
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', Config::get('app.paths.logs') . '/php_errors.log');
}

Session::start();
