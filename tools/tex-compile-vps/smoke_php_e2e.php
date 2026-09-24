<?php
/**
 * Smoke test end-to-end del flow PHP:
 *   PHP TikzRenderService → TikzRenderClient → VPS locale → SVG
 *
 * Punta al server locale in 127.0.0.1:8001 con il secret di test.
 * Usa Composer autoload del progetto pantedu.
 *
 * Run:
 *   php tools/tex-compile-vps/smoke_php_e2e.php
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/vendor/autoload.php';

// Bootstrap minimal: load .env solo per KMS (serve per teacher scope).
\App\Core\Config::load($root);

// Costruzione manuale del client/service col VPS LOCALE (bypass .env prod).
$client = new \App\Services\TexCompile\TikzRenderClient(
    endpoint: 'http://127.0.0.1:8001',
    secret:   'local-dev-test-secret-not-for-prod-32-bytes-min',
    timeoutSeconds: 30,
);

$crypto = new \App\Services\Crypto\TeacherCryptoService();
$svc = new \App\Services\Tikz\TikzRenderService($client, $crypto, $root);

$tikz = <<<'TEX'
\usepackage{amssymb}
\usepackage{amsmath}
\usepackage{tikz}
\usetikzlibrary{calc}

\begin{document}
\begin{tikzpicture}
  \draw[->, thick] (0,0) -- (4,0) node[right]{$x$};
  \draw[->, thick] (0,0) -- (0,3) node[above]{$y$};
  \draw[blue, thick] (0,0) -- (3,2) node[midway, above, sloped]{retta};
  \fill[red] (3,2) circle (3pt) node[above right]{$P(3,2)$};
\end{tikzpicture}
\end{document}
TEX;

echo "=== Test 1: scope=public, frammento semplice ===\n";
$t0 = microtime(true);
try {
    $r = $svc->getOrRender(
        $tikz,
        \App\Services\Tikz\TikzRenderService::SCOPE_PUBLIC,
        0,
        ['libraries' => ['calc']],
    );
    $dt = (int)((microtime(true) - $t0) * 1000);
    printf("OK source=%s hash=%s svg_bytes=%d duration_ms=%d total_php_ms=%d\n",
        $r['source'], substr($r['hash'], 0, 16), strlen($r['svg']),
        $r['duration_ms'] ?? -1, $dt);
    file_put_contents($root . '/tools/tex-compile-vps/php_smoke_test1.svg', $r['svg']);
    echo "  → salvato in tools/tex-compile-vps/php_smoke_test1.svg\n";
} catch (\Throwable $e) {
    echo "FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
    if (method_exists($e, 'httpStatus')) {
        echo "  http_status=" . $e->httpStatus() . "\n";
    }
}

echo "\n=== Test 2: lookup ripetuto (cache HIT) ===\n";
$t0 = microtime(true);
try {
    $r = $svc->getOrRender(
        $tikz,
        \App\Services\Tikz\TikzRenderService::SCOPE_PUBLIC,
        0,
        ['libraries' => ['calc']],
    );
    $dt = (int)((microtime(true) - $t0) * 1000);
    printf("OK source=%s svg_bytes=%d total_php_ms=%d\n",
        $r['source'], strlen($r['svg']), $dt);
    if ($r['source'] !== 'cache') {
        echo "  ⚠️ Aspettavo source=cache ma ho '{$r['source']}'!\n";
    }
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
}

echo "\n=== Test 3: scope=teacher (envelope encryption + decrypt) ===\n";
// Ricordati di avere KMS_MASTER_KEY in .env.local ed un teacher_id valido.
$teacherId = (int)($argv[1] ?? 1);
echo "  teacher_id=$teacherId\n";
$t0 = microtime(true);
try {
    $r = $svc->getOrRender(
        "\\begin{tikzpicture}\\draw (0,0) circle (1cm);\\end{tikzpicture}",
        \App\Services\Tikz\TikzRenderService::SCOPE_TEACHER,
        $teacherId,
        ['libraries' => []],
    );
    $dt = (int)((microtime(true) - $t0) * 1000);
    printf("OK source=%s svg_bytes=%d total_php_ms=%d\n",
        $r['source'], strlen($r['svg']), $dt);
    // Verifica che il file su disco sia .bin cifrato (non SVG raw)
    $hash = $r['hash'];
    $prefix = substr($hash, 0, 2);
    $blobPath = $root . "/storage/cache/tikz/teacher_{$teacherId}/{$prefix}/{$hash}.bin";
    if (is_file($blobPath)) {
        $blob = file_get_contents($blobPath);
        $blobHead = bin2hex(substr($blob, 0, 4));
        printf("  blob su disco: %d bytes, head=%s (header version=1+kv+iv...)\n",
            strlen($blob), $blobHead);
        if (strpos($blob, '<svg') !== false) {
            echo "  ⚠️ ATTENZIONE: il blob teacher contiene SVG in chiaro!\n";
        } else {
            echo "  ✓ blob NON contiene 'svg' in chiaro (cifrato)\n";
        }
    }
    // Re-render: deve fare decrypt e ritornare lo stesso SVG
    $r2 = $svc->getOrRender(
        "\\begin{tikzpicture}\\draw (0,0) circle (1cm);\\end{tikzpicture}",
        \App\Services\Tikz\TikzRenderService::SCOPE_TEACHER,
        $teacherId,
    );
    if ($r2['svg'] === $r['svg']) {
        echo "  ✓ decrypt round-trip OK (stesso SVG)\n";
    } else {
        echo "  ⚠️ decrypt round-trip FAIL (SVG diversi)\n";
    }
} catch (\Throwable $e) {
    echo "FAIL: " . get_class($e) . ": " . $e->getMessage() . "\n";
    if (str_contains($e->getMessage(), 'kms_not_configured')) {
        echo "  hint: KMS_MASTER_KEY mancante in .env.local\n";
    }
}

echo "\n=== Done ===\n";
