<?php
/**
 * ADR-029 — completeness-check runtime di un controller estratto.
 * Verifica che ogni $this->metodo() chiamato nel file risolva a un metodo
 * definito nella classe (cattura dipendenze private transitive mancanti, che
 * php -l e Reflection NON vedono). Exit 1 se incompleto.
 *
 * Uso: php tools/dev/check_controller_complete.php "App\Controllers\ContentPublishController"
 */
require __DIR__ . '/../../vendor/autoload.php';
$fqn = $argv[1] ?? exit("manca FQN\n");
$rc = new ReflectionClass($fqn);
$defined = array_map(fn($m) => $m->name, $rc->getMethods());
$src = file_get_contents($rc->getFileName());
preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m);
$called = array_values(array_unique($m[1]));
$missing = array_diff($called, $defined);
if ($missing) {
    fwrite(STDERR, "INCOMPLETO ($fqn): mancano " . implode(', ', $missing) . "\n");
    exit(1);
}
echo "COMPLETO: ogni \$this->metodo() risolve in {$rc->getShortName()} (" . count($defined) . " metodi)\n";
