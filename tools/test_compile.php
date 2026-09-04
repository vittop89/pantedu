<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$id = (int)($argv[1] ?? 1494);
$teacherId = 77;

$svc = new \App\Services\Verifica\VerificaDocumentService();
try {
    $tex = $svc->readTex($teacherId, $id);
    echo "TEX read OK: " . strlen($tex) . " bytes\n";
} catch (\Throwable $e) {
    echo "readTex FAIL: " . $e->getMessage() . "\n"; exit(1);
}

$client = \App\Services\TexCompile\TexCompileClient::default();
echo "endpoint=" . (\App\Core\Config::get('tex_compile.endpoint')) . "\n";
echo "calling compile() with_artifacts=true...\n";
$t0 = microtime(true);
$r = $client->compile(
    texSource: $tex,
    docId: 'verifica_' . $id,
    engine: 'pdflatex',
    passes: 2,
    withArtifacts: true,
);
$dt = (int)((microtime(true) - $t0) * 1000);
echo "result: ok=" . var_export($r['ok'] ?? null, true)
   . " status=" . ($r['http_status'] ?? '-')
   . " duration_remote=" . ($r['duration_ms'] ?? '-') . "ms"
   . " duration_local={$dt}ms\n";
if (!empty($r['log'])) {
    $log = (string)$r['log'];
    echo "log size=" . strlen($log) . " bytes\n";
    // Trova prima riga di errore "! "
    if (preg_match('/^(!\s*[^\n]+(?:\n[^\n]+){0,5})/m', $log, $m)) {
        echo "FIRST ERROR:\n" . $m[1] . "\n";
    }
    echo "log tail:\n" . substr($log, -800) . "\n";
}
if (!empty($r['errors'])) echo "errors: " . json_encode($r['errors'], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) . "\n";
