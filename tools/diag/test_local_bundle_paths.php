<?php
/**
 * G19.49 — Diagnostic: verifica i path generati da
 * `VerificaController::buildLocalBundleManifest` per teacher_id dato.
 *
 * Usage: php tools/diag/test_local_bundle_paths.php [teacher_id]
 */

declare(strict_types=1);

require __DIR__ . '/../../app/bootstrap.php';

use App\Controllers\VerificaController;

$teacherId = (int)($argv[1] ?? 77);

$ctrl = new VerificaController();
$ref  = new ReflectionClass($ctrl);
$m    = $ref->getMethod('buildLocalBundleManifest');
$m->setAccessible(true);
$manifest = $m->invoke($ctrl, $teacherId);

echo "Teacher ID: $teacherId\n";
echo "Total entries: " . count($manifest) . "\n";
echo str_repeat('=', 80) . "\n";

$byType = [];
foreach ($manifest as $e) {
    $byType[$e['type']] = ($byType[$e['type']] ?? 0) + 1;
}
foreach ($byType as $t => $n) echo "  $t: $n\n";

echo str_repeat('=', 80) . "\n";
echo "Sample paths (first 5 of each type):\n";

$shown = ['verifica-tex' => 0, 'verifica-pdf' => 0, 'mappa' => 0];
foreach ($manifest as $e) {
    $t = $e['type'];
    if (($shown[$t] ?? 5) >= 5) continue;
    $shown[$t] = ($shown[$t] ?? 0) + 1;
    printf("  [%-12s] %s\n", $t, $e['path']);
}

echo str_repeat('=', 80) . "\n";
echo "Path-shape validation:\n";

// Atteso: {ist}/{ind}/{cls}/{materia}/verifiche/{title}/{version_folder}/{file}
// Atteso: {ist}/{ind}/{cls}/{subj}/mappe/{file}
$verSegPattern = '#^[^/]+/[^/]+/[^/]+/[^/]+/verifiche/[^/]+(/[^/]+)?/[^/]+$#';
$mapSegPattern = '#^[^/]+/[^/]+/[^/]+/[^/]+/mappe/[^/]+$#';

$badVer = $badMap = 0;
foreach ($manifest as $e) {
    if (in_array($e['type'], ['verifica-tex', 'verifica-pdf'], true)) {
        if (!preg_match($verSegPattern, $e['path'])) {
            if ($badVer < 3) printf("  ❌ BAD verifica path: %s\n", $e['path']);
            $badVer++;
        }
    } elseif ($e['type'] === 'mappa') {
        if (!preg_match($mapSegPattern, $e['path'])) {
            if ($badMap < 3) printf("  ❌ BAD mappa path: %s\n", $e['path']);
            $badMap++;
        }
    }
}
echo $badVer > 0 ? "  ❌ $badVer verifica paths con shape errato\n"
                 : "  ✓ Tutte le verifiche con shape corretto (7 segments)\n";
echo $badMap > 0 ? "  ❌ $badMap mappe paths con shape errato\n"
                 : "  ✓ Tutte le mappe con shape corretto (6 segments)\n";
