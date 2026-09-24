<?php

declare(strict_types=1);

/**
 * Verifica che il sito risponda, subito dopo un rilascio.
 *
 *   php tools/ops/smoke_after_deploy.php
 *   php tools/ops/smoke_after_deploy.php --rotte=/,/login,/privacy/informativa
 *
 * Esce 0 se tutte le rotte rispondono, 1 se una sola non risponde: è pensato
 * per `deploy.sh`, che con un esito diverso da zero marca l'unità systemd come
 * `failed` — un segnale che si vede.
 *
 * PERCHÉ NON `curl`
 *   Il WAF risponde 403 ai client automatici, quindi un `curl` dopo il deploy
 *   direbbe «rotto» a ogni giro. Qui si dispaccia il Kernel dentro il processo,
 *   come fa `tools/dev/render_page.php`: stesso router, stessi controller, stessa
 *   configurazione, nessun HTTP di mezzo. Si verifica quello che il deploy può
 *   davvero aver rotto — codice, autoload, configurazione, database — non la
 *   rete.
 *
 * COSA CONTROLLA, per ogni rotta
 *   - lo stato è < 400 (un 3xx va bene: certe rotte pubbliche rimandano);
 *   - il corpo non è vuoto;
 *   - non contiene «Fatal error» né «Uncaught».
 *
 * COSA NON FA
 *   Solo GET, solo rotte pubbliche, niente sessione, niente scritture. Non
 *   sostituisce la suite end-to-end: dice che il sito sta in piedi, non che
 *   funziona.
 */

$base = \dirname(__DIR__, 2);

$opzioni = [];
foreach (\array_slice($argv, 1) as $arg) {
    if (\preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $opzioni[$m[1]] = $m[2];
    }
}

/**
 * Rotte pubbliche che devono rispondere sempre.
 *
 * Solo rotte dell'applicazione: i file statici — `/.well-known/security.txt`,
 * i fogli di stile — li serve nginx, e da qui risulterebbero 404.
 */
$rotte = isset($opzioni['rotte'])
    ? \array_values(\array_filter(\array_map('trim', \explode(',', $opzioni['rotte']))))
    : ['/', '/login', '/privacy/informativa', '/accessibility'];

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_SERVER['HTTP_HOST']      = (string)($opzioni['host'] ?? 'www.pantedu.eu');
$_SERVER['HTTP_ACCEPT']    = 'text/html';
$_SERVER['REMOTE_ADDR']    = '127.0.0.1';
$_SERVER['HTTPS']          = 'on';
$_SERVER['REQUEST_URI']    = '/';
$_GET = [];

require $base . '/app/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;

$router = new Router();
require $base . '/routes/web.php';
$kernel = new Kernel($router);

$guasti = [];
foreach ($rotte as $rotta) {
    $_SERVER['REQUEST_URI'] = $rotta;
    $_GET = [];
    $parti = \parse_url($rotta);
    if (isset($parti['query'])) {
        \parse_str($parti['query'], $_GET);
    }

    try {
        $risposta = $kernel->handle(new Request());
        $corpo    = (string)$risposta->body;
        $stato    = (int)$risposta->status;
    } catch (\Throwable $e) {
        $guasti[] = \sprintf('%s → eccezione %s: %s', $rotta, $e::class, $e->getMessage());
        continue;
    }

    if ($stato >= 400) {
        $guasti[] = \sprintf('%s → %d', $rotta, $stato);
        continue;
    }
    if ($stato < 300 && \trim($corpo) === '') {
        $guasti[] = \sprintf('%s → %d ma corpo vuoto', $rotta, $stato);
        continue;
    }
    if (\stripos($corpo, 'Fatal error') !== false || \stripos($corpo, 'Uncaught') !== false) {
        $guasti[] = \sprintf('%s → %d con un errore PHP nel corpo', $rotta, $stato);
        continue;
    }

    \printf("  ✓ %-32s %d · %d byte\n", $rotta, $stato, \strlen($corpo));
}

if ($guasti !== []) {
    \fwrite(STDERR, "\nIl sito non risponde come dovrebbe:\n");
    foreach ($guasti as $g) {
        \fwrite(STDERR, "  · $g\n");
    }
    \fwrite(STDERR, "\n");
    exit(1);
}

\printf("\nTutte le %d rotte rispondono.\n", \count($rotte));
