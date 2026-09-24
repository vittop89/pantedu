<?php

declare(strict_types=1);

/**
 * Router per il server incorporato di PHP (`php -S ... tools/ci/router-php-server.php`).
 *
 * Senza, il server incorporato risponde 404 da sé a ogni indirizzo che
 * «somiglia a un file», cioè che ha un punto nell'ultimo segmento: la regola
 * documentata è che il ripiego su `index.php` scatta solo quando l'URI non
 * indica un file. Nell'applicazione ci sono decine di rotte così —
 * `/api/studio/exercises.json`, `/api/teacher/header-page.json`,
 * `/api/study/content.json` — e in prova sparivano tutte con un 404 che non
 * veniva nemmeno dall'applicazione: nginx, in produzione, le instrada senza
 * batter ciglio.
 *
 * Regola: se il percorso corrisponde a un file vero dentro `public/`, lo serve
 * il server; tutto il resto va a `public/index.php`, come fa nginx.
 */

$radice = \dirname(__DIR__, 2) . '/public';
$percorso = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

// Niente risalite: `..` in un URI non deve poter uscire da `public/`.
$normalizzato = str_replace('\\', '/', $percorso);
if (str_contains($normalizzato, '..')) {
    http_response_code(400);
    return true;
}

$file = $radice . $normalizzato;
$estensioneRichiesta = strtolower(pathinfo($normalizzato, PATHINFO_EXTENSION));
// Una domanda sola al disco sul percorso della richiesta, riusata sotto: ogni
// `is_file` in più su un percorso che viene dalla richiesta è un riscontro in
// più di semgrep (`tainted-filename`, con un tetto), anche quando è innocuo.
$esisteInPublic = $normalizzato !== '/' && is_file($file);

// 2026-09-14 — le stesse risposte del nginx del container (`docker/nginx.conf`),
// nello stesso ordine in cui nginx sceglie la `location`. Da quel giorno la
// suite in CI gira contro l'immagine del rilascio, e questo router serve lo
// sviluppo: dove i due rispondono diverso, una prova passa in un posto e fallisce
// nell'altro, e si cerca un difetto che non c'è. Il router diceva di comportarsi
// come nginx e divergeva in tre punti: serviva `/views/`, passava
// all'applicazione un `.php` che non esiste, e serviva `/vendor/` quando in
// produzione rispondeva 403 (corretto nginx, #97). Prova:
// tests/ops/rotte-come-nginx.test.sh, sia qui sia contro il container.

// `location ^~ /vendor/`: le librerie del browser, solo file che esistono, mai PHP.
if (str_starts_with($normalizzato, '/vendor/')) {
    if ($esisteInPublic && $estensioneRichiesta !== 'php') {
        return false;
    }
    http_response_code(404);
    return true;
}

// I divieti: file nascosti e cartelle di codice.
if (preg_match('#/\.(env|git|htaccess|ht)#', $normalizzato) === 1
    || preg_match('#^/(storage|database|tools|tests|wiki|docs|app|views|bootstrap|vendor|node_modules|composer\.)#', $normalizzato) === 1
) {
    http_response_code(403);
    return true;
}

// `location ~ \.php$` con `try_files $uri =404`: il PHP che non c'è non arriva
// all'applicazione.
if ($estensioneRichiesta === 'php' && !$esisteInPublic) {
    http_response_code(404);
    return true;
}

if ($esisteInPublic) {
    return false;   // false = «servilo tu», il server incorporato manda il file
}

// Gli alias di produzione. Il nginx del container (`docker/nginx.conf`) serve
// /css/, /js/ e /img/ dalle cartelle omonime del REPOSITORY, non da
// public/. Qui si fa lo stesso; `/views/` non più, come nginx.
//
// 2026-09-10 — prima questo router li ignorava, e chi lo usava doveva
// ricrearli a mano: la CI fa `ln -s css public/css`, `ln -s js public/js` e
// copia le immagini dentro public/img. Su una copia di lavoro vera quei
// collegamenti e quelle copie finiscono fra i file non tracciati; e /views/ e
// /wasm/ non li ricreava nessuno. Con lo sviluppo in WSL questo router è il
// server di tutti i giorni, quindi deve comportarsi come nginx.
//
// Mai i sorgenti PHP: un alias che servisse `views/*.php` come testo
// mostrerebbe il codice.
//
// Il percorso da leggere non si costruisce dalla richiesta: si CERCA fra i
// file che esistono davvero nella cartella dell'alias, e a `readfile` arriva
// il percorso trovato sul disco. Così una risalita non è «respinta da un
// controllo»: è impossibile, perché nessun file fuori da quelle cartelle può
// essere trovato. Costa una visita della cartella per richiesta — misurata:
// 0,16 ms su `js/`, che con 221 file è la più grande. È anche la forma che il
// cancello di semgrep (`tainted-filename`, tetto 9) riconosce: la prima
// versione, che concatenava il percorso della richiesta, ne aggiungeva tre.
$cartelleAlias = [
    'css'   => \dirname(__DIR__, 2) . '/css',
    'js'    => \dirname(__DIR__, 2) . '/js',
    'img'   => \dirname(__DIR__, 2) . '/img',
];
if (preg_match('#^/(css|js|img)/(.+)$#', $normalizzato, $parti) === 1) {
    $base = $cartelleAlias[$parti[1]];
    $trovato = null;
    if (is_dir($base)) {
        $visita = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($visita as $voce) {
            if ($voce->isFile() && substr($voce->getPathname(), strlen($base) + 1) === $parti[2]) {
                $trovato = $voce;
                break;
            }
        }
    }
    $estensione = $trovato !== null ? strtolower($trovato->getExtension()) : '';
    if ($trovato !== null && $estensione !== 'php') {
        $tipi = [
            'css'   => 'text/css; charset=UTF-8',
            'js'    => 'text/javascript; charset=UTF-8',
            'mjs'   => 'text/javascript; charset=UTF-8',
            'map'   => 'application/json',
            'json'  => 'application/json',
            'html'  => 'text/html; charset=UTF-8',
            'svg'   => 'image/svg+xml',
            'png'   => 'image/png',
            'jpg'   => 'image/jpeg',
            'jpeg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'webp'  => 'image/webp',
            'ico'   => 'image/x-icon',
            'woff'  => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf'   => 'font/ttf',
        ];
        header('Content-Type: ' . ($tipi[$estensione] ?? 'application/octet-stream'));
        header('Content-Length: ' . (string) $trovato->getSize());
        readfile($trovato->getPathname());
        return true;
    }
    // Un `alias` di nginx non ripiega sull'applicazione: il file non c'è, 404.
    http_response_code(404);
    return true;
}

// 2026-09-12 — l'esito di ogni richiesta che passa dall'applicazione, nel
// registro del server.
//
// Con `PHP_CLI_SERVER_WORKERS` il server incorporato scrive soltanto
// «Accepted», «Closing» e «Failed to poll event»: **nessun codice di
// risposta**. Il 12 settembre la suite in CI è diventata rossa tre volte con un
// 403 dove ci si aspettava altro, e in un registro da 233.043 righe non c'era
// modo di sapere quale rotta l'avesse ricevuto. Un passo del workflow che
// cercava i 403 lì dentro non poteva trovarne nessuno, e non ne trovava.
//
// Solo le richieste che arrivano all'applicazione: i file statici li serve il
// server, e sarebbero migliaia di righe di 200. Il percorso si scrive senza
// query e con i caratteri non stampabili sostituiti, perché è testo che arriva
// dalla richiesta e non deve poter spezzare una riga del registro.
//
// Su `php://stderr` e non con `error_log()`: `app/bootstrap.php` sposta
// `error_log` in `storage/logs/php_errors.log`, e la prima stesura di queste
// righe finiva lì — con i codici giusti, nel file sbagliato. Nel registro del
// server: zero righe.
register_shutdown_function(static function () use ($percorso): void {
    $metodo = (string) preg_replace('/[^A-Z]/', '', (string) ($_SERVER['REQUEST_METHOD'] ?? '-'));
    $sicuro = (string) preg_replace('/[^\x21-\x7E]/', '?', $percorso);
    file_put_contents('php://stderr', sprintf("[esito] %d %s %s\n", http_response_code() ?: 200, $metodo, $sicuro));
});

require $radice . '/index.php';
return true;
