<?php

use App\Core\Config;

require __DIR__ . '/../vendor/autoload.php';

Config::load(__DIR__ . '/../app/Config');

if (!defined('TEST_FIXTURES')) {
    define('TEST_FIXTURES', __DIR__ . '/Fixtures');
}

date_default_timezone_set('Europe/Rome');
