<?php

// Phase 25.B7 — pentest 2026-05-18 FND-003 (info disclosure):
// rimuovi X-Powered-By aggiunto da PHP. `expose_php=Off` in .user.ini è
// ignorato quando PHP gira come Apache module (mod_php). header_remove
// funziona sempre, indipendentemente da SAPI/config.
// Server header (Apache/version) richiede `ServerTokens Prod` in httpd.conf
// — non gestibile via .htaccess su shared hosting. Documentato accepted-risk.
header_remove('X-Powered-By');

// 2026-05-24 — Link header preload per CF Early Hints (Opzione 2: granulare).
//
// Gate: invio solo su NAVIGATION TOP-LEVEL HTML (utente apre URL in browser
// bar o click su <a>). Skip per:
//   - non-GET (POST/PUT/DELETE: no asset preload needed)
//   - XMLHttpRequest (jQuery $.ajax, fetch con custom header)
//   - fetch() con Sec-Fetch-Mode != "navigate" (subresource/partial)
//   - Accept header non-HTML (es. application/json API call)
//
// Razionale: solo navigation top-level beneficia di preload CSS/JS (è la
// prima request della pagina). AJAX/XHR/fetch subresource fetchano
// JSON/HTML partial — preload CSS+JS sarebbe duplicato + CF non promuove
// a HTTP 103 affidabile su non-navigate → warning "preloaded but not used".
//
// Con CF Early Hints toggle ON in dashboard, CF promuove Link header
// HTTP 200 a Early Hints HTTP 103 (PRIMA della response) → browser
// pre-fetcha CSS/JS critici → -100/-300ms LCP gain Slow 3G.
$_method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_isXhr  = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
$_secFetchMode = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
$_isNavigate = $_secFetchMode === '' || $_secFetchMode === 'navigate';
$_accept = $_SERVER['HTTP_ACCEPT'] ?? '';
$_isHtml = str_contains($_accept, 'text/html') || $_accept === '*/*' || $_accept === '';

// 2026-05-24 — Gate ulteriore: solo request via Cloudflare. CF promuove
// Link header a Early Hints HTTP 103 (HTTP/2 + push). Su HTTP/1.1 origin
// diretto (XAMPP dev, accesso direct IP VPS) il preload Link header genera
// warning Chrome "preloaded but not used" perché su HTTP/1.1 il browser
// non riesce sempre a matchare il preload con il <link>/<script> del body
// (race condition: <link rel="stylesheet"> arriva al parser prima che il
// preload risponda → fetch duplicato → preload "orphan"). HTTP/2 + Early
// Hints risolve questo perché HTTP 103 arriva PRIMA del 200 response body.
// Detection: HTTP_CF_RAY exists solo se request via Cloudflare proxy.
$_viaCf = !empty($_SERVER['HTTP_CF_RAY']);

if ($_viaCf && $_method === 'GET' && !$_isXhr && $_isNavigate && $_isHtml) {
    $_root = __DIR__ . '/..';
    $_cssBundle = $_root . '/css/main.bundle.css';
    $_cssMain   = $_root . '/css/main.css';
    $_cssTarget = is_file($_cssBundle) ? '/css/main.bundle.css' : '/css/main.css';
    $_cssMtime  = is_file($_cssBundle) ? filemtime($_cssBundle) : (is_file($_cssMain) ? filemtime($_cssMain) : 0);
    header("Link: <{$_cssTarget}?v={$_cssMtime}>; rel=preload; as=style", false);

    // 2026-05-24 Fase 2 — Vite manifest preload: emette modulepreload per
    // entry bootstrap + tutti i sub-chunks statici (parallel fetch via CF
    // Early Hints HTTP 103). Fallback raw bootstrap.dist.js se manifest assente.
    $_manifestPath = $_root . '/public/build/manifest.json';
    if (is_file($_manifestPath)) {
        $_manifest = json_decode((string)@file_get_contents($_manifestPath), true) ?: [];
        $_entry = $_manifest['js/modules/bootstrap.js'] ?? null;
        if ($_entry && !empty($_entry['file'])) {
            header("Link: </build/{$_entry['file']}>; rel=modulepreload", false);
            foreach ((array)($_entry['imports'] ?? []) as $_chunkKey) {
                $_sub = $_manifest[$_chunkKey] ?? null;
                if ($_sub && !empty($_sub['file'])) {
                    header("Link: </build/{$_sub['file']}>; rel=modulepreload", false);
                }
            }
        }
    } else {
        // Fallback legacy: bootstrap.dist.js raw con cache-bust mtime.
        $_jsDist = $_root . '/js/modules/bootstrap.dist.js';
        $_jsBoot = $_root . '/js/modules/bootstrap.js';
        $_jsTarget = is_file($_jsDist) ? '/js/modules/bootstrap.dist.js' : '/js/modules/bootstrap.js';
        $_jsMtime  = is_file($_jsDist) ? filemtime($_jsDist) : (is_file($_jsBoot) ? filemtime($_jsBoot) : 0);
        header("Link: <{$_jsTarget}?v={$_jsMtime}>; rel=modulepreload", false);
    }
}

use App\Core\Kernel;
use App\Core\Request;
use App\Core\Router;

// Static passthrough universale: serve file reali in public/ per qualsiasi
// SAPI. Motivazioni:
//   - `php -S` non applica .htaccess → serve via `return false` al built-in server
//   - Apache/XAMPP con AllowOverride None ignora .htaccess → tutto passa per
//     il router PHP, che non ha route per /build/*, /css/*, ecc. → 404
//   - Apache con .htaccess attivo: RewriteCond %{REQUEST_FILENAME} -f skippa
//     già il rewrite → questo blocco non viene mai raggiunto (no-op innocuo)
// Safety: solo file realmente dentro public/ (is_file + path traversal guard),
// esclusi .php (mai serviti come static).
$reqPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if ($reqPath !== '/' && $reqPath !== '/index.php' && !str_ends_with($reqPath, '.php')) {
    if (str_contains($reqPath, '..') || str_contains($reqPath, "\0")) {
        http_response_code(400);
        exit;
    }
    $staticPath = __DIR__ . $reqPath;
    if (is_file($staticPath)) {
        if (PHP_SAPI === 'cli-server') return false;
        $ext = strtolower(pathinfo($staticPath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'js', 'mjs'  => 'application/javascript; charset=utf-8',
            'css'        => 'text/css; charset=utf-8',
            'json', 'map'=> 'application/json; charset=utf-8',
            'html', 'htm'=> 'text/html; charset=utf-8',
            'svg'        => 'image/svg+xml',
            'png'        => 'image/png',
            'jpg','jpeg' => 'image/jpeg',
            'gif'        => 'image/gif',
            'webp'       => 'image/webp',
            'ico'        => 'image/x-icon',
            'woff'       => 'font/woff',
            'woff2'      => 'font/woff2',
            'ttf'        => 'font/ttf',
            'otf'        => 'font/otf',
            'txt'        => 'text/plain; charset=utf-8',
            default      => 'application/octet-stream',
        };
        header("Content-Type: $mime");
        header('Content-Length: ' . filesize($staticPath));
        // Asset hashati (Vite): cache lunga. Altri: short cache.
        $cacheable = str_starts_with($reqPath, '/build/assets/');
        header($cacheable
            ? 'Cache-Control: public, max-age=31536000, immutable'
            : 'Cache-Control: public, max-age=300');
        readfile($staticPath);
        exit;
    }
}

// Phase X — layout detection per shared hosting (es. Aruba Linux basic).
// Dev/local: webroot (questo file) è dentro `public/` + parent contiene
//             `app/`, `vendor/`, `routes/`, `schemas/`, ecc.
// Aruba:     webroot = `httpdocs/` (o equivalente) contiene SOLO questo
//             index.php + static assets; `app/`, `vendor/`, `routes/`, …
//             sono in `../private/` sibling (fuori dalla webroot per safety).
// Detection: presenza di `../private/app/bootstrap.php` → layout Aruba.
$appRoot = is_file(__DIR__ . '/../private/app/bootstrap.php')
    ? __DIR__ . '/../private'
    : __DIR__ . '/..';

require $appRoot . '/app/bootstrap.php';

$router = new Router();
require $appRoot . '/routes/web.php';

(new Kernel($router))->handle(new Request())->send();
