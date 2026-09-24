<?php

use App\Core\Config;
use Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$radice = dirname(__DIR__);

// 2026-09-10 — qui c'era il solo `Config::load()`, senza i file `.env`.
//
// Conseguenza: la configurazione del database restava incompleta, e ogni prova
// che comincia con `if (!Database::isAvailable()) markTestSkipped(...)` si
// saltava. Ma non sempre: le classi che caricano `app/bootstrap.php` da sé
// (per esempio `RisdocResolverTest::setUpBeforeClass`) leggono i `.env` per
// tutto il processo, quindi **chi girava dopo di loro trovava il database e
// chi girava prima no**. La suite gira con `executionOrder="random"`: era una
// lotteria a ogni giro.
//
// Misurato il 10 settembre 2026: col seme 1 si saltavano le tre prove di
// `PublicContentPolicyTest`, coi semi da 2 a 8 nessuna. Da sola, quella classe
// si saltava sempre. Nessun rosso, mai: solo prove d'integrazione che a volte
// non guardavano niente.
//
// Si caricano gli stessi due file dell'applicazione, nello stesso ordine.
// **Non** il resto di `app/bootstrap.php`: quello avvia anche la sessione, che
// in una suite non serve e porta stato condiviso fra le prove.
if (is_file($radice . '/.env')) {
    Dotenv::createImmutable($radice)->safeLoad();
}
if (is_file($radice . '/.env.local')) {
    // createMutable: `.env.local` ha la precedenza su `.env`, come nell'app.
    Dotenv::createMutable($radice, '.env.local')->safeLoad();
}

Config::load(__DIR__ . '/../app/Config');

if (!defined('TEST_FIXTURES')) {
    define('TEST_FIXTURES', __DIR__ . '/Fixtures');
}

date_default_timezone_set('Europe/Rome');
