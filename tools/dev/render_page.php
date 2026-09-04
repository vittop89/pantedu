<?php

declare(strict_types=1);

/**
 * Renderizza una pagina passando dal router vero, senza browser.
 *
 * PERCHE'
 *   Per guardare una pagina servivano un browser, una sessione viva e —
 *   in locale — un'autorizzazione per ogni sito. Tre cose che non c'entrano
 *   niente con la domanda vera, che e' quasi sempre "questa pagina ha la
 *   cornice giusta / non e' esplosa". Qui la si ottiene da riga di comando:
 *   stesso Router, stesso Kernel, stessi controller di Apache.
 *
 * COSA NON FA
 *   Solo GET. Non e' una scorciatoia per agire al posto di qualcuno: non
 *   invia form, non scrive, non tocca la sessione del browser. La sessione
 *   finta vive dentro il processo e muore con lui.
 *
 * USO
 *   php tools/dev/render_page.php --route=/area-docente/da-categorizzare \
 *       --user=superadmin
 *   php tools/dev/render_page.php --route=/dashboard --user=mario --out=/tmp/p.html
 *   php tools/dev/render_page.php --route=/privacy/informativa   (anonimo)
 *
 * Opzioni:
 *   --user=<username>  sessione finta per quell'utente (ruolo letto dal DB)
 *   --role=<ruolo>     forza il ruolo invece di leggerlo dal DB
 *   --out=<file>       salva l'HTML completo
 *   --cerca=<stringa>  conta le occorrenze e mostra la prima riga che la contiene
 */

$base = \dirname(__DIR__, 2);

$opt = [];
foreach (\array_slice($argv, 1) as $arg) {
    if (\preg_match('/^--([a-z]+)=(.*)$/', $arg, $m)) {
        $opt[$m[1]] = $m[2];
    }
}
$route = (string)($opt['route'] ?? '');

// Git Bash su Windows riscrive gli argomenti che sembrano percorsi unix:
// `--route=/area-docente/x` arriva a PHP come
// `--route=C:/Program Files/Git/area-docente/x`. Non e' un errore di chi
// scrive il comando, e non ha senso fargli imparare MSYS_NO_PATHCONV: la
// rotta e' la coda, e si recupera.
if (\preg_match('~^[A-Za-z]:[\\\\/].*?[\\\\/]Git[\\\\/](.*)$~', $route, $m)) {
    $route = '/' . \str_replace('\\', '/', $m[1]);
}
if ($route !== '' && $route[0] !== '/') {
    $route = '/' . $route;
}
// `/` e' una rotta come le altre — e' la home. Va rifiutato solo il vuoto.
if (!isset($opt['route']) || \trim((string)$opt['route']) === '') {
    \fwrite(STDERR, "Serve --route=/una/rotta\n");
    exit(1);
}
if ($route === '') {
    $route = '/';
}

// Il front controller legge da $_SERVER: qui glielo prepariamo a mano.
$parts = \parse_url($route);
$_SERVER['REQUEST_METHOD']  = 'GET';
$_SERVER['REQUEST_URI']     = $route;
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['HTTP_HOST']       = (string)($opt['host'] ?? 'pantedu.local');
$_SERVER['HTTP_ACCEPT']     = 'text/html';
$_SERVER['REMOTE_ADDR']     = '127.0.0.1';
$_SERVER['HTTPS']           = 'on';
$_GET = [];
if (isset($parts['query'])) {
    \parse_str($parts['query'], $_GET);
}

require $base . '/app/bootstrap.php';

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;
use App\Core\Session;

if (isset($opt['user'])) {
    $st = App\Core\Database::connection()->prepare(
        'SELECT id, username, role FROM users WHERE username = ? LIMIT 1'
    );
    $st->execute([$opt['user']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if ($u === false) {
        \fwrite(STDERR, "Utente '{$opt['user']}' inesistente in questo database.\n");
        exit(1);
    }
    Session::put('autenticato', true);
    Session::put('username', $u['username']);
    Session::put('user_id', (int)$u['id']);
    Session::put('user_role', (string)($opt['role'] ?? $u['role']));
    \printf("sessione: %s (id %d, %s)\n", $u['username'], $u['id'], $opt['role'] ?? $u['role']);
}

$router = new Router();
require $base . '/routes/web.php';

// handle() e non send(): send() sputerebbe header che da CLI non servono.
$res  = (new Kernel($router))->handle(new Request());
$html = (string)$res->body;

\printf("%s → %d · %d byte\n\n", $route, $res->status, \strlen($html));

if ($res->status >= 300 && $res->status < 400) {
    \printf("  redirect a: %s\n", $res->headers['Location'] ?? '(nessuna Location)');
}

// Le poche cose che si vogliono sapere sempre: c'e' la cornice? e' una
// pagina intera o un frammento? il titolo e' quello giusto?
$titolo = \preg_match('~<title>(.*?)</title>~s', $html, $m) ? \trim($m[1]) : '(nessuno)';
\printf("  titolo:      %s\n", $titolo);
foreach ([
    'pagina intera' => '<!doctype',
    'sidebar'       => 'fm-sidebar',
    'nav docente'   => 'fm-area-docente-nav',
    'errore PHP'    => 'Fatal error',
] as $nome => $ago) {
    $n = \substr_count(\strtolower($html), \strtolower($ago));
    \printf("  %-12s %s\n", $nome . ':', $n > 0 ? "sì ($n)" : 'no');
}

if (isset($opt['cerca'])) {
    $n = \substr_count($html, $opt['cerca']);
    \printf("\n  \"%s\": %d occorrenze\n", $opt['cerca'], $n);
    foreach (\explode("\n", $html) as $riga) {
        if (\str_contains($riga, $opt['cerca'])) {
            \printf("    %s\n", \trim($riga));
            break;
        }
    }
}

if (isset($opt['out'])) {
    \file_put_contents($opt['out'], $html);
    \printf("\n  salvata in %s\n", $opt['out']);
}
